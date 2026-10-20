"""Task authoring assistant for teachers.

A teacher describes a programming task in plain words; the model drafts the
whole activity (task text, starter code, reference solution, tests). Every
draft is checked by actually running it:

* the reference solution must reach full points,
* plausible wrong student solutions (written by the model) must NOT,
* no test may be misconfigured.

Problems are fed back to the model, which repairs the draft (a few rounds).
Runs as a background job because it can take a few minutes.
"""

import asyncio
import json
import logging
import os
import re
import secrets
import time

from . import ai, grader
from .config import settings

log = logging.getLogger("mplrunner.author")

EXAMPLES = json.load(open(os.path.join(os.path.dirname(__file__), "author_examples.json"), encoding="utf-8"))

SCHEMA = r"""
## Format der Tests (JSON)

Ein Test ist ein Objekt. Gemeinsame Felder:
- "id": kurz, eindeutig, nur a-z0-9_ (z. B. "beispiel", "zufall")
- "name": kurzer, für Schüler verständlicher Name
- "type": "io" | "function" | "class" | "file" | "plot" | "code"
- "points": Punkte (Zahl > 0; bei "plot" ignoriert – dort zählen die Kriterien)
- "visibility": "visible" (Eingabe, erwartet, bekommen sichtbar) | "nameonly" (nur bestanden ja/nein) | "hidden" (nur in der Gesamtsumme bei der Abgabe)
- "show_expected": "auto" (Standard: erwartetes Ergebnis nur bei Zufallseingaben zeigen) | "always" | "never"
- "required": true = Pflichttest (nicht bestanden → 0 Punkte für die ganze Aufgabe). Sparsam einsetzen, v. a. für Code-Prüfungen gegen Hartcodieren.
- "repeat": Anzahl Durchläufe, nur sinnvoll bei Zufallseingaben (3–10)
- "timeout": optional, Sekunden

### "io" – Konsolenprogramm (input()/print())
- "inputs": Liste von Werten; jeder Wert wird zu einer Zeile für input(). Feste Werte sind roher Text.
- "expected": {"source": "reference"} (Musterlösung rechnet es aus – fast immer richtig) | {"source": "fixed", "value": "Text"} | {"source": "none"} (nur Bedingungen)
- "compare": {"mode": …, "tolerance": Zahl, "ignore_case": bool}
  Modi für Text: "lines" (zeilenweise, Standard), "exact", "tokens" (Leerzeichen egal), "unordered" (Wörter in beliebiger Reihenfolge),
  "unordered_lines", "numbers" (nur die Zahlen der Ausgabe vergleichen – sehr robust, wenn das Format egal ist; Achtung: Zahlen in input()-Prompts zählen mit),
  "numbers_unordered", "contains" (erwartetes Ergebnis kommt in der Ausgabe vor – gut wenn input("Zahl: ") Prompts ausgibt), "regex" (nur mit festem Wert).
  WICHTIG: input("…")-Prompts landen in der Ausgabe. Wähle den Vergleich so, dass Prompts der Schüler nicht stören ("numbers" oder "contains"), oder verlange im Aufgabentext genau festgelegte Ausgaben.

### "function" – Funktion aufrufen
- "function": Funktionsname
- "inputs": Liste der Argumente. Feste Werte sind PYTHON-LITERALE als String: "5", "'abc'", "[1, 2, 3]", "(1, 2)", "{'a': 1}", "None", "True".
- "expected" wie oben (bei "fixed" ebenfalls ein Python-Literal als String)
- "compare": "exact" (Standard), "numeric" (Kommazahlen mit Toleranz), "unordered" (Listen in beliebiger Reihenfolge), "string", "regex"
Code unter if __name__ == "__main__": wird bei Funktionstests nicht ausgeführt.

### "class" – Klasse testen
- "class": Klassenname; "inputs": Konstruktor-Argumente
- "calls": [{"method": "name", "args": [Werte…]}, …] nacheinander
- "target": {"type": "return"} (Rückgabe des letzten Aufrufs) | {"type": "returns"} (alle Rückgaben als Liste) | {"type": "attr", "name": "attribut"} | {"type": "str"}

### "file" – Datei prüfen
- "filename": Datei, die das Programm schreiben soll; "inputs"/"expected"/"compare" wie bei "io" (verglichen wird der Dateiinhalt)

### "plot" – matplotlib-Diagramm
Vergleicht das Diagramm der Schüler mit dem der Musterlösung (Musterlösung Pflicht).
- "plot": {"tolerance": 0.01, "criteria": {Kriterium: {"enabled": bool, "points": Zahl, "mode": "present"|"equal"}}}
  Kriterien: "figure" (Diagramm erzeugt), "kind" (Typ: Linie/Balken/Streu/Kreis), "count" (Anzahl Achsen/Reihen), "data" (Datenwerte),
  "title", "xlabel", "ylabel", "legend" ("present" = irgendein Text, "equal" = gleicher Text)
- Die Musterlösung muss plt.show() aufrufen oder einfach das Diagramm erzeugen.

### "code" – Quelltext statisch prüfen (ohne Ausführung)
- "rules": [{"type": …, "value": …}] mit
  "uses"/"not_uses" (value: "loop"|"for"|"while"|"if"|"def"|"class"|"comprehension"|"recursion"|"return"|"try"),
  "calls"/"not_calls" (Funktionsname, z. B. "sorted", "sum"), "imports"/"not_imports" (Modul),
  "max_numbers" (höchstens so viele Zahlen-Literale außer 0 und 1 – gegen hart codierte Ergebnisse), "max_lines",
  "contains"/"not_contains" (Regex auf den Quelltext)
- Typisch: Bei Aufgaben OHNE Eingabe (Ausgabe immer gleich) einen Pflicht-Code-Test gegen Hartcodieren anlegen.
  Bei Aufgaben wie „ohne sorted()“ ein "not_calls"-Regel als Pflichttest.

### Werte (für "inputs" und "args")
- {"kind": "fixed", "value": "…"}
- {"kind": "int", "min": 1, "max": 100}
- {"kind": "float", "min": 0, "max": 10, "decimals": 2}
- {"kind": "str", "charset": "lower"|"upper"|"letters"|"digits"|"alnum"|"text"|"custom", "chars": "nur bei custom", "min_len": 1, "max_len": 10}
- {"kind": "choice", "options": ["…", "…"]}  JSON-Array von Strings; bei function/class ist jede Option ein Python-Literal,
  z. B. ["'Anna'", "'Otto'"] oder ["[5, 5, 3]", "[1]", "[]"]; bei io roher Text, z. B. ["17", "-3"]
- {"kind": "list", "min_len": 1, "max_len": 10, "item": {Wert ohne list}, "sorted": bool, "unique": bool, "format": "spaces"|"lines"|"count_lines"|"commas" (nur io: wie die Liste als Eingabe getippt wird)}
- {"kind": "bool"}
Zufallswerte werden bei jedem Prüfen neu gezogen; Schülerlösung und Musterlösung bekommen in jedem Durchlauf DIESELBEN Werte, die
Musterlösung rechnet den Sollwert aus. Das gilt für alle Testarten, auch "plot" (Diagramm) und "class". So kann niemand die
Ergebnisse auswendig hinschreiben. Liest ein Programm z. B. 10 Zahlen ein, nimm 10 Einträge {"kind": "float", …} oder eine
{"kind": "list", "min_len": 10, "max_len": 10, "format": "lines", …}.

### Optionale Bedingungen ("properties", zusätzlich zum Vergleich oder bei "expected": {"source": "none"})
[{"type": "nonempty"}, {"type": "type", "value": "int|float|number|str|bool|list|tuple|dict|set|none"}, {"type": "sorted"}, {"type": "sorted_desc"},
 {"type": "unique"}, {"type": "length", "min": 1, "max": 5}, {"type": "range", "min": 0, "max": 10}, {"type": "contains", "value": "…"},
 {"type": "not_contains", "value": "…"}, {"type": "regex", "value": "…"}]
"""

