"""Comparison rules and property checks (all run in the trusted runner)."""

import math
import re as stdre

import regex

from mplcodec import Opaque, show

NUMBER_RE = stdre.compile(r"[-+]?(?:\d+(?:[.,]\d*)?|[.,]\d+)(?:[eE][-+]?\d+)?")
REGEX_TIMEOUT = 0.5
DEFAULT_TOL = 1e-6


class Outcome:
    def __init__(self, ok, message=""):
        self.ok = bool(ok)
        self.message = message


# --------------------------------------------------------------------------
# helpers
# --------------------------------------------------------------------------

def is_number(v):
    return isinstance(v, (int, float)) and not isinstance(v, bool)


def close(a, b, tol):
    if isinstance(a, float) and isinstance(b, float) and math.isnan(a) and math.isnan(b):
        return True
    if tol <= 0:
        return a == b
    return math.isclose(float(a), float(b), rel_tol=tol, abs_tol=tol)


def values_equal(a, b, tol=0.0, ignore_case=False, unordered=False):
    """Deep comparison with optional float tolerance."""
    if is_number(a) and is_number(b):
        if tol <= 0 and (isinstance(a, float) or isinstance(b, float)):
            return close(a, b, 1e-9)
        return close(a, b, tol)
    if isinstance(a, str) and isinstance(b, str):
        return a.casefold() == b.casefold() if ignore_case else a == b
    if isinstance(a, (list, tuple)) and isinstance(b, (list, tuple)):
        if type(a) is not type(b) and not unordered:
            # a tuple is not a list – but be lenient when only the container differs
            pass
        if len(a) != len(b):
            return False
        if unordered:
            return multiset_equal(list(a), list(b), tol, ignore_case)
        return all(values_equal(x, y, tol, ignore_case) for x, y in zip(a, b))
    if isinstance(a, frozenset) and isinstance(b, frozenset):
        return multiset_equal(list(a), list(b), tol, ignore_case)
    if isinstance(a, dict) and isinstance(b, dict):
        if set(a.keys()) != set(b.keys()):
            return False
        return all(values_equal(a[k], b[k], tol, ignore_case, unordered) for k in a)
    if isinstance(a, Opaque) or isinstance(b, Opaque):
        return repr(a) == repr(b)
    return a == b


def multiset_equal(a, b, tol, ignore_case):
    remaining = list(b)
    for x in a:
        for i, y in enumerate(remaining):
            if values_equal(x, y, tol, ignore_case):
                del remaining[i]
                break
        else:
            return False
    return not remaining


def extract_numbers(text):
    out = []
    for m in NUMBER_RE.findall(text or ""):
        token = m.replace(",", ".")
        try:
            out.append(float(token))
        except ValueError:
            pass
    return out


def norm_lines(text):
    lines = [line.rstrip() for line in (text or "").replace("\r\n", "\n").split("\n")]
    while lines and lines[-1] == "":
        lines.pop()
    while lines and lines[0] == "":
        lines.pop(0)
    return lines


def _fold(text, ignore_case):
    return text.casefold() if ignore_case else text


def search(pattern, text, ignore_case=False):
    flags = regex.MULTILINE | (regex.IGNORECASE if ignore_case else 0)
    try:
        return regex.search(pattern, text or "", flags=flags, timeout=REGEX_TIMEOUT) is not None
    except TimeoutError:
        return False
    except regex.error as exc:
        raise ValueError("Ungültiger regulärer Ausdruck: %s" % exc)


# --------------------------------------------------------------------------
# compare
# --------------------------------------------------------------------------

