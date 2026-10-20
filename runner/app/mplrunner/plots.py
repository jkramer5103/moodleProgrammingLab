"""Compare matplotlib figure structures (student vs. reference solution)."""

import math

from .compare import is_number

LABELS = {
    "figure": "Diagramm erzeugt",
    "kind": "Diagrammtyp",
    "count": "Anzahl Datenreihen",
    "data": "Datenwerte",
    "title": "Titel",
    "xlabel": "x-Achsenbeschriftung",
    "ylabel": "y-Achsenbeschriftung",
    "legend": "Legende",
}

ORDER = ["figure", "kind", "count", "data", "title", "xlabel", "ylabel", "legend"]

TYPE_NAMES = {"line": "Liniendiagramm", "bar": "Balkendiagramm", "barh": "horizontales Balkendiagramm",
              "scatter": "Streudiagramm", "pie": "Kreisdiagramm"}


def all_axes(figures):
    axes = []
    for fig in figures or []:
        for ax in fig.get("axes") or []:
            entry = dict(ax)
            if not entry.get("title") and fig.get("suptitle"):
                entry["title"] = fig.get("suptitle")
            axes.append(entry)
    return axes


def _norm(text):
    return " ".join(str(text or "").split()).casefold()


def _types(ax):
    return sorted(s.get("type") for s in ax.get("series") or [])


def _close_lists(a, b, tol):
    if len(a) != len(b):
        return False
    for x, y in zip(a, b):
        if is_number(x) and is_number(y):
            if not math.isclose(x, y, rel_tol=tol, abs_tol=tol):
                return False
        elif _norm(x) != _norm(y):
            return False
    return True


def _series_match(ref, stu, tol):
    if ref.get("type") != stu.get("type"):
        return False
    if not _close_lists(ref.get("y") or [], stu.get("y") or [], tol):
        return False
    if ref.get("type") in ("bar", "barh") and ref.get("categories"):
        return [_norm(c) for c in ref["categories"]] == [_norm(c) for c in stu.get("categories") or []]
    if ref.get("type") == "pie":
        return True
    return _close_lists(ref.get("x") or [], stu.get("x") or [], tol)


def describe(figures):
    """Short human description of figures for feedback."""
    parts = []
    for i, ax in enumerate(all_axes(figures)):
        kinds = ", ".join(TYPE_NAMES.get(t, t) for t in _types(ax)) or "leer"
        parts.append("Achse %d: %s; Titel '%s'; x '%s'; y '%s'%s" % (
            i + 1, kinds, ax.get("title") or "", ax.get("xlabel") or "", ax.get("ylabel") or "",
            "; Legende: " + ", ".join(ax["legend"]) if ax.get("legend") else ""))
    return "\n".join(parts) or "kein Diagramm"


def compare_figures(student, reference, criteria, tolerance=0.01):
    tol = float(tolerance or 0.01)
    ref_axes = all_axes(reference)
    stu_axes = all_axes(student)
    results = []
    for key in ORDER:
        crit = (criteria or {}).get(key)
        if not crit or not crit.get("enabled", True):
            continue
        points = float(crit.get("points") or 0)
        mode = crit.get("mode", "equal")
        fraction, message = 0.0, ""
        if not stu_axes:
            message = "Es wurde kein Diagramm erzeugt"
        elif key == "figure":
            fraction = 1.0
        elif key == "kind":
            bad = [i for i, r in enumerate(ref_axes)
                   if i >= len(stu_axes) or set(_types(r)) != set(_types(stu_axes[i]))]
            fraction = 0.0 if bad else 1.0
            if bad:
                i = bad[0]
                want = ", ".join(TYPE_NAMES.get(t, t) for t in sorted(set(_types(ref_axes[i]))))
                got = ", ".join(TYPE_NAMES.get(t, t) for t in sorted(set(_types(stu_axes[i])))) \
                    if i < len(stu_axes) else "nichts"
                message = "Erwartet %s, bekommen %s" % (want, got)
        elif key == "count":
            if len(stu_axes) != len(ref_axes):
                message = "Erwartet %d Teildiagramm(e), bekommen %d" % (len(ref_axes), len(stu_axes))
            else:
                bad = [i for i, r in enumerate(ref_axes) if len(r.get("series") or []) != len(stu_axes[i].get("series") or [])]
                if bad:
                    i = bad[0]
                    message = "Erwartet %d Datenreihe(n), bekommen %d" % (
                        len(ref_axes[i].get("series") or []), len(stu_axes[i].get("series") or []))
                else:
                    fraction = 1.0
        elif key == "data":
            total = matched = 0
            for i, r in enumerate(ref_axes):
                stu_series = list(stu_axes[i].get("series") or []) if i < len(stu_axes) else []
                for rs in r.get("series") or []:
                    total += 1
                    for j, ss in enumerate(stu_series):
                        if _series_match(rs, ss, tol):
                            matched += 1
                            del stu_series[j]
                            break
            fraction = matched / total if total else 1.0
            if fraction < 1:
                message = "%d von %d Datenreihen stimmen" % (matched, total)
        elif key in ("title", "xlabel", "ylabel"):
            bad = []
            for i, r in enumerate(ref_axes):
                got = stu_axes[i].get(key) if i < len(stu_axes) else ""
                if mode == "present":
                    if not _norm(got):
                        bad.append((i, "fehlt"))
                elif _norm(got) != _norm(r.get(key)):
                    bad.append((i, "erwartet '%s', bekommen '%s'" % (r.get(key) or "", got or "")))
            fraction = 0.0 if bad else 1.0
            if bad:
                message = bad[0][1]
        elif key == "legend":
            bad = []
            for i, r in enumerate(ref_axes):
                got = stu_axes[i].get("legend") if i < len(stu_axes) else None
                if r.get("legend") is None and mode != "present":
                    continue
                if got is None:
                    bad.append("Legende fehlt")
                elif mode != "present" and sorted(map(_norm, got)) != sorted(map(_norm, r.get("legend") or [])):
                    bad.append("erwartet %s, bekommen %s" % (", ".join(r.get("legend") or []), ", ".join(got)))
            fraction = 0.0 if bad else 1.0
            if bad:
                message = bad[0]
        results.append({
            "key": key,
            "label": LABELS.get(key, key),
            "points": points,
            "awarded": round(points * fraction, 4),
            "passed": fraction >= 1.0,
            "message": message,
        })
    return results
