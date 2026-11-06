"""Turns a test specification into sandbox jobs and grades the observations.

Everything here runs in the trusted runner process. Student code, reference
solution and teacher check scripts each run in their *own* sandbox, so the
student's code never gets to see the reference solution, the expected values
or the grading rules.
"""

import asyncio
import base64
import hashlib
import json
import random
import time
from collections import OrderedDict

import mplcodec
from mplcodec import show

from . import codecheck, compare, generators, plots, turtlecmp
from .config import settings

VALUE_TYPES = ("function", "class")
PROGRAM_TYPES = ("io", "file", "plot", "turtle")
DRAWING_TYPES = ("plot", "turtle")
MAX_FILES_BYTES = 8 * 1024 * 1024
ALL_TYPES = VALUE_TYPES + PROGRAM_TYPES + ("code",)


class ConfigError(ValueError):
    pass


class ReferenceCache:
    """Results of the reference solution are deterministic for a given case.

    Fixed (non-random) tests therefore only need the reference solution to run
    once; this halves the work when a whole class checks at the same time.
    """

    def __init__(self, size):
        self.size = size
        self.data = OrderedDict()

    @staticmethod
    def key(code, case, limits, files_hash=""):
        raw = json.dumps([code, {k: v for k, v in case.items() if k != "id"}, limits, files_hash], sort_keys=True)
        return hashlib.sha256(raw.encode("utf-8")).hexdigest()

    def get(self, key):
        if key in self.data:
            self.data.move_to_end(key)
            return self.data[key]
        return None

    def put(self, key, value):
        if self.size <= 0:
            return
        self.data[key] = value
        self.data.move_to_end(key)
        while len(self.data) > self.size:
            self.data.popitem(last=False)


reference_cache = ReferenceCache(settings.reference_cache)


def _limits(req):
    limits = req.get("limits") or {}
    timeout = float(limits.get("timeout") or settings.default_timeout)
    timeout = max(1.0, min(timeout, settings.max_timeout))
    memory = int(limits.get("memory") or settings.default_memory_mb)
    memory = max(64, min(memory, settings.max_memory_mb))
    return timeout, memory


def _needs_reference(test):
    if test.get("type") == "code":
        return False
    if test.get("type") in DRAWING_TYPES:
        return True
    return (test.get("expected") or {}).get("source", "reference") == "reference"


def _has_random(test):
    specs = list(test.get("inputs") or [])
    for call in test.get("calls") or []:
        specs.extend(call.get("args") or [])
    return any(generators.is_random(s) for s in specs)


def _rng_for(seed, test_id, k):
    return random.Random("%s:%s:%s" % (seed, test_id, k))


