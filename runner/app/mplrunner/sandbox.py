"""Throw-away sandbox containers with a small pool of pre-started ones."""

import asyncio
import json
import logging
import uuid

from .config import settings

log = logging.getLogger("mplrunner.sandbox")

LABEL = "mpl.sandbox=1"


def instance_label():
    return "mpl.runner=" + settings.instance
STREAM_LIMIT = 64 * 1024 * 1024


class SandboxError(RuntimeError):
    pass


def docker_args(name, memory_mb, cpus, worker=False):
    memory = "%dm" % int(memory_mb)
    return [
        settings.docker, "run", "-i", "--rm",
        "--name", name,
        "--label", LABEL,
        "--label", instance_label(),
        "--network", "none",
        "--read-only",
        "--tmpfs", "/tmp:rw,nosuid,nodev,size=%dm,mode=1777" % settings.tmpfs_mb,
        "--memory", memory, "--memory-swap", memory,
        "--pids-limit", str(settings.pids_limit),
        "--cpus", str(cpus),
        "--ulimit", "nofile=512:512",
        "--cap-drop", "ALL",
        "--cap-add", "SETUID", "--cap-add", "SETGID", "--cap-add", "KILL",
        "--security-opt", "no-new-privileges",
        "--user", "0",
        "--log-driver", "none",
    ] + (["--runtime", settings.docker_runtime] if settings.docker_runtime else []) + [settings.image] + (
        ["--worker"] if worker else [])


class Sandbox:
    def __init__(self, memory_mb, cpus, worker=False):
        self.name = "mpl-sbx-" + uuid.uuid4().hex[:16]
        self.memory_mb = memory_mb
        self.cpus = cpus
        self.worker = worker
        self.jobs = 0
        self.proc = None

    async def start(self):
        self.proc = await asyncio.create_subprocess_exec(
            *docker_args(self.name, self.memory_mb, self.cpus, self.worker),
            stdin=asyncio.subprocess.PIPE,
            stdout=asyncio.subprocess.PIPE,
            stderr=asyncio.subprocess.DEVNULL,
            limit=STREAM_LIMIT,
        )
        return self

    @property
    def alive(self):
        return self.proc is not None and self.proc.returncode is None

    async def send_job(self, job, close_stdin):
        line = (json.dumps(job, ensure_ascii=False) + "\n").encode("utf-8")
        try:
            self.proc.stdin.write(line)
            await self.proc.stdin.drain()
            if close_stdin:
                self.proc.stdin.close()
        except (BrokenPipeError, ConnectionResetError) as exc:
            raise SandboxError("Sandbox nicht erreichbar") from exc

    async def run_json(self, job, timeout):
        """Send one job, wait for the single JSON answer line."""
        try:
            await self.send_job(job, close_stdin=True)
            data = await asyncio.wait_for(self.proc.stdout.read(), timeout=timeout)
        except asyncio.TimeoutError as exc:
            asyncio.get_running_loop().create_task(self.kill())
            raise SandboxError("Zeitlimit der Sandbox überschritten") from exc
        except BaseException:
            asyncio.get_running_loop().create_task(self.kill())
            raise
        # The harness exits by itself after answering (--rm cleans up); only
        # force-remove containers that linger, and do it in the background.
        asyncio.get_running_loop().create_task(self.reap())
        for line in data.decode("utf-8", errors="replace").splitlines()[::-1]:
            line = line.strip()
            if line.startswith("{"):
                try:
                    return json.loads(line)
                except ValueError:
                    continue
        raise SandboxError("Sandbox lieferte kein Ergebnis")

    async def ask(self, job, timeout):
        """Worker protocol: one job line in, one answer line out."""
        self.jobs += 1
        await self.send_job(job, close_stdin=False)
        line = await asyncio.wait_for(self.proc.stdout.readline(), timeout=timeout)
        if not line:
            raise SandboxError("Sandbox wurde unerwartet beendet")
        try:
            return json.loads(line.decode("utf-8", errors="replace"))
        except ValueError as exc:
            raise SandboxError("Sandbox lieferte kein Ergebnis") from exc

    async def reap(self):
        try:
            await asyncio.wait_for(self.proc.wait(), timeout=5)
        except asyncio.TimeoutError:
            await self.kill()

    async def write_input(self, data):
        if not self.alive:
            return
        try:
            self.proc.stdin.write(data)
            await self.proc.stdin.drain()
        except (BrokenPipeError, ConnectionResetError):
            pass

    async def readline(self):
        return await self.proc.stdout.readline()

    async def kill(self):
        if self.proc is None:
            return
        if self.proc.returncode is None:
            try:
                self.proc.kill()
            except ProcessLookupError:
                pass
        await remove_container(self.name)
        try:
            await asyncio.wait_for(self.proc.wait(), timeout=10)
        except asyncio.TimeoutError:
            pass


