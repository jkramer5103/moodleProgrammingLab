"""Compare turtle drawings (student vs. reference solution).

The harness records what the turtle drew (pen strokes, fills, dots, texts).
Strokes are compared geometrically: both drawings are sampled along their
lines and every sample must lie close to a line of the other drawing. The
order, the direction and how a line is split into pieces do not matter, so
``forward(100)`` equals ``forward(50); forward(50)`` and drawing a square
clockwise equals drawing it counter-clockwise.
"""

import math

LABELS = {
    "drawing": "Zeichnung erstellt",
    "lines": "Linien",
    "colors": "Farben",
    "fills": "Gefüllte Flächen",
    "texts": "Texte",
}
ORDER = ["drawing", "lines", "colors", "fills", "texts"]
MAX_SAMPLES = 20000


def default_criteria():
    return {
        "drawing": {"enabled": True, "points": 1},
        "lines": {"enabled": True, "points": 3},
        "colors": {"enabled": True, "points": 1},
        "fills": {"enabled": False, "points": 1},
        "texts": {"enabled": False, "points": 1},
    }


def empty(drawing):
    d = drawing or {}
    return not (d.get("segments") or d.get("dots") or d.get("fills") or d.get("texts"))


def describe(drawing):
    d = drawing or {}
    if not d.get("used") and empty(d):
        return "keine Turtle-Zeichnung"
    segs = d.get("segments") or []
    length = sum(math.hypot(s[2] - s[0], s[3] - s[1]) for s in segs)
    colors = sorted({s[4] for s in segs})
    parts = ["%d Linienstück(e), Gesamtlänge %s" % (len(segs), _fmt(length))]
    if colors:
        parts.append("Farben: " + ", ".join(colors[:8]))
    if d.get("fills"):
        parts.append("%d gefüllte Fläche(n)" % len(d["fills"]))
    if d.get("dots"):
        parts.append("%d Punkt(e)" % len(d["dots"]))
    if d.get("texts"):
        parts.append("Text: " + ", ".join("'%s'" % t[2] for t in d["texts"][:5]))
    return "; ".join(parts)


def _fmt(v):
    return ("%.1f" % v).rstrip("0").rstrip(".")


# ---------------------------------------------------------------------------
# Geometry
# ---------------------------------------------------------------------------

def _bbox(d):
    xs, ys = [], []
    for s in d.get("segments") or []:
        xs += [s[0], s[2]]
        ys += [s[1], s[3]]
    for dot in d.get("dots") or []:
        xs.append(dot[0])
        ys.append(dot[1])
    for f in d.get("fills") or []:
        for p in f.get("points") or []:
            xs.append(p[0])
            ys.append(p[1])
    if not xs:
        return None
    return min(xs), min(ys), max(xs), max(ys)


def normalise(drawing, placement):
    """Shift (and scale) a drawing according to the placement option.

    "exact": coordinates as drawn; "translate": position does not matter
    (lower left corner of the drawing moved to 0,0); "scale": position and
    size do not matter (additionally scaled to a size of 100).
    """
    d = drawing or {}
    if placement not in ("translate", "scale"):
        return d
    box = _bbox(d)
    if box is None:
        return d
    x0, y0, x1, y1 = box
    k = 1.0
    if placement == "scale":
        size = max(x1 - x0, y1 - y0)
        k = 100.0 / size if size > 1e-9 else 1.0

    def p(x, y):
        return (x - x0) * k, (y - y0) * k

    out = dict(d)
    out["segments"] = [list(p(s[0], s[1])) + list(p(s[2], s[3])) + list(s[4:]) for s in d.get("segments") or []]
    out["dots"] = [list(p(t[0], t[1])) + [t[2] * k, t[3]] for t in d.get("dots") or []]
    out["fills"] = [dict(f, points=[list(p(*pt)) for pt in f.get("points") or []]) for f in d.get("fills") or []]
    out["texts"] = [list(p(t[0], t[1])) + list(t[2:]) for t in d.get("texts") or []]
    return out


def _segments(d):
    return [s for s in d.get("segments") or [] if math.hypot(s[2] - s[0], s[3] - s[1]) > 1e-9]