def compare_text(actual, expected, spec):
    mode = spec.get("mode", "lines")
    ic = bool(spec.get("ignore_case"))
    tol = float(spec.get("tolerance") or 0) or DEFAULT_TOL
    actual = actual if isinstance(actual, str) else ("" if actual is None else str(actual))
    expected = expected if isinstance(expected, str) else ("" if expected is None else str(expected))
    if mode == "exact":
        return Outcome(_fold(actual.rstrip("\n"), ic) == _fold(expected.rstrip("\n"), ic))
    if mode == "lines":
        return Outcome([_fold(x, ic) for x in norm_lines(actual)] == [_fold(x, ic) for x in norm_lines(expected)])
    if mode == "tokens":
        return Outcome(_fold(actual, ic).split() == _fold(expected, ic).split())
    if mode == "unordered":
        return Outcome(sorted(_fold(actual, ic).split()) == sorted(_fold(expected, ic).split()))
    if mode == "unordered_lines":
        a = sorted(_fold(x, ic) for x in norm_lines(actual))
        b = sorted(_fold(x, ic) for x in norm_lines(expected))
        return Outcome(a == b)
    if mode in ("numbers", "numbers_unordered"):
        a, b = extract_numbers(actual), extract_numbers(expected)
        if len(a) != len(b):
            return Outcome(False, "Anzahl Zahlen: erwartet %d, bekommen %d" % (len(b), len(a)))
        if mode == "numbers_unordered":
            a, b = sorted(a), sorted(b)
        return Outcome(all(close(x, y, tol) for x, y in zip(a, b)))
    if mode == "contains":
        norm = lambda t: " ".join(_fold(t, ic).split())  # noqa: E731
        return Outcome(norm(expected) in norm(actual))
    if mode == "regex":
        return Outcome(search(expected, actual, ic))
    raise ValueError("Unbekannter Vergleichsmodus '%s'" % mode)


def compare_value(actual, expected, spec):
    mode = spec.get("mode", "exact")
    ic = bool(spec.get("ignore_case"))
    tol = float(spec.get("tolerance") or 0)
    if mode == "numeric":
        return Outcome(values_equal(actual, expected, tol or DEFAULT_TOL, ic))
    if mode == "unordered":
        if isinstance(actual, (list, tuple, frozenset)) and isinstance(expected, (list, tuple, frozenset)):
            return Outcome(len(actual) == len(expected)
                           and multiset_equal(list(actual), list(expected), tol, ic))
        return Outcome(values_equal(actual, expected, tol, ic))
    if mode == "string":
        a, b = str(actual), str(expected)
        return Outcome(_fold(" ".join(a.split()), ic) == _fold(" ".join(b.split()), ic))
    if mode == "regex":
        return Outcome(search(str(expected), str(actual), ic))
    if mode in ("exact", "lines", "tokens", "numbers", "numbers_unordered", "contains", "unordered_lines"):
        if isinstance(actual, str) and isinstance(expected, str) and mode != "exact":
            return compare_text(actual, expected, spec)
        ok = values_equal(actual, expected, tol, ic)
        if not ok and type(actual) is not type(expected) and values_equal(
                _listify(actual), _listify(expected), tol, ic):
            return Outcome(False, "Richtige Werte, aber falscher Typ: erwartet %s, bekommen %s"
                           % (type_name(expected), type_name(actual)))
        return Outcome(ok)
    raise ValueError("Unbekannter Vergleichsmodus '%s'" % mode)


def _listify(v):
    if isinstance(v, (tuple, frozenset)):
        return list(v) if isinstance(v, tuple) else sorted(v, key=repr)
    return v


def type_name(v):
    if isinstance(v, frozenset):
        return "set"
    if isinstance(v, Opaque):
        return v.typename or "object"
    return type(v).__name__


# --------------------------------------------------------------------------
# properties
# --------------------------------------------------------------------------

TYPE_NAMES = {
    "int": lambda v: isinstance(v, int) and not isinstance(v, bool),
    "float": lambda v: isinstance(v, float),
    "number": is_number,
    "str": lambda v: isinstance(v, str),
    "bool": lambda v: isinstance(v, bool),
    "list": lambda v: isinstance(v, list),
    "tuple": lambda v: isinstance(v, tuple),
    "dict": lambda v: isinstance(v, dict),
    "set": lambda v: isinstance(v, frozenset),
    "none": lambda v: v is None,
}