async def remove_container(name):
    proc = await asyncio.create_subprocess_exec(
        settings.docker, "rm", "-f", name,
        stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL)
    try:
        await asyncio.wait_for(proc.wait(), timeout=20)
    except asyncio.TimeoutError:
        proc.kill()


async def cleanup_stale():
    proc = await asyncio.create_subprocess_exec(
        settings.docker, "ps", "-aq", "--filter", "label=" + LABEL, "--filter", "label=" + instance_label(),
        stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.DEVNULL)
    out, _ = await proc.communicate()
    ids = out.decode().split()
    if ids:
        rm = await asyncio.create_subprocess_exec(
            settings.docker, "rm", "-f", *ids,
            stdout=asyncio.subprocess.DEVNULL, stderr=asyncio.subprocess.DEVNULL)
        await rm.wait()
        log.info("removed %d stale sandbox containers", len(ids))


class Pool:
    """Sandboxes for the runner.

    * Test evaluations with the default limits go to long-lived *worker*
      containers (one job at a time each; the harness wipes everything
      between jobs). Starting containers is the expensive part, so this keeps
      a whole class checking at once fast.
    * Interactive consoles and evaluations with custom limits get a fresh
      one-shot container; ``size`` of those are kept pre-started.
    """

    def __init__(self, size):
        self.size = size
        self.ready = []
        self.refilling = False
        self.parallel = asyncio.Semaphore(settings.max_parallel)
        self.workers = asyncio.Queue()
        self.worker_count = 0
        self._closed = False

    async def start_workers(self):
        for _ in range(settings.workers):
            await self._add_worker()

    async def _add_worker(self):
        if self._closed:
            return
        try:
            worker = await Sandbox(settings.default_memory_mb, settings.cpus, worker=True).start()
        except Exception:
            log.exception("could not start worker")
            await asyncio.sleep(5)
            asyncio.get_running_loop().create_task(self._add_worker())
            return
        self.worker_count += 1
        await self.workers.put(worker)

    def _retire(self, worker):
        self.worker_count -= 1
        loop = asyncio.get_running_loop()
        loop.create_task(worker.kill())
        loop.create_task(self._add_worker())

    async def run_worker(self, job, timeout):
        worker = await self.workers.get()
        if not worker.alive:
            self._retire(worker)
            return await self.run_worker(job, timeout)
        try:
            answer = await worker.ask(job, timeout)
        except (asyncio.TimeoutError, SandboxError, OSError) as exc:
            self._retire(worker)
            if isinstance(exc, asyncio.TimeoutError):
                raise SandboxError("Zeitlimit der Sandbox überschritten") from exc
            raise
        except BaseException:
            self._retire(worker)
            raise
        if worker.jobs >= settings.worker_jobs or answer.get("error"):
            self._retire(worker)
        else:
            await self.workers.put(worker)
        return answer

    def _default(self, memory_mb, cpus):
        return memory_mb == settings.default_memory_mb and float(cpus) == float(settings.cpus)

    async def acquire(self, memory_mb=None, cpus=None):
        memory_mb = int(memory_mb or settings.default_memory_mb)
        cpus = cpus or settings.cpus
        sandbox = None
        if self._default(memory_mb, cpus):
            while self.ready:
                candidate = self.ready.pop(0)
                if candidate.alive:
                    sandbox = candidate
                    break
            self.schedule_refill()
        if sandbox is None:
            sandbox = await Sandbox(memory_mb, cpus).start()
        return sandbox

    def schedule_refill(self):
        if not self.refilling and not self._closed:
            self.refilling = True
            asyncio.get_running_loop().create_task(self._refill())

    async def _refill(self):
        try:
            while not self._closed and len([s for s in self.ready if s.alive]) < self.size:
                self.ready = [s for s in self.ready if s.alive]
                try:
                    sb = await Sandbox(settings.default_memory_mb, settings.cpus).start()
                except Exception:  # docker unavailable – retry later
                    log.exception("could not start sandbox")
                    await asyncio.sleep(5)
                    continue
                self.ready.append(sb)
        finally:
            self.refilling = False

    async def run(self, job, timeout, memory_mb=None, cpus=None):
        memory_mb = int(memory_mb or settings.default_memory_mb)
        if settings.workers > 0 and self._default(memory_mb, cpus or settings.cpus):
            return await self.run_worker(job, timeout)
        async with self.parallel:
            sandbox = await self.acquire(memory_mb, cpus)
            return await sandbox.run_json(job, timeout)

    async def close(self):
        self._closed = True
        while not self.workers.empty():
            await self.workers.get_nowait().kill()
        await asyncio.gather(*(s.kill() for s in self.ready), return_exceptions=True)
        self.ready = []
