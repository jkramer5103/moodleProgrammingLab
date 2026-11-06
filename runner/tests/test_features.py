"""Integration tests for data files and turtle graphics (against a running runner).

    MPL_RUNNER_URL=http://localhost:8000 MPL_RUNNER_SECRET=... python runner/tests/test_features.py
"""

import base64
import json
import os
import time
import urllib.request

URL = os.environ.get("MPL_RUNNER_URL", "http://localhost:18000")
SECRET = os.environ.get("MPL_RUNNER_SECRET", "testsecret1234567890")


def call(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(URL + path, data=data, method=method,
                                 headers={"Authorization": "Bearer " + SECRET, "Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=300) as resp:
        return json.loads(resp.read())


def evaluate(code, tests, reference="", **kw):
    return call("POST", "/v1/evaluate", dict(code=code, reference=reference, tests=tests, **kw))


def b64(text):
    return base64.b64encode(text.encode("utf-8")).decode("ascii")


CSV = "name;punkte\nAnna;12\nBen;7\nCem;15\n"
FILES = [{"name": "noten.csv", "data": b64(CSV)}]

READ_REF = '''
summe = 0
with open("noten.csv", encoding="utf-8") as f:
    next(f)
    for zeile in f:
        name, punkte = zeile.strip().split(";")
        summe += int(punkte)
print(summe)
'''

FILE_TESTS = [
    {"id": "sum", "name": "Summe", "type": "io", "points": 2, "expected": {"source": "reference"}},
    {"id": "other", "name": "Andere Datei", "type": "io", "points": 1, "visibility": "hidden",
     "expected": {"source": "reference"},
     "files": [{"name": "noten.csv", "content": "name;punkte\nX;1\nY;2\n"}]},
]


def test_files_reference_and_override():
    r = evaluate(READ_REF, FILE_TESTS, READ_REF, files=FILES)
    assert r["score"] == 3, r
    sums = [t["instances"][0]["actual_display"].strip() for t in r["tests"]]
    assert sums == ["34", "3"], sums


def test_hardcoded_fails_on_override():
    r = evaluate("print(34)\n", FILE_TESTS, READ_REF, files=FILES)
    assert r["score"] == 2, r  # the hidden test uses another file


def test_missing_files_error():
    r = evaluate(READ_REF, FILE_TESTS[:1], READ_REF)
    t = r["tests"][0]
    assert t["status"] == "config_error" or t["instances"][0]["error"], t


def test_file_cannot_escape():
    files = [{"name": "../../etc/x", "data": b64("x")}, {"name": "main.py", "data": b64("print('evil')")},
             {"name": "sub/a.txt", "data": b64("ok")}]
    code = "import os\nprint(open('sub/a.txt').read())\nprint(os.path.exists('/etc/x'))\n"
    r = evaluate(code, [{"id": "a", "name": "a", "type": "io", "points": 1,
                         "expected": {"source": "fixed", "value": "ok\nFalse"}}], files=files)
    assert r["score"] == 1, json.dumps(r)[:2000]


SQUARE_REF = '''
import turtle
t = turtle.Turtle()
t.pencolor("blue")
for _ in range(4):
    t.forward(100)
    t.left(90)
turtle.done()
'''

SQUARE_OTHER_WAY = '''
from turtle import *
pencolor("#0000ff")
forward(50); forward(50)
left(90)
forward(100)
left(90)
forward(100)
goto(0, 0)
done()
'''

SQUARE_CLOCKWISE = '''
from turtle import *
color("blue")
right(90)
for i in range(4):
    fd(100)
    lt(90)
'''

TURTLE_TEST = [{"id": "q", "name": "Quadrat", "type": "turtle", "turtle": {
    "tolerance": 3, "placement": "exact", "criteria": {
        "drawing": {"enabled": True, "points": 1}, "lines": {"enabled": True, "points": 3},
        "colors": {"enabled": True, "points": 1}}}}]


def test_turtle_equal_drawings():
    r = evaluate(SQUARE_OTHER_WAY, TURTLE_TEST, SQUARE_REF)
    assert r["score"] == 5, json.dumps(r)[:3000]
    inst = r["tests"][0]["instances"][0]
    assert inst["plots"]["student"] and inst["plots"]["reference"], "pngs missing"


def test_turtle_wrong_place_and_color():
    wrong = SQUARE_CLOCKWISE.replace('"blue"', '"red"')
    r = evaluate(wrong, TURTLE_TEST, SQUARE_REF)
    crit = {c["key"]: c for c in r["tests"][0]["instances"][0]["criteria"]}
    assert crit["drawing"]["passed"] and not crit["lines"]["passed"] and not crit["colors"]["passed"], crit
    # Position does not matter -> lines match.
    test = json.loads(json.dumps(TURTLE_TEST))
    test[0]["turtle"]["placement"] = "translate"
    r = evaluate(SQUARE_CLOCKWISE, test, SQUARE_REF)
    assert r["score"] == 5, json.dumps(r["tests"][0]["instances"][0]["criteria"])


def test_turtle_colors_by_position():
    # Right shape, one side in another colour: colours fail although the same colours are used.
    ref = "import turtle\nfor c in ['red', 'blue', 'red', 'blue']:\n    turtle.pencolor(c)\n    turtle.forward(80)\n    turtle.left(90)\n"
    swapped = ref.replace("['red', 'blue', 'red', 'blue']", "['blue', 'red', 'blue', 'red']")
    r = evaluate(swapped, TURTLE_TEST, ref)
    crit = {c["key"]: c for c in r["tests"][0]["instances"][0]["criteria"]}
    assert crit["lines"]["passed"] and not crit["colors"]["passed"], crit
    # Wrong shape but the right colours: colour points are given.
    tri = "import turtle\nfor c in ['red', 'blue', 'red']:\n    turtle.pencolor(c)\n    turtle.forward(80)\n    turtle.left(120)\n"
    r = evaluate(tri, TURTLE_TEST, ref)
    crit = {c["key"]: c for c in r["tests"][0]["instances"][0]["criteria"]}
    assert not crit["lines"]["passed"] and crit["colors"]["passed"], crit


def test_turtle_scale_and_fill_text():
    ref = '''
import turtle as t
t.fillcolor("yellow")
t.begin_fill()
t.circle(50)
t.end_fill()
t.penup(); t.goto(0, -30); t.write("Sonne")
'''
    small = ref.replace("circle(50)", "circle(25)").replace("goto(0, -30)", "goto(0, -15)")
    test = [{"id": "s", "name": "Sonne", "type": "turtle", "turtle": {"tolerance": 3, "placement": "scale", "criteria": {
        "lines": {"enabled": True, "points": 2}, "fills": {"enabled": True, "points": 1},
        "texts": {"enabled": True, "points": 1}}}}]
    r = evaluate(small, test, ref)
    assert r["score"] == 4, json.dumps(r["tests"][0]["instances"][0]["criteria"])
    r = evaluate(small.replace("Sonne", "Mond").replace("yellow", "orange"), test, ref)
    crit = {c["key"]: c for c in r["tests"][0]["instances"][0]["criteria"]}
    assert crit["lines"]["passed"] and not crit["fills"]["passed"] and not crit["texts"]["passed"], crit


def test_turtle_random_input():
    ref = "import turtle\nn = int(input())\nfor i in range(n):\n    turtle.forward(50)\n    turtle.left(360 / n)\n"
    test = [{"id": "p", "name": "Vieleck", "type": "turtle", "repeat": 3,
             "inputs": [{"kind": "int", "min": 3, "max": 8}],
             "turtle": {"tolerance": 2, "criteria": {"lines": {"enabled": True, "points": 2}}}}]
    r = evaluate(ref, test, ref)
    assert r["score"] == 2, r
    r = evaluate("import turtle\nn = int(input())\nfor i in range(5):\n    turtle.forward(50)\n    turtle.left(72)\n",
                 test, ref)
    assert r["score"] < 2, r


def test_turtle_nothing_drawn():
    r = evaluate("print('hallo')\n", TURTLE_TEST, SQUARE_REF)
    assert r["score"] == 0
    crit = r["tests"][0]["instances"][0]["criteria"]
    assert "nichts gezeichnet" in crit[0]["message"], crit


def test_turtle_console_shows_png():
    sid = call("POST", "/v1/sessions", {"code": SQUARE_REF + "\nprint('fertig')\n", "owner": "t",
                                         "files": FILES})["id"]
    events, offset = [], 0
    for _ in range(60):
        data = call("GET", "/v1/sessions/%s?offset=%d&wait=1" % (sid, offset))
        events += data["events"]
        offset = data["offset"]
        if data["done"]:
            break
        time.sleep(0.2)
    kinds = [e["t"] for e in events]
    assert "plot" in kinds and any(e["t"] == "out" and "fertig" in e["d"] for e in events), events


def test_console_reads_file():
    sid = call("POST", "/v1/sessions", {"code": "print(open('noten.csv').read().count(';'))\n", "owner": "t2",
                                         "files": FILES})["id"]
    out, offset = "", 0
    for _ in range(60):
        data = call("GET", "/v1/sessions/%s?offset=%d&wait=1" % (sid, offset))
        out += "".join(e.get("d", "") for e in data["events"])
        offset = data["offset"]
        if data["done"]:
            break
    assert out.strip().startswith("4"), out


if __name__ == "__main__":
    failed = 0
    for name, fn in list(globals().items()):
        if name.startswith("test_") and callable(fn):
            try:
                fn()
                print("ok  ", name)
            except Exception as exc:  # noqa: BLE001
                failed += 1
                print("FAIL", name, exc)
    raise SystemExit(1 if failed else 0)
