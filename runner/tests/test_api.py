"""Integration tests against a running runner.

    MPL_RUNNER_URL=http://localhost:8000 MPL_RUNNER_SECRET=... python -m pytest runner/tests
or simply
    python runner/tests/test_api.py
"""

import json
import os
import time
import urllib.request

URL = os.environ.get("MPL_RUNNER_URL", "http://localhost:18000")
SECRET = os.environ.get("MPL_RUNNER_SECRET", "testsecret1234567890")


def call(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(URL + path, data=data, method=method,
                                 headers={"Authorization": "Bearer " + SECRET,
                                          "Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=300) as resp:
        return json.loads(resp.read())


def evaluate(code, tests, reference="", **kw):
    return call("POST", "/v1/evaluate", dict(code=code, reference=reference, tests=tests, **kw))


CAESAR_REF = '''
def encrypt(text, shift):
    out = ""
    for ch in text:
        if ch.islower():
            out += chr((ord(ch) - 97 + shift) % 26 + 97)
        else:
            out += ch
    return out
'''

CAESAR_TESTS = [
    {"id": "fixed", "name": "Beispiel", "type": "function", "function": "encrypt", "points": 2,
     "inputs": [{"kind": "fixed", "value": "'abc'"}, {"kind": "fixed", "value": "1"}],
     "expected": {"source": "fixed", "value": "'bcd'"}},
    {"id": "random", "name": "Zufall", "type": "function", "function": "encrypt", "points": 3,
     "repeat": 5, "visibility": "hidden",
     "inputs": [{"kind": "str", "charset": "lower", "min_len": 1, "max_len": 12},
                {"kind": "int", "min": 0, "max": 25}],
     "expected": {"source": "reference"}},
]


def test_caesar_correct():
    r = evaluate(CAESAR_REF, CAESAR_TESTS, CAESAR_REF)
    assert r["score"] == 5, r
    assert r["tests"][1]["status"] == "passed"
    assert len(r["tests"][1]["instances"]) == 5


def test_caesar_hardcoded_fails():
    hard = "def encrypt(text, shift):\n    return 'bcd'\n"
    r = evaluate(hard, CAESAR_TESTS, CAESAR_REF)
    assert r["tests"][0]["status"] == "passed"
    assert r["tests"][1]["status"] in ("failed", "partial")
    assert r["score"] < 5


def test_error_traceback():
    bad = "def encrypt(text, shift):\n    return text + shift\n"
    r = evaluate(bad, CAESAR_TESTS[:1], CAESAR_REF)
    inst = r["tests"][0]["instances"][0]
    assert inst["error"]["type"] == "TypeError"
    assert "main.py" in inst["error"]["traceback"] and "line 2" in inst["error"]["traceback"]


PRIMES_REF = "print([n for n in range(2, 101) if all(n % d for d in range(2, n))])"


def test_primes_numbers_mode():
    tests = [{"id": "p", "name": "Primzahlen", "type": "io", "points": 4,
              "expected": {"source": "reference"}, "compare": {"mode": "numbers"}}]
    as_lines = "for n in range(2, 101):\n    if all(n % d for d in range(2, n)):\n        print(n)\n"
    assert evaluate(as_lines, tests, PRIMES_REF)["score"] == 4
    assert evaluate(PRIMES_REF, tests, PRIMES_REF)["score"] == 4
    assert evaluate("print(2, 3, 5)", tests, PRIMES_REF)["score"] == 0


def test_io_random_and_prompts():
    ref = "a = int(input())\nb = int(input())\nprint(a + b)"
    tests = [{"id": "sum", "name": "Summe", "type": "io", "points": 2, "repeat": 4,
              "inputs": [{"kind": "int", "min": -50, "max": 50}, {"kind": "int", "min": 0, "max": 9}],
              "expected": {"source": "reference"}, "compare": {"mode": "lines"}}]
    with_prompts = "x = int(input('Erste: '))\ny = int(input('Zweite: '))\nprint(x + y)"
    # prompts end up in stdout: "lines" fails, "contains" accepts them
    assert evaluate(with_prompts, tests, ref)["score"] < 2
    tests[0]["compare"] = {"mode": "contains"}
    assert evaluate(with_prompts, tests, ref)["score"] == 2
    assert evaluate("print(int(input()) - int(input()))", tests, ref)["score"] < 2


def test_sort_properties_without_reference():
    tests = [{"id": "s", "name": "Sortieren", "type": "function", "function": "sortiere", "points": 3,
              "repeat": 3,
              "inputs": [{"kind": "list", "min_len": 0, "max_len": 20,
                          "item": {"kind": "int", "min": -99, "max": 99}}],
              "expected": {"source": "none"},
              "properties": [{"type": "type", "value": "list"}, {"type": "sorted"}],
              "check": "def check(result, expected, inputs):\n"
                       "    return sorted(inputs[0]) == result, 'Elemente stimmen nicht'\n"}]
    good = "def sortiere(l):\n    return sorted(l)\n"
    assert evaluate(good, tests)["score"] == 3
    cheat = "def sortiere(l):\n    return []\n"
    r = evaluate(cheat, tests)
    assert r["score"] < 3, r


def test_class():
    ref = ("class Konto:\n    def __init__(self, s):\n        self.stand = s\n"
           "    def einzahlen(self, b):\n        self.stand += b\n        return self.stand\n")
    tests = [{"id": "k", "name": "Konto", "type": "class", "class": "Konto", "points": 2, "repeat": 3,
              "inputs": [{"kind": "int", "min": 0, "max": 100}],
              "calls": [{"method": "einzahlen", "args": [{"kind": "int", "min": 1, "max": 50}]},
                        {"method": "einzahlen", "args": [{"kind": "fixed", "value": "10"}]}],
              "target": {"type": "attr", "name": "stand"}, "expected": {"source": "reference"}}]
    assert evaluate(ref, tests, ref)["score"] == 2
    wrong = ref.replace("self.stand += b", "self.stand = b")
    assert evaluate(wrong, tests, ref)["score"] == 0


def test_file():
    ref = "n = int(input())\nwith open('quadrate.txt','w') as f:\n    for i in range(1, n+1):\n        f.write(f'{i*i}\\n')\n"
    tests = [{"id": "f", "name": "Datei", "type": "file", "filename": "quadrate.txt", "points": 1,
              "inputs": [{"kind": "int", "min": 1, "max": 20}], "repeat": 2,
              "expected": {"source": "reference"}}]
    assert evaluate(ref, tests, ref)["score"] == 1
    r = evaluate("print('nix')", tests, ref)
    assert r["score"] == 0 and "nicht erstellt" in r["tests"][0]["instances"][0]["messages"][0]


PLOT_REF = '''
import matplotlib.pyplot as plt
tage = [1, 2, 3, 4, 5]
temp = [18, 20, 21, 19, 17]
plt.plot(tage, temp, label="Temperatur")
plt.title("Temperaturverlauf")
plt.xlabel("Tag")
plt.ylabel("Temperatur in °C")
plt.legend()
plt.show()
'''

PLOT_TEST = [{"id": "plot", "name": "Diagramm", "type": "plot", "plot": {"tolerance": 0.01, "criteria": {
    "kind": {"points": 2}, "data": {"points": 2}, "title": {"points": 1, "mode": "present"},
    "xlabel": {"points": 0.5}, "ylabel": {"points": 0.5}, "legend": {"points": 1, "mode": "present"}}}}]


def test_plot():
    r = evaluate(PLOT_REF, PLOT_TEST, PLOT_REF)
    assert r["score"] == 7 and r["max_score"] == 7, r
    inst = r["tests"][0]["instances"][0]
    assert inst["plots"]["student"][0] and inst["plots"]["reference"][0]
    student = PLOT_REF.replace("plt.plot(tage, temp", "plt.bar(tage, temp").replace("plt.legend()\n", "")
    r = evaluate(student, PLOT_TEST, PLOT_REF)
    crit = {c["key"]: c for c in r["tests"][0]["instances"][0]["criteria"]}
    assert not crit["kind"]["passed"] and not crit["legend"]["passed"] and crit["title"]["passed"]
    r = evaluate("print('kein plot')", PLOT_TEST, PLOT_REF)
    assert r["score"] == 0


def test_reference_error_is_config_error():
    r = evaluate("def f(): return 1", [{"id": "x", "type": "function", "function": "f", "points": 1,
                                         "expected": {"source": "reference"}}], "def g(): pass")
    assert r["tests"][0]["status"] == "config_error"


def test_interactive_session():
    s = call("POST", "/v1/sessions", {"code": "name = input('Name? ')\nprint('Hallo', name)\n"
                                              "import matplotlib.pyplot as plt\nplt.plot([1,2]);plt.show()",
                                      "owner": "1"})
    sid = s["id"]
    offset, text, plots = 0, "", 0
    deadline = time.time() + 20
    sent = False
    while time.time() < deadline:
        r = call("GET", "/v1/sessions/%s?offset=%d&wait=1" % (sid, offset))
        offset = r["offset"]
        for e in r["events"]:
            if e["t"] in ("out", "err"):
                text += e["d"]
            if e["t"] == "plot":
                plots += 1
        if "Name?" in text and not sent:
            call("POST", "/v1/sessions/%s/input" % sid, {"data": "Ada\n"})
            sent = True
        if r["done"]:
            break
    assert "Hallo Ada" in text, text
    assert plots == 1
    assert r["exit"]["code"] == 0


if __name__ == "__main__":
    failed = 0
    for name, fn in list(globals().items()):
        if name.startswith("test_") and callable(fn):
            t = time.time()
            try:
                fn()
                print("ok   %-40s %.2fs" % (name, time.time() - t))
            except Exception as exc:  # noqa: BLE001
                failed += 1
                print("FAIL %-40s %r" % (name, exc))
    raise SystemExit(1 if failed else 0)