SYSTEM = """Du bist ein erfahrener Informatik-Fachdidaktiker und Python-Entwickler. Du erstellst Programmieraufgaben für das
Moodle-Plugin „Programming Lab“ für Schulen (Klasse 7–13). Viele Lehrkräfte sind keine Programmier-Profis: Nimm ihnen Arbeit ab,
triff sinnvolle Entscheidungen selbst und erkläre kurz, was du gemacht hast.

Rahmen:
- Python 3.12. Verfügbar: Standardbibliothek, numpy, matplotlib, pandas. KEIN Internet, keine Dateien außer denen, die das Programm selbst schreibt.
- Eingaben nur über input() (Tests liefern sie), Ausgaben über print(), Diagramme über matplotlib.
- Ein Schülerprogramm ist EINE Datei.

Gute Aufgaben (orientiere dich an den Beispielen):
- Aufgabentext ("intro") als einfaches HTML (<p>, <code>, <ul><li>, <strong>, <em>), klar, altersgerecht, in der Sprache der Lehrkraft (meist Deutsch, du-Form).
  Nenne genau, was gefordert ist: Funktionsnamen und Parameter, Ein-/Ausgabeformat, Sonderfälle, 1–3 Beispiele.
- "starter": minimales Gerüst: Funktionskopf mit pass bzw. höchstens die Eingabezeile(n) und „# Dein Code hier“.
  KEINE Schleifen, Bedingungen oder Variablen, die den Lösungsweg vorgeben – außer die Lehrkraft wünscht ausdrücklich ein Gerüst
  (z. B. für absolute Anfänger oder Lückentexte).
- "reference": korrekte, gut lesbare Musterlösung, wie eine Lehrkraft sie schreiben würde (keine Tricks).
- Tests: meist 3–8. Mische feste Beispiele (sichtbar, ggf. show_expected "always", damit Schüler verstehen, was gemeint ist),
  Zufallstests (repeat 4–8) gegen Hartcodieren, Randfälle (0, leer, negativ, Grenzen) und 1 versteckten oder „nameonly“-Test.
  Punkte nach Bedeutung verteilen. Erwartete Werte fast immer aus der Musterlösung ("source": "reference").
- Bei einer Aufgabe ohne Eingabe (Ausgabe immer gleich): Pflicht-Code-Test gegen Hartcodieren.
- Wähle Vergleichsmodi, die faire Lösungen nicht wegen Formatkleinigkeiten durchfallen lassen.
- Code-Prüfungen nur gegen Hartcodieren oder wenn die Lehrkraft etwas verbietet/verlangt („ohne sorted()“, „mit Rekursion“).
  Keine Stilvorschriften, die korrekte Lösungen bestrafen (z. B. break, while True, range oder bestimmte Variablennamen verbieten).
  Verlange keine bestimmte Funktion, wenn es gleichwertige Alternativen gibt: Zufall z. B. per "imports" random prüfen,
  nicht per "calls" randint (randrange/choice sind genauso richtig).
- Fällt eine deiner falschen Lösungen bei der Prüfung NICHT durch, ergänze Testfälle, die den Fehler sichtbar machen
  (z. B. teuerster Artikel zuerst), oder ersetze sie durch eine falsche Lösung, die wirklich falsche Ergebnisse liefert.

Ändern statt neu bauen: Wenn eine aktuelle Aufgabe mitgeschickt wird und die Lehrkraft etwas ändern möchte, behalte alles Übrige
(auch Test-IDs) bei und ändere nur, was gewünscht ist. Ist ein Wunsch schon erfüllt (z. B. „Zufallswerte statt fester Werte“, obwohl
es schon Zufallstests gibt), sag das ehrlich und konkret (welche Tests es schon tun) und ändere nichts Unnötiges. Einen sichtbaren
festen Beispieltest, der zum Beispiel im Aufgabentext passt, behältst du, außer die Lehrkraft will ihn ausdrücklich weg.

Einstellungen der Aktivität: Nur wenn die Lehrkraft etwas dazu sagt (z. B. „Klassenarbeit“, „nur eine Abgabe“, „ohne KI-Hilfe“,
„Schüler sollen nicht ausprobieren können“), setze "settings" mit den betroffenen Feldern:
  "allowrun" (bool, freies Ausführen in der Konsole), "allowcheck" (bool, unbewertetes Prüfen), "checkgraded" (bool, jedes Prüfen zählt als Abgabe),
  "maxattempts" (0 = unbegrenzt), "grademethod" (0 = bester Versuch, 1 = letzter), "timeout" (Sekunden 1–120), "memory" (MB 64–1024),
  "aihints" (bool, KI-Hinweise für Schüler), "aifree" (kostenlose Hinweise), "aicost" (% Abzug je weiterem Hinweis), "aimax" (max. % Abzug).
  Für eine Klassenarbeit z. B.: aihints false, allowcheck true oder false je nach Wunsch, maxattempts 1. Sonst "settings" weglassen.

Antworte AUSSCHLIESSLICH mit einem JSON-Objekt:
{
  "message": "2–5 Sätze an die Lehrkraft in einfacher Sprache: was die Aufgabe jetzt enthält bzw. was du geändert hast, welche Annahmen du getroffen hast, was sie noch anpassen könnte",
  "task": {"name": "…", "intro": "<p>…</p>", "starter": "…", "reference": "…", "tests": [ … ], "settings": {…optional…}},
  "wrong_solutions": [{"description": "ein kurzer, klarer Satz: der typische Schülerfehler", "code": "vollständiger fehlerhafter Code"}, …]
}
"wrong_solutions": 2–3 realistische falsche Schülerlösungen (z. B. Randfall vergessen, falscher Vergleich, hart codiert). Sie dienen nur
zur automatischen Prüfung, dass deine Tests Fehler erkennen.
"message" sieht nur die Lehrkraft: KEINE Fachbegriffe aus diesem Format (JSON, Felder wie required/show_expected/settings, Strings),
nichts über die automatische Prüfung, Korrekturrunden oder deine falschen Lösungen. Sprich von „Tests“, „Pflichttest“,
„versteckter Test“, „Einstellungen“ wie im Formular.
""" + SCHEMA

