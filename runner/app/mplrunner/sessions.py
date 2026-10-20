"""Interactive console sessions (proxied through Moodle via polling)."""

import asyncio
import json
import secrets
import time

from .config import settings
from .sandbox import SandboxError

MAX_EVENTS_BYTES = 4 * 1024 * 1024


class Session:
    def __init__(self, sandbox, owner):
        self.id = secrets.token_urlsafe(18)
        self.owner = owner
        self.sandbox = sandbox
        self.events = []
        self.size = 0
        self.created = time.monotonic()
        self.last_active = self.created
        self.done = False
        self.finished_at = None
        self.exit = None
        self.changed = asyncio.Event()
        self.reader = None

    def push(self, event):
        if event.get("t") in ("out", "err"):
            self.size += len(event.get("d", ""))
            if self.size > MAX_EVENTS_BYTES:
                return
            last = self.events[-1] if self.events else None
            # merge consecutive chunks of the same stream that nobody fetched yet
            if last and last.get("t") == event["t"] and not last.get("_sent") and len(last["d"]) < 65536:
                last["d"] += event["d"]
                self.changed.set()
                return
        self.events.append(event)
        self.changed.set()

    async def read_loop(self):
        try:
            while True:
                line = await self.sandbox.readline()
                if not line:
                    break
                try:
                    msg = json.loads(line.decode("utf-8", errors="replace"))
                except ValueError:
                    continue
                t = msg.get("t")
                if t in ("out", "err"):
                    self.push({"t": t, "d": msg.get("d", "")})
                elif t == "plot":
                    plot = msg.get("plot") or {}
                    self.push({"t": "plot", "png": plot.get("png")})
                elif t == "exit":
                    self.exit = {"code": msg.get("code"), "message": msg.get("message", "")}
        finally:
            self.finish(None)

    def finish(self, message):
        if self.done:
            return
        self.done = True
        self.finished_at = time.monotonic()
        if self.exit is None:
            self.exit = {"code": None, "message": message or "Programm beendet"}
        elif message:
            self.exit["message"] = message
        self.changed.set()
        asyncio.get_running_loop().create_task(self.sandbox.kill())

    async def poll(self, offset, wait):
        self.last_active = time.monotonic()
        offset = max(0, int(offset))
        if offset >= len(self.events) and not self.done and wait > 0:
            self.changed.clear()
            try:
                await asyncio.wait_for(self.changed.wait(), timeout=min(wait, 5.0))
            except asyncio.TimeoutError:
                pass
        events = self.events[offset:]
        for e in events:
            e["_sent"] = True
        return {
            "events": [{k: v for k, v in e.items() if not k.startswith("_")} for e in events],
            "offset": offset + len(events),
            "done": self.done,
            "exit": self.exit if self.done else None,
        }

    async def send(self, text):
        self.last_active = time.monotonic()
        if self.done:
            return False
        await self.sandbox.write_input(text.encode("utf-8"))
        return True


class SessionManager:
    def __init__(self, pool):
        self.pool = pool
        self.sessions = {}
        self.gc_task = None

    def start_gc(self):
        self.gc_task = asyncio.get_running_loop().create_task(self._gc())

    async def create(self, code, owner, timeout=None, memory=None):
        active = [s for s in self.sessions.values() if not s.done]
        if len(active) >= settings.max_sessions:
            raise SandboxError("Zu viele gleichzeitige Ausführungen – bitte gleich nochmal versuchen")
        mine = [s for s in active if owner and s.owner == owner]
        for old in mine:  # one live console per user
            old.finish("Durch neuen Lauf ersetzt")
        timeout = max(1.0, min(float(timeout or settings.default_timeout), settings.max_timeout))
        sandbox = await self.pool.acquire(memory)
        await sandbox.send_job({"mode": "interactive", "code": code,
                                "limits": {"timeout": timeout}}, close_stdin=False)
        session = Session(sandbox, owner)
        session.reader = asyncio.get_running_loop().create_task(session.read_loop())
        self.sessions[session.id] = session
        return session

    def get(self, sid):
        return self.sessions.get(sid)

    async def _gc(self):
        while True:
            await asyncio.sleep(2)
            now = time.monotonic()
            for sid, s in list(self.sessions.items()):
                if not s.done:
                    if now - s.last_active > settings.session_idle:
                        s.finish("Sitzung wegen Inaktivität beendet")
                    elif now - s.created > settings.session_max:
                        s.finish("Maximale Laufzeit der Sitzung erreicht")
                elif s.finished_at and now - s.finished_at > 120:
                    del self.sessions[sid]

    async def close(self):
        if self.gc_task:
            self.gc_task.cancel()
        for s in self.sessions.values():
            if not s.done:
                await s.sandbox.kill()
