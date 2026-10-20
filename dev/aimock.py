"""Fake OpenAI-compatible chat API for tests: answers with a canned hint and logs requests."""
import json
from http.server import BaseHTTPRequestHandler, HTTPServer

LOG = "/log/requests.jsonl"


class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", 0))))
        with open(LOG, "a") as fh:
            fh.write(json.dumps({"auth": self.headers.get("Authorization"), "body": body}) + "\n")
        n = sum(1 for _ in open(LOG))
        content = "Mock-Hinweis %d: Schau dir an, was `range` genau zurückgibt." % n
        if "JSON-Objekt" in body["messages"][0]["content"]:
            content = json.dumps({
                "message": "Mock: Aufgabe erstellt.",
                "task": {"name": "Mock-Aufgabe: Quadrat", "intro": "<p>Schreibe <code>quadrat(x)</code>.</p>",
                         "starter": "def quadrat(x):\n    pass\n", "reference": "def quadrat(x):\n    return x * x\n",
                         "tests": [{"id": "z", "name": "Zufall", "type": "function", "function": "quadrat", "points": 3,
                                    "repeat": 3, "inputs": [{"kind": "int", "min": -9, "max": 9}],
                                    "expected": {"source": "reference"}}],
                         "settings": {"aihints": False, "maxattempts": 1, "timeout": 999}},
                "wrong_solutions": [{"description": "verdoppelt statt quadriert", "code": "def quadrat(x):\n    return 2 * x\n"}]})
        answer = {"choices": [{"message": {"content": content}}],
                  "model": body.get("model"), "usage": {"prompt_tokens": 100, "completion_tokens": 20}}
        data = json.dumps(answer).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def log_message(self, *args):
        pass


HTTPServer(("0.0.0.0", 9000), Handler).serve_forever()