def _samples(segments, spacing):
    """Points along all segments (each with the segment's colour)."""
    total = sum(math.hypot(s[2] - s[0], s[3] - s[1]) for s in segments)
    if total > 0 and total / spacing > MAX_SAMPLES:
        spacing = total / MAX_SAMPLES
    pts = []
    for s in segments:
        length = math.hypot(s[2] - s[0], s[3] - s[1])
        n = max(1, int(math.ceil(length / spacing)))
        for i in range(n + 1):
            t = i / n
            pts.append((s[0] + (s[2] - s[0]) * t, s[1] + (s[3] - s[1]) * t, s[4] if len(s) > 4 else ""))
    return pts


class _Index:
    """Uniform grid over segments for fast "nearest segment" queries."""

    def __init__(self, segments, cell):
        self.cell = cell
        self.grid = {}
        self.segments = segments
        for idx, s in enumerate(segments):
            x0, x1 = sorted((s[0], s[2]))
            y0, y1 = sorted((s[1], s[3]))
            for gx in range(int(math.floor(x0 / cell)), int(math.floor(x1 / cell)) + 1):
                for gy in range(int(math.floor(y0 / cell)), int(math.floor(y1 / cell)) + 1):
                    self.grid.setdefault((gx, gy), []).append(idx)

    def near(self, x, y, tol, color=None):
        gx, gy = int(math.floor(x / self.cell)), int(math.floor(y / self.cell))
        seen = set()
        for dx in (-1, 0, 1):
            for dy in (-1, 0, 1):
                for idx in self.grid.get((gx + dx, gy + dy), ()):
                    if idx in seen:
                        continue
                    seen.add(idx)
                    s = self.segments[idx]
                    if color is not None and (s[4] if len(s) > 4 else "") != color:
                        continue
                    if _dist(x, y, s) <= tol:
                        return True
        return False


def _dist(px, py, s):
    x0, y0, x1, y1 = s[0], s[1], s[2], s[3]
    dx, dy = x1 - x0, y1 - y0
    length2 = dx * dx + dy * dy
    if length2 <= 1e-18:
        return math.hypot(px - x0, py - y0)
    t = max(0.0, min(1.0, ((px - x0) * dx + (py - y0) * dy) / length2))
    return math.hypot(px - (x0 + t * dx), py - (y0 + t * dy))


def _coverage(samples, index, tol, by_color=False):
    if not samples:
        return 1.0
    hit = sum(1 for x, y, c in samples if index.near(x, y, tol, c if by_color else None))
    return hit / len(samples)


def _area(points):
    a = 0.0
    for i in range(len(points)):
        x0, y0 = points[i]
        x1, y1 = points[(i + 1) % len(points)]
        a += x0 * y1 - x1 * y0
    return abs(a) / 2.0


def _centroid(points):
    n = len(points) or 1
    return sum(p[0] for p in points) / n, sum(p[1] for p in points) / n


def _norm_text(t):
    return " ".join(str(t or "").split())


# ---------------------------------------------------------------------------
# Criteria
# ---------------------------------------------------------------------------

