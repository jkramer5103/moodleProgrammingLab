"""Static checks on the submitted source code ("Code-Prüfung").

The code is only parsed (``ast``), never executed, so this runs in the
trusted runner. Typical use: forbid hard-coded answers ("at most 10 numbers
in the code") or require a construct ("uses a loop").
"""

import ast

from . import compare

MAX_SOURCE = 256 * 1024

CONSTRUCTS = {
    "for": "eine for-Schleife",
    "while": "eine while-Schleife",
    "loop": "eine Schleife (for, while oder Comprehension)",
    "if": "eine if-Abfrage",
    "def": "eine eigene Funktion (def)",
    "class": "eine Klasse",
    "comprehension": "eine Comprehension ([… for … in …])",
    "recursion": "eine rekursive Funktion",
    "return": "return",
    "try": "try/except",
}


class Analysis:
    def __init__(self, tree):
        self.tree = tree
        self.nodes = list(ast.walk(tree))
        self.imports = set()
        self.calls = set()
        for node in self.nodes:
            if isinstance(node, ast.Import):
                self.imports.update(a.name.split(".")[0] for a in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.imports.add(node.module.split(".")[0])
            elif isinstance(node, ast.Call):
                name = _call_name(node.func)
                if name:
                    self.calls.add(name)

    def has(self, construct):
        types = {
            "for": (ast.For, ast.AsyncFor),
            "while": (ast.While,),
            "if": (ast.If, ast.IfExp),
            "def": (ast.FunctionDef, ast.AsyncFunctionDef, ast.Lambda),
            "class": (ast.ClassDef,),
            "comprehension": (ast.ListComp, ast.SetComp, ast.DictComp, ast.GeneratorExp),
            "return": (ast.Return,),
            "try": (ast.Try,) + ((ast.TryStar,) if hasattr(ast, "TryStar") else ()),
        }
        if construct == "loop":
            return self.has("for") or self.has("while") or self.has("comprehension")
        if construct == "recursion":
            return self.recursive()
        wanted = types.get(construct)
        if wanted is None:
            raise ValueError("Unbekanntes Sprachelement '%s'" % construct)
        return any(isinstance(n, wanted) for n in self.nodes)

    def recursive(self):
        for node in self.nodes:
            if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef)):
                for inner in ast.walk(node):
                    if isinstance(inner, ast.Call) and _call_name(inner.func) == node.name:
                        return True
        return False

    def numbers(self):
        """Numeric literals except 0 and 1 (which appear in almost every loop)."""
        count = 0
        for node in self.nodes:
            if isinstance(node, ast.Constant) and isinstance(node.value, (int, float, complex)) \
                    and not isinstance(node.value, bool) and node.value not in (0, 1):
                count += 1
        return count


def _call_name(func):
    if isinstance(func, ast.Name):
        return func.id
    if isinstance(func, ast.Attribute):
        return func.attr
    return None


def describe(rule):
    rtype = rule.get("type")
    value = str(rule.get("value", "")).strip()
    if rtype in ("uses", "not_uses"):
        what = CONSTRUCTS.get(value, value)
        return ("verwendet %s" if rtype == "uses" else "verwendet nicht %s") % what
    if rtype in ("calls", "not_calls"):
        return ("ruft %s() auf" if rtype == "calls" else "ruft %s() nicht auf") % value
    if rtype in ("imports", "not_imports"):
        return ("importiert %s" if rtype == "imports" else "importiert %s nicht") % value
    if rtype == "max_numbers":
        return "höchstens %s Zahlen im Code (0 und 1 zählen nicht)" % value
    if rtype == "max_lines":
        return "höchstens %s Codezeilen" % value
    if rtype == "contains":
        return "Code passt zu „%s“" % value
    if rtype == "not_contains":
        return "Code passt nicht zu „%s“" % value
    return str(rtype)


def check_rule(rule, source, analysis):
    rtype = rule.get("type")
    value = str(rule.get("value", "")).strip()
    if rtype in ("uses", "not_uses"):
        ok = analysis.has(value)
        return ok if rtype == "uses" else not ok
    if rtype in ("calls", "not_calls"):
        ok = value in analysis.calls
        return ok if rtype == "calls" else not ok
    if rtype in ("imports", "not_imports"):
        ok = value.split(".")[0] in analysis.imports
        return ok if rtype == "imports" else not ok
    if rtype == "max_numbers":
        return analysis.numbers() <= int(float(value or 0))
    if rtype == "max_lines":
        lines = [line for line in source.splitlines() if line.strip() and not line.strip().startswith("#")]
        return len(lines) <= int(float(value or 0))
    if rtype in ("contains", "not_contains"):
        ok = compare.search(value, source)
        return ok if rtype == "contains" else not ok
    raise ValueError("Unbekannte Code-Regel '%s'" % rtype)


def evaluate(source, rules):
    """Returns (fraction, criteria, error)."""
    source = (source or "")[:MAX_SOURCE]
    try:
        tree = ast.parse(source, filename="main.py")
    except SyntaxError as exc:
        return 0.0, [], {"type": "SyntaxError", "message": "%s (Zeile %s)" % (exc.msg, exc.lineno), "traceback": ""}
    analysis = Analysis(tree)
    criteria = []
    for rule in rules or []:
        ok = check_rule(rule, source, analysis)
        criteria.append({"key": "code", "label": describe(rule), "points": 1.0,
                         "awarded": 1.0 if ok else 0.0, "passed": ok, "message": ""})
    if not criteria:
        return 1.0, criteria, None
    passed = sum(1 for c in criteria if c["passed"])
    return passed / len(criteria), criteria, None