def _sequence_for(value, text_mode):
    """List the properties work on. Text output: numbers in it (if any) else lines."""
    if text_mode:
        nums = extract_numbers(value)
        return nums if nums else norm_lines(value)
    if isinstance(value, (list, tuple)):
        return list(value)
    if isinstance(value, frozenset):
        return sorted(value, key=repr)
    if isinstance(value, dict):
        return list(value.keys())
    if isinstance(value, str):
        return list(value)
    return [value]


def check_property(prop, value, text_mode, literal):
    ptype = prop.get("type")
    seq = None

    def sequence():
        nonlocal seq
        if seq is None:
            seq = _sequence_for(value, text_mode)
        return seq

    try:
        if ptype == "nonempty":
            if text_mode:
                return Outcome(bool((value or "").strip()), "Keine Ausgabe")
            return Outcome(value not in (None, "", [], (), {}, frozenset()), "Ergebnis ist leer")
        if ptype == "type":
            want = str(prop.get("value", "")).strip().lower()
            check = TYPE_NAMES.get(want)
            if check is None:
                raise ValueError("Unbekannter Typ '%s'" % want)
            return Outcome(check(value), "Erwarteter Typ %s, bekommen %s" % (want, type_name(value)))
        if ptype in ("sorted", "sorted_desc"):
            s = sequence()
            try:
                ok = all(s[i] <= s[i + 1] for i in range(len(s) - 1)) if ptype == "sorted" else \
                    all(s[i] >= s[i + 1] for i in range(len(s) - 1))
            except TypeError:
                ok = False
            return Outcome(ok, "Nicht sortiert")
        if ptype == "unique":
            s = sequence()
            reprs = [repr(x) for x in s]
            return Outcome(len(set(reprs)) == len(reprs), "Enthält doppelte Werte")
        if ptype == "length":
            s = sequence()
            lo = prop.get("min")
            hi = prop.get("max")
            n = len(s)
            ok = (lo in (None, "") or n >= float(lo)) and (hi in (None, "") or n <= float(hi))
            return Outcome(ok, "Länge %d außerhalb von [%s, %s]" % (n, lo or "", hi or ""))
        if ptype == "range":
            s = sequence() if not is_number(value) else [value]
            lo = prop.get("min")
            hi = prop.get("max")
            bad = [x for x in s if not is_number(x)
                   or (lo not in (None, "") and x < float(lo))
                   or (hi not in (None, "") and x > float(hi))]
            return Outcome(not bad, "Wert %s außerhalb von [%s, %s]" % (
                show(bad[0], 60) if bad else "", lo or "", hi or ""))
        if ptype == "contains":
            raw = prop.get("value", "")
            if text_mode or isinstance(value, str):
                return Outcome(str(raw) in (value or ""), "'%s' fehlt" % raw)
            needle = literal(raw)
            container = value if isinstance(value, (list, tuple, frozenset, dict)) else [value]
            return Outcome(any(values_equal(x, needle) for x in container), "%s fehlt" % show(needle, 80))
        if ptype == "regex":
            text = value if isinstance(value, str) else repr(value)
            return Outcome(search(str(prop.get("value", "")), text, bool(prop.get("ignore_case"))),
                           "Muster nicht gefunden")
        if ptype == "not_contains":
            raw = str(prop.get("value", ""))
            text = value if isinstance(value, str) else repr(value)
            return Outcome(raw not in text, "'%s' darf nicht vorkommen" % raw)
    except (TypeError, ValueError) as exc:
        return Outcome(False, str(exc))
    raise ValueError("Unbekannte Eigenschaft '%s'" % ptype)


PROPERTY_LABELS = {
    "nonempty": "nicht leer",
    "type": "Typ",
    "sorted": "aufsteigend sortiert",
    "sorted_desc": "absteigend sortiert",
    "unique": "keine Duplikate",
    "length": "Länge",
    "range": "Wertebereich",
    "contains": "enthält",
    "not_contains": "enthält nicht",
    "regex": "passt zu Muster",
}