def compare(student, reference, criteria=None, tolerance=None, placement="exact"):
    tol = float(tolerance) if tolerance not in (None, "") else 3.0
    tol = max(0.01, tol)
    criteria = criteria or default_criteria()
    stu = normalise(student, placement)
    ref = normalise(reference, placement)
    stu_segs, ref_segs = _segments(stu), _segments(ref)
    spacing = max(tol / 2.0, 0.05)
    cell = max(tol * 2.0, 1.0)
    cache = {}

    def line_scores():
        if "lines" not in cache:
            ref_samples = _samples(ref_segs, spacing)
            stu_samples = _samples(stu_segs, spacing)
            recall = _coverage(ref_samples, _Index(stu_segs, cell), tol)     # reference lines drawn by the student
            precision = _coverage(stu_samples, _Index(ref_segs, cell), tol)  # student lines that belong there
            cache["lines"] = (recall, precision, ref_samples)
        return cache["lines"]

    results = []
    for key in ORDER:
        crit = criteria.get(key)
        if not crit or not crit.get("enabled", True):
            continue
        points = float(crit.get("points") or 0)
        fraction, message = 0.0, ""
        if empty(stu):
            message = "Es wurde nichts gezeichnet"
        elif key == "drawing":
            fraction = 1.0
        elif key == "lines":
            recall, precision, _ = line_scores()
            dots_ok, dots_msg = _dots_match(stu, ref, tol)
            score = min(recall, precision)
            if score >= 0.98 and dots_ok:
                fraction = 1.0
            else:
                fraction = 0.5 if score >= 0.8 and dots_ok else 0.0
                bits = []
                if recall < 0.98:
                    bits.append("%d %% der erwarteten Linien fehlen" % round(100 * (1 - recall)))
                if precision < 0.98:
                    bits.append("%d %% deiner Linien gehören nicht zur erwarteten Zeichnung" %
                                round(100 * (1 - precision)))
                if not dots_ok:
                    bits.append(dots_msg)
                message = "; ".join(bits)
        elif key == "colors":
            # Lines in the right place: every line must have the right colour there. Otherwise (the shape is
            # already wrong) it is enough to use the same colours.
            recall, precision, ref_samples = line_scores()
            want = sorted({s[4] for s in ref_segs} | {d[3] for d in ref.get("dots") or []})
            got = sorted({s[4] for s in stu_segs} | {d[3] for d in stu.get("dots") or []})
            if min(recall, precision) >= 0.98 and _dots_match(stu, ref, tol)[0]:
                ok = _coverage(ref_samples, _Index(stu_segs, cell), tol, by_color=True)
                passed = ok >= 0.95 and _dots_match(stu, ref, tol, by_color=True)[0]
                if not passed:
                    message = "Die Linien sind an der richtigen Stelle, haben aber teilweise eine andere Farbe"
            else:
                passed = want == got
                if not passed:
                    message = "erwartete Farben: %s; deine Farben: %s" % (", ".join(want) or "–", ", ".join(got) or "–")
            fraction = 1.0 if passed else 0.0
        elif key == "fills":
            fraction, message = _fills_match(stu, ref, tol)
        elif key == "texts":
            want = sorted(_norm_text(t[2]) for t in ref.get("texts") or [])
            got = sorted(_norm_text(t[2]) for t in stu.get("texts") or [])
            if want == got:
                fraction = 1.0
            else:
                message = "erwartet: %s; bekommen: %s" % (", ".join("'%s'" % t for t in want) or "kein Text",
                                                          ", ".join("'%s'" % t for t in got) or "kein Text")
        results.append({"key": key, "label": LABELS[key], "points": points, "awarded": round(points * fraction, 4),
                        "passed": fraction >= 1.0, "message": message})
    return results


def _dots_match(stu, ref, tol, by_color=False):
    want = list(ref.get("dots") or [])
    got = list(stu.get("dots") or [])
    if len(want) != len(got):
        return False, "erwartet %d Punkt(e), bekommen %d" % (len(want), len(got))
    remaining = list(got)
    for w in want:
        found = None
        for i, g in enumerate(remaining):
            if math.hypot(w[0] - g[0], w[1] - g[1]) <= tol + 0.25 * max(w[2], g[2]) and \
                    abs(w[2] - g[2]) <= max(tol, 0.25 * w[2]) and (not by_color or w[3] == g[3]):
                found = i
                break
        if found is None:
            return False, "Punkte liegen an anderen Stellen oder sind anders groß"
        del remaining[found]
    return True, ""


def _fills_match(stu, ref, tol):
    want = [f for f in ref.get("fills") or [] if len(f.get("points") or []) >= 3]
    got = [f for f in stu.get("fills") or [] if len(f.get("points") or []) >= 3]
    if not want and not got:
        return 1.0, ""
    remaining = list(got)
    matched = 0
    for w in want:
        wa, wc = _area(w["points"]), _centroid(w["points"])
        for i, g in enumerate(remaining):
            ga, gc = _area(g["points"]), _centroid(g["points"])
            if g.get("color") != w.get("color"):
                continue
            if abs(ga - wa) <= max(0.1 * max(wa, ga), tol * tol) and math.hypot(wc[0] - gc[0], wc[1] - gc[1]) <= 3 * tol:
                matched += 1
                del remaining[i]
                break
    if matched == len(want) and not remaining:
        return 1.0, ""
    return (matched / len(want) if want else 0.0) * (1.0 if not remaining else 0.5), \
        "%d von %d gefüllten Flächen stimmen (Lage, Größe, Farbe)%s" % (
            matched, len(want), "; %d zu viel" % len(remaining) if remaining else "")
