"""HTTP API of the runner. Only the Moodle server talks to it."""

import hmac
import logging
from contextlib import asynccontextmanager

from fastapi import Body, Depends, FastAPI, Header, HTTPException

from . import ai, grader
from .config import settings
from .sandbox import Pool, SandboxError, cleanup_stale
from .sessions import SessionManager

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(name)s: %(message)s")
log = logging.getLogger("mplrunner")

state = {}


@asynccontextmanager
async def lifespan(app):
    if not settings.secret or len(settings.secret) < 16:
        raise RuntimeError("MPL_RUNNER_SECRET muss gesetzt sein (mindestens 16 Zeichen)")
    await cleanup_stale()
    pool = Pool(settings.pool_size)
    pool.schedule_refill()
    await pool.start_workers()
    sessions = SessionManager(pool)
    sessions.start_gc()
    state["pool"], state["sessions"] = pool, sessions
    yield
    await sessions.close()
    await pool.close()


app = FastAPI(title="Moodle Programming Lab Runner", version="1.2.0", lifespan=lifespan,
              docs_url=None, redoc_url=None, openapi_url=None)


def auth(authorization: str = Header(default="")):
    token = authorization[7:] if authorization.startswith("Bearer ") else ""
    if not hmac.compare_digest(token.encode(), settings.secret.encode()):
        raise HTTPException(status_code=401, detail="unauthorized")


@app.get("/v1/health")
async def health():
    pool = state.get("pool")
    return {"ok": True, "warm": len([s for s in pool.ready if s.alive]) if pool else 0,
            "workers": pool.worker_count if pool else 0}


@app.get("/v1/info", dependencies=[Depends(auth)])
async def info():
    return {"ok": True, "version": app.version, "max_timeout": settings.max_timeout,
            "max_memory_mb": settings.max_memory_mb, "image": settings.image,
            "ai": ai.enabled(), "ai_model": settings.ai_model if ai.enabled() else None}


@app.post("/v1/hint", dependencies=[Depends(auth)])
async def hint(req: dict = Body(...)):
    try:
        return await ai.hint(req)
    except ai.AIError as exc:
        raise HTTPException(status_code=503, detail=str(exc))


@app.post("/v1/evaluate", dependencies=[Depends(auth)])
async def evaluate(req: dict = Body(...)):
    try:
        return await grader.evaluate(req, state["pool"])
    except SandboxError as exc:
        raise HTTPException(status_code=503, detail=str(exc))


@app.post("/v1/sessions", dependencies=[Depends(auth)])
async def create_session(req: dict = Body(...)):
    try:
        limits = req.get("limits") or {}
        session = await state["sessions"].create(
            req.get("code") or "", str(req.get("owner") or ""),
            timeout=limits.get("timeout"), memory=limits.get("memory"))
    except SandboxError as exc:
        raise HTTPException(status_code=503, detail=str(exc))
    return {"id": session.id}


def _session(sid):
    session = state["sessions"].get(sid)
    if session is None:
        raise HTTPException(status_code=404, detail="Sitzung nicht gefunden")
    return session


@app.get("/v1/sessions/{sid}", dependencies=[Depends(auth)])
async def poll_session(sid: str, offset: int = 0, wait: float = 0.0):
    return await _session(sid).poll(offset, wait)


@app.post("/v1/sessions/{sid}/input", dependencies=[Depends(auth)])
async def session_input(sid: str, req: dict = Body(...)):
    data = str(req.get("data") or "")[:65536]
    return {"ok": await _session(sid).send(data)}


@app.delete("/v1/sessions/{sid}", dependencies=[Depends(auth)])
async def stop_session(sid: str):
    _session(sid).finish("Vom Benutzer gestoppt")
    return {"ok": True}
