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
        answer = {"choices": [{"message": {"content": "Mock-Hinweis %d: Schau dir an, was `range` genau zurückgibt." % n}}],
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