EXAMPLE_TEXT = "## Beispiele gelungener Aufgaben\n" + "\n".join(
    "```json\n" + json.dumps(e, ensure_ascii=False) + "\n```" for e in EXAMPLES)


class Job:
    def __init__(self):
        self.id = secrets.token_urlsafe(16)
        self.status = "running"
        self.progress = []
        self.result = None
        self.error = None
        self.created = time.monotonic()

    def step(self, text):
        self.progress.append(text)

    def view(self):
        return {"id": self.id, "status": self.status, "progress": self.progress, "result": self.result,
                "error": self.error}


JOBS = {}


def _extract_json(text):
    text = text.strip()
    fence = re.search(r"```(?:json)?\s*(\{.*\})\s*```", text, re.S)
    if fence:
        text = fence.group(1)
    start, end = text.find("{"), text.rfind("}")
    if start < 0 or end < start:
        raise ValueError("Keine JSON-Antwort")
    return json.loads(text[start:end + 1])


def _clean_task(task):
    if not isinstance(task, dict):
        raise ValueError("'task' fehlt")
    out = {
        "name": str(task.get("name") or "Neue Aufgabe")[:200],
        "intro": str(task.get("intro") or ""),
        "starter": str(task.get("starter") or ""),
        "reference": str(task.get("reference") or ""),
        "tests": [],
    }
    seen = set()
    for i, t in enumerate(task.get("tests") or []):
        if not isinstance(t, dict):
            continue
        t = dict(t)
        tid = re.sub(r"[^A-Za-z0-9_-]", "", str(t.get("id") or "")) or "t%d" % (i + 1)
        while tid in seen:
            tid += "_%d" % (i + 1)
        seen.add(tid)
        t["id"] = tid
        t.setdefault("name", tid)
        t.setdefault("visibility", "visible")
        if t.get("type") != "plot":
            try:
                t["points"] = max(0.0, float(t.get("points", 1)))
            except (TypeError, ValueError):
                t["points"] = 1.0
        out["tests"].append(t)
    if not out["tests"]:
        raise ValueError("Die Aufgabe hat keine Tests")
    settings_in = task.get("settings") if isinstance(task.get("settings"), dict) else {}
    limits = {"allowrun": (bool,), "allowcheck": (bool,), "checkgraded": (bool,), "aihints": (bool,),
              "maxattempts": (int, 0, 50), "grademethod": (int, 0, 1), "timeout": (int, 1, 120),
              "memory": (int, 64, 1024), "aifree": (int, 0, 50), "aicost": (float, 0, 100), "aimax": (float, 0, 100)}
    clean = {}
    for key, spec in limits.items():
        if key not in settings_in:
            continue
        value = settings_in[key]
        try:
            if spec[0] is bool:
                clean[key] = bool(value) if not isinstance(value, str) else value.lower() in ("1", "true", "ja", "yes")
            else:
                clean[key] = max(spec[1], min(spec[2], spec[0](value)))
        except (TypeError, ValueError):
            continue
    if clean:
        out["settings"] = clean
    return out


