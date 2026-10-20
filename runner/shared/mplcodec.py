"""Typed JSON codec shared by the runner and the sandbox harness.

Python values (tuples, sets, dicts with non-string keys, special floats, ...)
are encoded into plain JSON so they can cross the sandbox boundary. Decoding
only ever builds primitive containers, never arbitrary objects, so decoding
data coming out of the sandbox is safe.
"""

import math

MAX_DEPTH = 40
MAX_ITEMS = 10000


def encode(value, _depth=0):
    if _depth > MAX_DEPTH:
        return {"$t": "repr", "v": "<zu tief verschachtelt>"}
    if value is None or isinstance(value, (bool, str)):
        return value
    if isinstance(value, int):
        if abs(value) > 2 ** 53:
            return {"$t": "bigint", "v": str(value)}
        return value
    if isinstance(value, float):
        if math.isnan(value):
            return {"$t": "float", "v": "nan"}
        if math.isinf(value):
            return {"$t": "float", "v": "inf" if value > 0 else "-inf"}
        return value
    if isinstance(value, list):
        return [encode(v, _depth + 1) for v in value[:MAX_ITEMS]]
    if isinstance(value, tuple):
        return {"$t": "tuple", "v": [encode(v, _depth + 1) for v in value[:MAX_ITEMS]]}
    if isinstance(value, (set, frozenset)):
        items = [encode(v, _depth + 1) for v in list(value)[:MAX_ITEMS]]
        return {"$t": "set", "v": items}
    if isinstance(value, dict):
        items = [[encode(k, _depth + 1), encode(v, _depth + 1)]
                 for k, v in list(value.items())[:MAX_ITEMS]]
        return {"$t": "dict", "v": items}
    # numpy scalars / arrays without importing numpy here.
    tolist = getattr(value, "tolist", None)
    if callable(tolist) and type(value).__module__.startswith("numpy"):
        try:
            return encode(tolist(), _depth + 1)
        except Exception:
            pass
    try:
        text = repr(value)
    except Exception:
        text = "<%s>" % type(value).__name__
    return {"$t": "repr", "v": text[:2000], "type": type(value).__name__}


class Opaque:
    """Stand-in for a value that could only be transported as its repr."""

    def __init__(self, text, typename=""):
        self.text = text
        self.typename = typename

    def __eq__(self, other):
        return isinstance(other, Opaque) and other.text == self.text

    def __hash__(self):
        return hash(self.text)

    def __repr__(self):
        return self.text


def _hashable(v):
    try:
        hash(v)
        return v
    except TypeError:
        return Opaque(repr(v))


def decode(data, _depth=0):
    if _depth > MAX_DEPTH + 2:
        return None
    if data is None or isinstance(data, (bool, int, float, str)):
        return data
    if isinstance(data, list):
        return [decode(v, _depth + 1) for v in data[:MAX_ITEMS]]
    if isinstance(data, dict):
        t = data.get("$t")
        v = data.get("v")
        if t == "tuple" and isinstance(v, list):
            return tuple(decode(x, _depth + 1) for x in v)
        if t == "set" and isinstance(v, list):
            return frozenset(_hashable(decode(x, _depth + 1)) for x in v)
        if t == "dict" and isinstance(v, list):
            out = {}
            for pair in v:
                if isinstance(pair, list) and len(pair) == 2:
                    out[_hashable(decode(pair[0], _depth + 1))] = decode(pair[1], _depth + 1)
            return out
        if t == "float":
            return float(v) if v in ("nan", "inf", "-inf") else None
        if t == "bigint":
            try:
                return int(str(v)[:5000])
            except ValueError:
                return None
        if t == "repr":
            return Opaque(str(v)[:2000], str(data.get("type", ""))[:100])
    return None


def show(value, limit=2000):
    """Human readable representation for feedback."""
    if isinstance(value, frozenset):
        text = "{" + ", ".join(sorted(repr(v) for v in value)) + "}" if value else "set()"
    else:
        text = repr(value)
    if len(text) > limit:
        text = text[:limit] + " …"
    return text