def clean_files(files):
    """Data files of the task: [{"name", "data" (base64)}]."""
    out, total = [], 0
    for f in files or []:
        if not isinstance(f, dict) or not str(f.get("name") or "").strip():
            continue
        data = str(f.get("data") or "")
        total += len(data) * 3 // 4
        if total > MAX_FILES_BYTES:
            raise ConfigError("Die Dateien der Aufgabe sind zu groß (zusammen höchstens %d MB)"
                              % (MAX_FILES_BYTES // (1024 * 1024)))
        out.append({"name": str(f["name"]).strip(), "data": data})
    return out


def files_hash(files):
    if not files:
        return ""
    h = hashlib.sha256()
    for f in files:
        h.update(f["name"].encode("utf-8") + b"\0" + f["data"].encode("ascii", "replace") + b"\0")
    return h.hexdigest()


def test_files(test):
    """Files of a single test (text entered in the test builder), replacing task files of the same name."""
    out = []
    for f in test.get("files") or []:
        if not isinstance(f, dict):
            continue
        name = str(f.get("name") or "").strip()
        if not name:
            continue
        if "data" in f:
            data = str(f.get("data") or "")
        else:
            data = base64.b64encode(str(f.get("content") or "").encode("utf-8")).decode("ascii")
        out.append({"name": name, "data": data})
    return out


def build_instances(test, seed):
    ttype = test.get("type")
    if ttype not in ALL_TYPES:
        raise ConfigError("Unbekannter Testtyp '%s'" % ttype)
    repeat = 1
    if _has_random(test):
        repeat = max(1, min(int(test.get("repeat") or 3), settings.max_repeat))
    instances = []
    for k in range(repeat):
        rng = _rng_for(seed, test.get("id"), k)
        case = {"id": "%s#%d" % (test.get("id"), k)}
        inst = {"case": case}
        if ttype in PROGRAM_TYPES:
            parts, values = [], []
            for spec in test.get("inputs") or []:
                v = generators.generate(spec, rng, literal=False)
                values.append(v)
                parts.append(generators.stdin_text(spec, v))
            stdin = "".join(parts)
            case.update(kind="program", stdin=stdin, capture_plots=(ttype == "plot"),
                        capture_turtle=(ttype == "turtle"))
            if ttype == "file":
                filename = (test.get("filename") or "").strip()
                if not filename:
                    raise ConfigError("Kein Dateiname angegeben")
                case["read_file"] = filename
            inst["inputs"] = values
            inst["inputs_display"] = stdin if stdin else "(keine Eingabe)"
        elif ttype == "function":
            name = (test.get("function") or "").strip()
            if not name:
                raise ConfigError("Kein Funktionsname angegeben")
            args = [generators.generate(s, rng, literal=True) for s in test.get("inputs") or []]
            case.update(kind="function", function=name, args=[mplcodec.encode(a) for a in args],
                        capture_plots=False)
            inst["inputs"] = args
            inst["inputs_display"] = "%s(%s)" % (name, ", ".join(show(a, 300) for a in args))
        else:  # class
            cls = (test.get("class") or "").strip()
            if not cls:
                raise ConfigError("Kein Klassenname angegeben")
            init = [generators.generate(s, rng, literal=True) for s in test.get("inputs") or []]
            calls, lines = [], ["obj = %s(%s)" % (cls, ", ".join(show(a, 200) for a in init))]
            all_args = [init]
            for call in test.get("calls") or []:
                method = (call.get("method") or "").strip()
                args = [generators.generate(s, rng, literal=True) for s in call.get("args") or []]
                all_args.append(args)
                calls.append({"method": method, "args": [mplcodec.encode(a) for a in args]})
                lines.append("obj.%s(%s)" % (method, ", ".join(show(a, 200) for a in args)))
            target = test.get("target") or {"type": "return"}
            if target.get("type") == "attr":
                lines.append("obj.%s" % target.get("name", ""))
            elif target.get("type") == "str":
                lines.append("str(obj)")
            case["class"] = cls
            case.update(kind="class", init_args=[mplcodec.encode(a) for a in init], calls=calls,
                        target=target, capture_plots=False)
            inst["inputs"] = all_args
            inst["inputs_display"] = "\n".join(lines)
        if test.get("timeout"):
            case["timeout"] = float(test["timeout"])
        own_files = test_files(test)
        if own_files:
            case["files"] = own_files
        instances.append(inst)
    return instances


def observed(test, result):
    """The value a test looks at, extracted from a harness result."""
    ttype = test.get("type")
    if ttype == "io":
        return result.get("stdout", "")
    if ttype == "file":
        f = result.get("file") or {}
        return f.get("content") if f.get("exists") else None
    if ttype == "plot":
        return result.get("plots") or []
    if ttype == "turtle":
        return result.get("turtle") or {"used": False, "segments": [], "fills": [], "dots": [], "texts": []}
    return mplcodec.decode(result.get("value"))


def display(test, value):
    if test.get("type") in ("io", "file"):
        if value is None:
            return "(Datei fehlt)"
        return value if len(value) < 20000 else value[:20000] + "\n…"
    if test.get("type") == "plot":
        return plots.describe(value)
    if test.get("type") == "turtle":
        return turtlecmp.describe(value)
    return show(value)


def fixed_expected(test):
    raw = (test.get("expected") or {}).get("value", "")
    if test.get("type") in ("io", "file"):
        return str(raw)
    return generators.parse_literal(raw)


def strip_png(figures):
    return [{k: v for k, v in fig.items() if k != "png"} for fig in figures or []]


def error_public(error):
    if not error:
        return None
    return {"type": error.get("type", ""), "message": error.get("message", ""),
            "traceback": error.get("traceback", "")}


async def evaluate(req, pool):
    started = time.monotonic()
    code = req.get("code") or ""
    reference = req.get("reference") or ""
    tests = req.get("tests") or []
    seed = req.get("seed")
    if seed in (None, ""):
        seed = random.SystemRandom().randrange(1, 2 ** 31)
    timeout, memory = _limits(req)
    try:
        files = clean_files(req.get("files"))
    except ConfigError as exc:
        files = []
        files_error = str(exc)
    else:
        files_error = None
    fhash = files_hash(files)

    prepared = []
    for index, test in enumerate(tests):
        test = dict(test)
        test.setdefault("id", "t%d" % (index + 1))
        entry = {"test": test, "instances": [], "config_error": None}
        try:
            if test.get("type") == "code":
                entry["instances"] = []
                prepared.append(entry)
                continue
            if files_error:
                raise ConfigError(files_error)
            entry["instances"] = build_instances(test, seed)
            if _needs_reference(test) and not reference.strip():
                raise ConfigError("Für diesen Test wird eine Musterlösung benötigt")
        except (ConfigError, generators.SpecError) as exc:
            entry["config_error"] = str(exc)
            entry["instances"] = []
        prepared.append(entry)

    student_cases, reference_cases = [], []
    for entry in prepared:
        for inst in entry["instances"]:
            student_cases.append(inst["case"])
            if _needs_reference(entry["test"]):
                reference_cases.append(inst["case"])

    def job_timeout(cases):
        per_case = [float(c.get("timeout") or timeout) for c in cases]
        return min(settings.max_eval_seconds, sum(per_case) + 20)

    limits = {"timeout": timeout}

    async def run(cases, source):
        if not cases:
            return {}
        job = {"mode": "evaluate", "code": source, "cases": cases, "limits": limits}
        if files:
            job["files"] = files
        answer = await pool.run(job, job_timeout(cases), memory_mb=memory)
        return {r.get("id"): r for r in answer.get("results") or []}

    cached, missing = {}, []
    for case in reference_cases:
        hit = reference_cache.get(ReferenceCache.key(reference, case, limits, fhash))
        if hit is not None:
            cached[case["id"]] = dict(hit, id=case["id"])
        else:
            missing.append(case)

    student_res, reference_res = await asyncio.gather(
        run(student_cases, code), run(missing, reference))
    for case in missing:
        res = reference_res.get(case["id"])
        if res and not res.get("error"):
            reference_cache.put(ReferenceCache.key(reference, case, limits, fhash), res)
    reference_res.update(cached)

    # ---- compare --------------------------------------------------------
    pending_checks = []
    out_tests = []
    for entry in prepared:
        test = entry["test"]
        ttype = test.get("type")
        points = float(test.get("points") or 0)
        criteria = (test.get("plot") or {}).get("criteria") or {}
        if ttype == "turtle":
            criteria = (test.get("turtle") or {}).get("criteria") or turtlecmp.default_criteria()
        if ttype in DRAWING_TYPES:
            points = sum(float(c.get("points") or 0) for c in criteria.values()
                         if c and c.get("enabled", True))
        out = {
            "id": test.get("id"), "name": test.get("name") or test.get("id"), "type": ttype,
            "visibility": test.get("visibility", "visible"), "points": points,
            "awarded": 0.0, "status": "failed", "instances": [], "config_error": entry["config_error"],
            "random": _has_random(test), "show_expected": test.get("show_expected") or "auto",
            "required": bool(test.get("required")),
        }
        out_tests.append(out)
        if entry["config_error"]:
            out["status"] = "config_error"
            continue
        if ttype == "code":
            try:
                fraction, crit, error = codecheck.evaluate(code, test.get("rules") or [])
            except ValueError as exc:
                out["config_error"] = str(exc)
                continue
            out["instances"].append({
                "inputs_display": None, "expected_display": None, "actual_display": None,
                "stdout": "", "stderr": "", "error": error, "fraction": fraction,
                "passed": error is None and fraction >= 1.0 - 1e-9, "messages": [], "criteria": crit,
                "plots": None, "duration": 0,
            })
            continue
        expected_mode = (test.get("expected") or {}).get("source", "reference")
        for inst in entry["instances"]:
            cid = inst["case"]["id"]
            res = student_res.get(cid) or {"error": {"type": "Internal", "message": "kein Ergebnis"}}
            item = {
                "inputs_display": inst["inputs_display"],
                "expected_display": None, "actual_display": None,
                "stdout": res.get("stdout", "")[:20000], "stderr": res.get("stderr", "")[:20000],
                "error": error_public(res.get("error")),
                "fraction": 0.0, "passed": False, "messages": [], "criteria": None,
                "plots": None, "duration": res.get("duration"),
            }
            out["instances"].append(item)
            actual = observed(test, res)
            item["actual_display"] = display(test, actual) if not res.get("error") or ttype in ("io",) else None

            expected = None
            have_expected = False
            if _needs_reference(test):
                ref = reference_res.get(cid) or {}
                if ref.get("error") or not ref:
                    msg = ref.get("error", {}).get("message", "kein Ergebnis") if ref else "kein Ergebnis"
                    out["config_error"] = "Die Musterlösung ist bei diesem Test fehlgeschlagen: %s" % msg
                    item["reference_error"] = error_public(ref.get("error")) if ref else None
                    continue
                expected = observed(test, ref)
                have_expected = True
                if ttype == "plot":
                    item["plots"] = {"student": [f.get("png") for f in actual or []],
                                     "reference": [f.get("png") for f in expected or []]}
                elif ttype == "turtle":
                    item["plots"] = {"student": [actual.get("png")] if actual.get("png") else [],
                                     "reference": [expected.get("png")] if expected.get("png") else []}
            elif expected_mode == "fixed":
                expected = fixed_expected(test)
                have_expected = True
            if have_expected:
                item["expected_display"] = display(test, expected)

            if res.get("error") and ttype not in DRAWING_TYPES:
                item["messages"].append(res["error"].get("message", ""))
                continue

            if ttype in DRAWING_TYPES:
                if res.get("error"):
                    item["messages"].append(res["error"].get("message", ""))
                if ttype == "plot":
                    crit = plots.compare_figures(actual, expected, criteria,
                                                 (test.get("plot") or {}).get("tolerance"))
                else:
                    opts = test.get("turtle") or {}
                    crit = turtlecmp.compare(actual, expected, criteria, opts.get("tolerance"),
                                             opts.get("placement") or "exact")
                item["criteria"] = crit
                total = sum(c["points"] for c in crit)
                got = sum(c["awarded"] for c in crit)
                item["fraction"] = (got / total) if total else 0.0
                item["passed"] = bool(total) and got >= total - 1e-9
                continue

            ok = True
            if ttype == "file" and actual is None:
                ok = False
                item["messages"].append("Die Datei '%s' wurde nicht erstellt" % test.get("filename"))
            cmp_spec = test.get("compare") or {}
            if ok and have_expected:
                try:
                    if ttype in ("io", "file"):
                        outcome = compare.compare_text(actual, expected, cmp_spec)
                    else:
                        outcome = compare.compare_value(actual, expected, cmp_spec)
                except ValueError as exc:
                    out["config_error"] = str(exc)
                    continue
                if not outcome.ok:
                    ok = False
                    item["messages"].append("Ausgabe stimmt nicht mit der Erwartung überein")
                    if outcome.message:
                        # May reveal parts of the expected result – hidden together with it.
                        item["hint"] = outcome.message
            if ok:
                text_mode = ttype in ("io", "file")
                for prop in test.get("properties") or []:
                    try:
                        o = compare.check_property(prop, actual, text_mode, generators.parse_literal)
                    except ValueError as exc:
                        out["config_error"] = str(exc)
                        ok = False
                        break
                    if not o.ok:
                        ok = False
                        item["messages"].append("Eigenschaft '%s' verletzt: %s" % (
                            compare.PROPERTY_LABELS.get(prop.get("type"), prop.get("type")), o.message))
            if ok and (test.get("check") or "").strip():
                pending_checks.append((item, test, actual, expected, inst["inputs"]))
                item["fraction"] = 1.0
                continue
            item["fraction"] = 1.0 if ok else 0.0
            item["passed"] = ok

    # ---- teacher check scripts (own sandbox) -----------------------------
    if pending_checks:
        checks = []
        for i, (item, test, actual, expected, inputs) in enumerate(pending_checks):
            value = strip_png(actual) if test.get("type") == "plot" else actual
            checks.append({"id": str(i), "code": test.get("check"), "data": {
                "result": mplcodec.encode(value), "expected": mplcodec.encode(expected),
                "inputs": mplcodec.encode(inputs)}})
        try:
            answer = await pool.run({"mode": "check", "checks": checks, "limits": {"timeout": 10}},
                                    10 * len(checks) + 20)
            by_id = {r.get("id"): r for r in answer.get("results") or []}
        except Exception as exc:  # noqa: BLE001
            by_id = {}
            for item, *_ in pending_checks:
                item["messages"].append("Prüfskript konnte nicht ausgeführt werden: %s" % exc)
        for i, (item, test, *_rest) in enumerate(pending_checks):
            r = by_id.get(str(i)) or {}
            if r.get("error"):
                item["fraction"] = 0.0
                item["passed"] = False
                err = r["error"]
                item["check_error"] = err
                item["messages"].append("Fehler im Prüfskript: %s: %s" % (err.get("type"), err.get("message")))
                continue
            verdict = r.get("value") or {"score": 0.0, "message": ""}
            score = float(verdict.get("score") or 0)
            item["fraction"] = score
            item["passed"] = score >= 1.0 - 1e-9
            if verdict.get("message"):
                item["messages"].append(verdict["message"])

    # ---- totals --------------------------------------------------------
    score = 0.0
    max_score = 0.0
    for out in out_tests:
        max_score += out["points"]
        if out["config_error"]:
            out["status"] = "config_error"
            out["awarded"] = 0.0
            continue
        inst = out["instances"]
        frac = sum(i["fraction"] for i in inst) / len(inst) if inst else 0.0
        out["awarded"] = round(out["points"] * frac, 4)
        score += out["awarded"]
        if inst and all(i["passed"] for i in inst):
            out["status"] = "passed"
        elif any(i.get("error") for i in inst) and frac == 0:
            out["status"] = "error"
        elif frac > 0:
            out["status"] = "partial"
        else:
            out["status"] = "failed"

    # Required tests ("Pflichttest"): if one fails, the whole task scores 0.
    gate = [t["name"] for t in out_tests if t["required"] and not t["config_error"] and t["status"] != "passed"]
    if gate:
        score = 0.0
        for t in out_tests:
            t["awarded"] = 0.0

    return {
        "gate_failed": gate,
        "score": round(score, 4),
        "max_score": round(max_score, 4),
        "seed": seed,
        "duration": round(time.monotonic() - started, 3),
        "tests": out_tests,
    }