RANDOM_KINDS = {"int", "float", "str", "choice", "list", "bool"}


def _uses_random(node):
    """True if a test draws random values somewhere (inputs, args, calls …)."""
    if isinstance(node, dict):
        if isinstance(node.get("kind"), str) and node["kind"] in RANDOM_KINDS:
            return True
        return any(_uses_random(v) for v in node.values())
    if isinstance(node, list):
        return any(_uses_random(v) for v in node)
    return False


async def _check(task, wrong_solutions, limits):
    """Run reference, starter and wrong solutions against the tests."""
    problems = []
    report = {"reference": None, "max": None, "wrong": [], "starter": None,
              "tests": len(task["tests"]), "random": sum(1 for t in task["tests"] if _uses_random(t))}
    ref = await grader.evaluate({"code": task["reference"], "reference": task["reference"], "tests": task["tests"],
                                 "limits": limits}, POOL[0])
    report["reference"], report["max"] = ref["score"], ref["max_score"]
    for t in ref["tests"]:
        if t.get("config_error"):
            problems.append("Test „%s“ ist fehlerhaft konfiguriert: %s" % (t["name"], t["config_error"]))
        elif t["status"] != "passed":
            detail = ""
            for inst in t.get("instances") or []:
                if not inst.get("passed") and t.get("type") == "code":
                    failed = [c["label"] for c in inst.get("criteria") or [] if not c.get("passed")]
                    err = inst.get("error") or {}
                    detail = "nicht erfüllte Regeln: %s %s" % ("; ".join(failed), err.get("message", "") if err else "")
                    break
                if not inst.get("passed"):
                    err = inst.get("error") or {}
                    detail = "Eingabe: %s | erwartet: %s | bekommen: %s | %s %s" % (
                        inst.get("inputs_display"), inst.get("expected_display"), inst.get("actual_display"),
                        "; ".join(m for m in inst.get("messages") or [] if m),
                        (err.get("type", "") + ": " + err.get("message", "")) if err else "")
                    break
            problems.append("Die Musterlösung besteht Test „%s“ nicht: %s" % (t["name"], detail[:600]))
    if ref["max_score"] <= 0:
        problems.append("Die Tests vergeben keine Punkte")
    takes_input = "input(" in task["reference"] or any(
        t.get("type") in ("function", "class") or t.get("inputs") or t.get("args") for t in task["tests"])
    if takes_input and not report["random"]:
        problems.append("Kein Test verwendet Zufallswerte – so lassen sich die Ergebnisse hart codieren. Ergänze mindestens "
                        "einen sichtbaren Zufallstest (repeat 3–8) mit zufälligen Eingaben/Argumenten; das geht bei jeder "
                        "Testart, auch bei Diagrammen. Feste Beispieltests dürfen bleiben.")
    if task["starter"].strip():
        st = await grader.evaluate({"code": task["starter"], "reference": task["reference"], "tests": task["tests"],
                                    "limits": limits}, POOL[0])
        report["starter"] = st["score"]
        if st["max_score"] and st["score"] >= st["max_score"]:
            problems.append("Schon der Startcode bekommt die volle Punktzahl – er verrät zu viel oder die Tests sind zu schwach")
    for w in (wrong_solutions or [])[:4]:
        code = str((w or {}).get("code") or "")
        if not code.strip():
            continue
        r = await grader.evaluate({"code": code, "reference": task["reference"], "tests": task["tests"],
                                   "limits": limits}, POOL[0])
        report["wrong"].append({"description": str(w.get("description") or "")[:300], "score": r["score"],
                                "max": r["max_score"]})
        if r["max_score"] and r["score"] >= r["max_score"]:
            problems.append("Die falsche Lösung „%s“ bekommt trotzdem die volle Punktzahl – die Tests erkennen diesen "
                            "Fehler nicht. Ergänze einen passenden Test. Liegt der Fehler nicht im Ergebnis, sondern im Code "
                            "(verbotene Funktion wie sorted/sum/max, hart codierte Werte, fehlende Schleife/Rekursion), "
                            "brauchst du einen Test vom Typ \"code\" mit passender Regel (z. B. {\"type\": \"not_calls\", "
                            "\"value\": \"sorted\"}) und \"required\": true." % w.get("description", ""))
    return problems, report


