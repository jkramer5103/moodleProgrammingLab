"""Concrete test inputs from the teacher's value specifications."""

import ast
import random
import string

CHARSETS = {
    "lower": string.ascii_lowercase,
    "upper": string.ascii_uppercase,
    "letters": string.ascii_letters,
    "digits": string.digits,
    "alnum": string.ascii_letters + string.digits,
    "text": string.ascii_lowercase + "      ",
}

MAX_LIST = 1000
MAX_STR = 10000


class SpecError(ValueError):
    pass


def parse_literal(text):
    """Python literal typed by a teacher, e.g. ``"abc"``, ``[1, 2]`` or ``3.5``."""
    if not isinstance(text, str):
        return text
    stripped = text.strip()
    if stripped == "":
        return ""
    try:
        return ast.literal_eval(stripped)
    except (ValueError, SyntaxError, MemoryError, RecursionError):
        # Lenient: unquoted words are treated as strings.
        return text


def _num(spec, key, default):
    value = spec.get(key, default)
    if value in (None, ""):
        return default
    try:
        return float(value)
    except (TypeError, ValueError):
        raise SpecError("'%s' ist keine Zahl" % key)


def _int(spec, key, default):
    return int(round(_num(spec, key, default)))


def generate(spec, rng, literal=True):
    """Return a Python value for ``spec``.

    ``literal`` controls how fixed values are interpreted: as Python literals
    (function/class arguments) or as raw text (stdin lines).
    """
    if not isinstance(spec, dict):
        raise SpecError("Ungültige Wertangabe")
    kind = spec.get("kind", "fixed")
    if kind == "fixed":
        value = spec.get("value", "")
        return parse_literal(value) if literal else str(value)
    if kind == "int":
        lo, hi = _int(spec, "min", 0), _int(spec, "max", 100)
        if lo > hi:
            lo, hi = hi, lo
        return rng.randint(lo, hi)
    if kind == "float":
        lo, hi = _num(spec, "min", 0.0), _num(spec, "max", 1.0)
        if lo > hi:
            lo, hi = hi, lo
        decimals = max(0, min(10, _int(spec, "decimals", 2)))
        return round(rng.uniform(lo, hi), decimals)
    if kind == "bool":
        return rng.random() < 0.5
    if kind == "str":
        charset = spec.get("charset", "lower")
        chars = spec.get("chars") if charset == "custom" else CHARSETS.get(charset)
        if not chars:
            raise SpecError("Kein Zeichenvorrat angegeben")
        lo = max(0, _int(spec, "min_len", 1))
        hi = min(MAX_STR, max(lo, _int(spec, "max_len", 10)))
        n = rng.randint(lo, hi)
        text = "".join(rng.choice(chars) for _ in range(n))
        if charset == "text":
            text = " ".join(text.split()) or rng.choice(string.ascii_lowercase)
        return text
    if kind == "choice":
        options = spec.get("options") or []
        if isinstance(options, str):
            options = [o.strip() for o in options.split(",")]
        options = [o for o in options if o != ""]
        if not options:
            raise SpecError("Keine Auswahlmöglichkeiten angegeben")
        choice = rng.choice(options)
        return parse_literal(choice) if literal else str(choice)
    if kind == "list":
        lo = max(0, _int(spec, "min_len", 1))
        hi = min(MAX_LIST, max(lo, _int(spec, "max_len", 10)))
        n = rng.randint(lo, hi)
        item = spec.get("item") or {"kind": "int", "min": 0, "max": 100}
        if item.get("kind") == "list":
            raise SpecError("Verschachtelte Listen bitte als festen Wert angeben")
        values = []
        unique = bool(spec.get("unique"))
        attempts = 0
        while len(values) < n and attempts < n * 50 + 100:
            attempts += 1
            v = generate(item, rng, literal=True)
            if unique and v in values:
                continue
            values.append(v)
        if spec.get("sorted"):
            values.sort()
        return values
    raise SpecError("Unbekannte Wertart '%s'" % kind)


def stdin_text(spec, value):
    """Format a generated value as stdin line(s)."""
    if isinstance(value, (list, tuple)):
        fmt = spec.get("format", "spaces")
        items = [str(v) for v in value]
        if fmt == "lines":
            return "\n".join(items) + "\n" if items else ""
        if fmt == "count_lines":
            return "\n".join([str(len(items))] + items) + "\n"
        if fmt == "commas":
            return ",".join(items) + "\n"
        return " ".join(items) + "\n"
    text = str(value)
    return text if text.endswith("\n") else text + "\n"


def is_random(spec):
    if not isinstance(spec, dict):
        return False
    if spec.get("kind", "fixed") != "fixed":
        return True
    return False


def make_rng(seed):
    return random.Random(seed)