POOL = [None]


def _build_messages(req):
    lang = req.get("lang", "de")
    messages = [{"role": "system", "content": SYSTEM + "\n\n" + EXAMPLE_TEXT}]
    state = req.get("state") or {}
    has_state = bool((state.get("reference") or "").strip() or state.get("tests"))
    for i, m in enumerate(req.get("messages") or []):
        role = "user" if m.get("role") == "teacher" else "assistant"
        content = str(m.get("content") or "")[:8000]
        if role == "user" and i == len(req["messages"]) - 1:
            prefix = ""
            if has_state:
                prefix = ("## Aktueller Stand der Aufgabe (von der Lehrkraft evtl. von Hand geändert)\n```json\n" +
                          json.dumps({k: state.get(k) for k in ("name", "intro", "starter", "reference", "tests", "settings")},
                                     ensure_ascii=False)[:30000] + "\n```\n\n## Wunsch der Lehrkraft\n")
            content = prefix + content
            if lang != "de":
                content += "\n\n(Write the task in the teacher's language.)"
        messages.append({"role": role, "content": content})
    return messages


async def _run(job, req):
    limits = {"timeout": 10, "memory": 512}
    messages = _build_messages(req)
    usage = []
    try:
        draft = None
        report = None
        problems = []
        for attempt in range(settings.ai_author_repairs + 1):
            job.step("Die KI entwirft die Aufgabe …" if attempt == 0 else
                     "Die KI bessert nach (Runde %d) …" % attempt)
            text, meta = await asyncio.to_thread(
                ai.chat, messages, settings.ai_author_model, settings.ai_author_max_tokens,
                settings.ai_author_reasoning, True, settings.ai_author_timeout, 0.3)
            usage.append(meta)
            try:
                data = _extract_json(text)
                task = _clean_task(data.get("task"))
            except (ValueError, json.JSONDecodeError) as exc:
                problems = ["Antwort war kein gültiges JSON im verlangten Format (%s)" % exc]
                messages += [{"role": "assistant", "content": text[:20000]},
                             {"role": "user", "content": "Fehler: " + problems[0] + ". Antworte nur mit dem JSON-Objekt."}]
                continue
            # Repair rounds talk to the checker, not the teacher: keep the first draft's message.
            message = (draft or {}).get("message") or str(data.get("message") or "")
            draft = {"message": message, "task": task,
                     "wrong_solutions": data.get("wrong_solutions") or []}
            job.step("Musterlösung und Tests werden ausgeführt …")
            problems, report = await _check(task, draft["wrong_solutions"], limits)
            if not problems:
                break
            job.step("Gefundene Probleme: " + "; ".join(problems)[:300])
            messages += [{"role": "assistant", "content": json.dumps(data, ensure_ascii=False)[:30000]},
                         {"role": "user", "content": "Die automatische Prüfung hat Probleme gefunden:\n- " +
                          "\n- ".join(problems) + "\nKorrigiere die Aufgabe (Musterlösung, Tests und/oder falsche Lösungen) und antworte "
                          "wieder nur mit dem vollständigen JSON-Objekt. Die Lehrkraft sieht diese Prüfung nicht: "
                          "\"message\" beschreibt weiterhin die fertige Aufgabe bzw. ihre gewünschte Änderung, "
                          "nicht diese Korrektur."}]
        if draft is None:
            raise ai.AIError("Die KI hat keine verwertbare Aufgabe geliefert. Bitte formuliere den Wunsch anders.")
        draft.pop("wrong_solutions", None)
        draft["validation"] = report
        draft["problems"] = problems
        draft["usage"] = usage
        job.result = draft
        job.status = "done"
    except ai.AIError as exc:
        job.error = str(exc)
        job.status = "error"
    except Exception as exc:  # noqa: BLE001
        log.exception("author job failed")
        job.error = "Interner Fehler: %s" % exc
        job.status = "error"


def start(req, pool):
    if not ai.enabled():
        raise ai.AIError("KI ist auf dem Ausführungsserver nicht eingerichtet (MPL_AI_API_KEY fehlt)")
    POOL[0] = pool
    now = time.monotonic()
    for jid, j in list(JOBS.items()):
        if now - j.created > 3600:
            del JOBS[jid]
    job = Job()
    JOBS[job.id] = job
    asyncio.get_running_loop().create_task(_run(job, req))
    return job.view()


def get(job_id):
    job = JOBS.get(job_id)
    return job.view() if job else None
