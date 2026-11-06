"""Sandbox harness for Moodle Programming Lab.

Runs inside a throw-away container (no network, read-only root fs). The
harness itself runs as root with only SETUID/SETGID/KILL capabilities; every
piece of submitted code runs in a forked child that drops to an unprivileged
uid, so it can neither touch the harness process nor its output channel.

The harness never sees reference solutions or grading rules of *other* code:
it only executes the code it was handed and reports raw observations
(stdout, return values, figure structure, files). Grading happens outside.

Protocol: the first line on stdin is a JSON job. In "evaluate" and "check"
mode the harness answers with a single JSON line. In "interactive" mode it
forwards the rest of stdin to the program and streams JSON lines.
"""

import base64
import builtins
import io
import json
import os
import selectors
import signal
import sys
import time
import traceback

sys.path.insert(0, "/opt/mpl")
import mplcodec  # noqa: E402

STUDENT_UID = 1000
STUDENT_GID = 1000
MAX_FIGURES = 6
MAX_POINTS = 5000

# Pre-import heavy libraries once, children inherit them via fork. The font
# cache built into the image is copied to the writable tmpfs first.
os.environ.setdefault("MPLBACKEND", "Agg")
try:
    import shutil
    shutil.copytree("/opt/mplconfig", "/tmp/.mplconfig", dirs_exist_ok=True)
    os.chmod("/tmp/.mplconfig", 0o700)
    os.environ["MPLCONFIGDIR"] = "/tmp/.mplconfig"
except Exception:
    pass
try:
    import matplotlib
    matplotlib.use("Agg")
    import matplotlib.pyplot as plt
    from matplotlib.container import BarContainer
    from matplotlib.collections import PathCollection
    from matplotlib.patches import Wedge
    import numpy  # noqa: F401
    HAVE_MPL = True
    try:
        import pandas  # noqa: F401  (pre-imported so that pandas tasks start fast)
    except Exception:
        pass
except Exception:  # pragma: no cover - image always has matplotlib
    HAVE_MPL = False
try:
    import mplturtle  # headless "turtle" (no Tk in the sandbox), installed as sys.modules["turtle"] in the child
except Exception:  # pragma: no cover
    mplturtle = None


def out_line(obj):
    data = (json.dumps(obj, ensure_ascii=False) + "\n").encode("utf-8")
    view = memoryview(data)
    while view:
        n = os.write(1, view)
        view = view[n:]


# --------------------------------------------------------------------------
# Child side
# --------------------------------------------------------------------------

class Channel:
    """Result channel (pipe) from the child to the harness."""

    def __init__(self, fd):
        self.fd = fd

    def send(self, obj):
        data = (json.dumps(obj, ensure_ascii=False) + "\n").encode("utf-8")
        view = memoryview(data)
        while view:
            n = os.write(self.fd, view)
            view = view[n:]


def drop_privileges(timeout, file_limit):
    import resource
    os.setsid()
    if os.getuid() == 0:
        os.setgroups([])
        os.setgid(STUDENT_GID)
        os.setuid(STUDENT_UID)
    cpu = max(1, int(timeout) + 1)
    resource.setrlimit(resource.RLIMIT_CPU, (cpu, cpu + 1))
    resource.setrlimit(resource.RLIMIT_FSIZE, (file_limit, file_limit))
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))


def setup_stdio(stdin_fd, stdout_fd, stderr_fd):
    os.dup2(stdin_fd, 0)
    os.dup2(stdout_fd, 1)
    os.dup2(stderr_fd, 2)
    sys.stdin = io.TextIOWrapper(io.FileIO(0, "r", closefd=False), encoding="utf-8", errors="replace")
    sys.stdout = io.TextIOWrapper(io.FileIO(1, "w", closefd=False), encoding="utf-8",
                                  errors="replace", line_buffering=True, write_through=True)
    sys.stderr = io.TextIOWrapper(io.FileIO(2, "w", closefd=False), encoding="utf-8",
                                  errors="replace", line_buffering=True, write_through=True)


def format_error(exc):
    """Traceback restricted to the frames of the submitted file."""
    te = traceback.TracebackException.from_exception(exc)
    frames = [f for f in te.stack if f.filename == "main.py"]
    te.stack = traceback.StackSummary.from_list(frames)
    lines = list(te.format())
    if not frames and lines and lines[0].startswith("Traceback"):
        lines = lines[1:]
    text = "".join(lines)
    if len(text) > 8000:
        text = text[-8000:]
    return {"type": type(exc).__name__, "message": str(exc)[:2000], "traceback": text}


def to_list(values):
    out = []
    try:
        seq = list(values)
    except Exception:
        return out
    for v in seq[:MAX_POINTS]:
        try:
            f = float(v)
            out.append(f if f == f else None)
        except Exception:
            out.append(str(v))
    return out


def _text(t):
    try:
        return t.get_text()
    except Exception:
        return ""


def axes_info(ax):
    info = {
        "title": ax.get_title() or ax.get_title(loc="left") or ax.get_title(loc="right"),
        "xlabel": ax.get_xlabel(),
        "ylabel": ax.get_ylabel(),
        "legend": None,
        "series": [],
    }
    legend = ax.get_legend()
    if legend is not None:
        info["legend"] = [_text(t) for t in legend.get_texts()]

    tick_map = {}
    try:
        for pos, lab in zip(ax.get_xticks(), ax.get_xticklabels()):
            tick_map[round(float(pos), 6)] = _text(lab)
    except Exception:
        pass

    bar_patches = set()
    for container in ax.containers:
        if isinstance(container, BarContainer):
            rects = list(container.patches)
            bar_patches.update(id(r) for r in rects)
            horizontal = getattr(container, "orientation", "vertical") == "horizontal"
            if horizontal:
                xs = [r.get_y() + r.get_height() / 2 for r in rects]
                ys = [r.get_width() for r in rects]
            else:
                xs = [r.get_x() + r.get_width() / 2 for r in rects]
                ys = [r.get_height() for r in rects]
            cats = [tick_map.get(round(float(x), 6), "") for x in xs]
            info["series"].append({
                "type": "barh" if horizontal else "bar",
                "label": _label(container.get_label()),
                "x": to_list(xs),
                "y": to_list(ys),
                "categories": cats if any(cats) else None,
            })
    for line in ax.get_lines():
        info["series"].append({
            "type": "line",
            "label": _label(line.get_label()),
            "x": to_list(line.get_xdata()),
            "y": to_list(line.get_ydata()),
        })
    for coll in ax.collections:
        if isinstance(coll, PathCollection):
            offs = coll.get_offsets()
            try:
                xs = [p[0] for p in offs]
                ys = [p[1] for p in offs]
            except Exception:
                xs, ys = [], []
            info["series"].append({
                "type": "scatter",
                "label": _label(coll.get_label()),
                "x": to_list(xs),
                "y": to_list(ys),
            })
    wedges = [p for p in ax.patches if isinstance(p, Wedge) and id(p) not in bar_patches]
    if wedges:
        info["series"].append({
            "type": "pie",
            "label": None,
            "x": [_text(t) for t in ax.texts][:len(wedges)],
            "y": to_list([(w.theta2 - w.theta1) / 360.0 for w in wedges]),
        })
    return info


def _label(label):
    if not label or str(label).startswith("_"):
        return None
    return str(label)


class PlotRecorder:
    def __init__(self, channel=None):
        self.figures = []
        self.seen = set()
        self.channel = channel  # stream plots immediately (interactive mode)

    def snapshot(self):
        if not HAVE_MPL:
            return
        for num in plt.get_fignums():
            fig = plt.figure(num)
            if id(fig) in self.seen or len(self.figures) >= MAX_FIGURES:
                continue
            self.seen.add(id(fig))
            try:
                fig.canvas.draw()
                buf = io.BytesIO()
                fig.savefig(buf, format="png", dpi=72)
                png = base64.b64encode(buf.getvalue()).decode("ascii")
                if len(png) > 400000:
                    png = None
                info = {
                    "suptitle": fig._suptitle.get_text() if fig._suptitle is not None else "",
                    "axes": [axes_info(ax) for ax in fig.get_axes()],
                    "png": png,
                }
            except Exception as exc:  # broken figure, keep going
                info = {"axes": [], "png": None, "error": str(exc)[:500]}
            self.figures.append(info)
            if self.channel is not None:
                self.channel.send({"k": "plot", "plot": info})

    def install(self):
        if not HAVE_MPL:
            return
        recorder = self
        original_close = plt.close

        def show(*args, **kwargs):
            recorder.snapshot()
            original_close("all")

        def close(*args, **kwargs):
            recorder.snapshot()
            return original_close(*args, **kwargs)

        plt.show = show
        plt.close = close


def safe_relpath(name):
    """Relative path inside the working directory, or None if not allowed."""
    name = os.path.normpath(str(name or "").replace("\\", "/")).lstrip("/")
    if not name or name in (".", "main.py") or name.startswith("..") or "\0" in name:
        return None
    return name


def write_data_files(workdir, files):
    """Data files of the task (e.g. a CSV to read), written before the program starts."""
    for f in files or []:
        name = safe_relpath(f.get("name"))
        if not name:
            continue
        path = os.path.join(workdir, name)
        os.makedirs(os.path.dirname(path) or workdir, exist_ok=True)
        with open(path, "wb") as fh:
            fh.write(base64.b64decode(f.get("data") or ""))


def merge_files(job_files, case_files):
    """Files of a single test replace task files with the same name."""
    merged = {}
    for f in (job_files or []) + (case_files or []):
        name = safe_relpath(f.get("name"))
        if name:
            merged[name] = f
    return list(merged.values())


def install_turtle(channel, interactive):
    if mplturtle is None:
        return
    sys.modules["turtle"] = mplturtle
    if interactive:
        mplturtle.on_show = lambda png: channel.send({"k": "plot", "plot": {"png": png, "axes": [], "turtle": True}})


def fresh_globals(name):
    return {"__name__": name, "__file__": "main.py", "__builtins__": builtins, "__doc__": None}


def child_main(case, code, channel, workdir, interactive=False, files=None):
    """Runs inside the forked, unprivileged child. Never returns."""
    status = 0
    result = {"error": None, "value": None, "exit_code": 0}
    recorder = PlotRecorder(channel if interactive else None)
    try:
        os.makedirs(workdir, exist_ok=True)
        os.chdir(workdir)
        write_data_files(workdir, files)
        install_turtle(channel, interactive)
        with open("main.py", "w", encoding="utf-8") as fh:
            fh.write(code)
        if "/opt/mpl" in sys.path:
            sys.path.remove("/opt/mpl")
        sys.path.insert(0, workdir)
        sys.argv = ["main.py"]
        recorder.install()
        kind = case.get("kind", "program")
        compiled = compile(code, "main.py", "exec")
        if kind == "program":
            g = fresh_globals("__main__")
            try:
                exec(compiled, g)
            except SystemExit as exc:
                c = exc.code
                result["exit_code"] = c if isinstance(c, int) else (0 if c is None else 1)
                if c is not None and not isinstance(c, int):
                    sys.stderr.write(str(c) + "\n")
        else:
            g = fresh_globals("student_solution")
            exec(compiled, g)
            if kind == "function":
                fn = g.get(case.get("function", ""))
                if not callable(fn):
                    raise NameError("Funktion '%s' wurde nicht gefunden" % case.get("function"))
                args = [mplcodec.decode(a) for a in case.get("args", [])]
                result["value"] = mplcodec.encode(fn(*args))
            elif kind == "class":
                cls = g.get(case.get("class", ""))
                if not isinstance(cls, type):
                    raise NameError("Klasse '%s' wurde nicht gefunden" % case.get("class"))
                obj = cls(*[mplcodec.decode(a) for a in case.get("init_args", [])])
                returns = []
                for call in case.get("calls", []):
                    method = getattr(obj, call.get("method", ""), None)
                    if not callable(method):
                        raise AttributeError("Methode '%s' wurde nicht gefunden" % call.get("method"))
                    returns.append(method(*[mplcodec.decode(a) for a in call.get("args", [])]))
                target = case.get("target") or {"type": "return"}
                if target.get("type") == "attr":
                    name = target.get("name", "")
                    if not hasattr(obj, name):
                        raise AttributeError("Attribut '%s' existiert nicht" % name)
                    value = getattr(obj, name)
                elif target.get("type") == "returns":
                    value = returns
                elif target.get("type") == "str":
                    value = str(obj)
                else:
                    value = returns[-1] if returns else None
                result["value"] = mplcodec.encode(value)
            elif kind == "check":
                fn = g.get("check")
                if not callable(fn):
                    raise NameError("Das Prüfskript muss eine Funktion check(result, expected, inputs) definieren")
                data = case.get("data", {})
                verdict = fn(mplcodec.decode(data.get("result")), mplcodec.decode(data.get("expected")),
                             mplcodec.decode(data.get("inputs")))
                result["value"] = normalise_verdict(verdict)
    except BaseException as exc:  # noqa: BLE001 - report everything
        if isinstance(exc, KeyboardInterrupt):
            exc = RuntimeError("abgebrochen")
        result["error"] = format_error(exc)
        result["exit_code"] = 1
        status = 1
        if interactive:
            try:
                sys.stderr.write(result["error"]["traceback"])
            except Exception:
                pass
    try:
        sys.stdout.flush()
        sys.stderr.flush()
    except Exception:
        pass
    if case.get("capture_plots", True):
        try:
            recorder.snapshot()
        except Exception:
            pass
    if mplturtle is not None and mplturtle.used():
        try:
            if interactive:
                mplturtle.show_if_pending()
            elif case.get("capture_turtle"):
                result["turtle"] = mplturtle.export(with_png=True)
        except Exception as exc:
            if case.get("capture_turtle") and not result.get("error"):
                result["error"] = {"type": "TurtleError", "message": "Zeichnung konnte nicht ausgewertet werden: %s"
                                   % str(exc)[:500], "traceback": ""}
    result["plots"] = [] if interactive else recorder.figures
    read_file = case.get("read_file")
    if read_file:
        result["file"] = read_result_file(workdir, read_file)
    try:
        channel.send({"k": "result", "result": result})
    except Exception:
        pass
    os._exit(status)


def normalise_verdict(verdict):
    message = ""
    if isinstance(verdict, tuple) and len(verdict) == 2:
        verdict, message = verdict
    if isinstance(verdict, bool):
        score = 1.0 if verdict else 0.0
    elif isinstance(verdict, (int, float)):
        score = max(0.0, min(1.0, float(verdict)))
    elif isinstance(verdict, str):
        score, message = 0.0, verdict
    elif verdict is None:
        score = 1.0
    else:
        score = 1.0 if verdict else 0.0
    return {"score": score, "message": str(message)[:2000]}


def read_result_file(workdir, name):
    name = os.path.normpath(name).lstrip("/")
    if name.startswith(".."):
        return {"exists": False, "content": None}
    path = os.path.join(workdir, name)
    if not os.path.isfile(path):
        return {"exists": False, "content": None}
    with open(path, "rb") as fh:
        data = fh.read(1024 * 1024 + 1)
    return {"exists": True, "content": data[:1024 * 1024].decode("utf-8", errors="replace"),
            "truncated": len(data) > 1024 * 1024}


# --------------------------------------------------------------------------
# Parent side
# --------------------------------------------------------------------------

def reap():
    while True:
        try:
            pid, _ = os.waitpid(-1, os.WNOHANG)
        except ChildProcessError:
            return
        if pid == 0:
            return


def kill_student_processes():
    """Kill all leftovers of the last run (background processes, fork bombs).

    The harness is PID 1 of its container, so kill(-1) hits every other
    process in the container. Orphans are re-parented to us and reaped here.
    """
    deadline = time.monotonic() + 3
    while time.monotonic() < deadline:
        if os.getpid() == 1:
            try:
                os.kill(-1, signal.SIGKILL)
            except ProcessLookupError:
                pass
        else:  # only when started outside a container (tests)
            for entry in os.listdir("/proc"):
                if entry.isdigit():
                    try:
                        if os.stat("/proc/" + entry).st_uid == STUDENT_UID:
                            os.kill(int(entry), signal.SIGKILL)
                    except (FileNotFoundError, ProcessLookupError, PermissionError):
                        pass
        time.sleep(0.01)
        reap()
        alive = False
        for entry in os.listdir("/proc"):
            if entry.isdigit() and entry != str(os.getpid()):
                try:
                    with open("/proc/%s/stat" % entry) as fh:
                        state = fh.read().rsplit(")", 1)[1].split()[0]
                    if state != "Z" and os.stat("/proc/" + entry).st_uid == STUDENT_UID:
                        alive = True
                        break
                except (FileNotFoundError, ProcessLookupError, IndexError):
                    pass
        if not alive:
            reap()
            return


def spawn(case, code, workdir, timeout, file_limit, stdin_data=None, interactive=False, files=None):
    in_r, in_w = os.pipe()
    out_r, out_w = os.pipe()
    err_r, err_w = os.pipe()
    res_r, res_w = os.pipe()
    for attempt in range(20):
        try:
            pid = os.fork()
            break
        except BlockingIOError:
            kill_student_processes()
            time.sleep(0.05)
    else:
        raise RuntimeError("fork failed")
    if pid == 0:
        try:
            os.close(in_w)
            os.close(out_r)
            os.close(err_r)
            os.close(res_r)
            signal.signal(signal.SIGPIPE, signal.SIG_DFL)
            drop_privileges(timeout, file_limit)
            setup_stdio(in_r, out_w, err_w)
            for fd in (in_r, out_w, err_w):
                if fd > 2:
                    os.close(fd)
            child_main(case, code, Channel(res_w), workdir, interactive, files)
        finally:
            os._exit(70)
    os.close(in_r)
    os.close(out_w)
    os.close(err_w)
    os.close(res_w)
    if not interactive:
        if stdin_data:
            try:
                os.write(in_w, stdin_data.encode("utf-8")[:4 * 1024 * 1024])
            except (BrokenPipeError, OSError):
                pass
        os.close(in_w)
        in_w = None
    return pid, in_w, out_r, err_r, res_r


def run_case(case, code, limits, index, files=None):
    timeout = float(case.get("timeout") or limits.get("timeout", 30))
    output_limit = int(limits.get("output_limit", 1024 * 1024))
    file_limit = int(limits.get("file_limit", 10 * 1024 * 1024))
    workdir = "/tmp/run%d" % index
    started = time.monotonic()
    pid, _, out_r, err_r, res_r = spawn(case, code, workdir, timeout, file_limit,
                                        stdin_data=case.get("stdin", ""),
                                        files=merge_files(files, case.get("files")))
    sel = selectors.DefaultSelector()
    buffers = {out_r: bytearray(), err_r: bytearray(), res_r: bytearray()}
    for fd in buffers:
        os.set_blocking(fd, False)
        sel.register(fd, selectors.EVENT_READ)
    timed_out = False
    output_exceeded = False
    open_fds = set(buffers)
    status = None
    deadline = started + timeout
    while open_fds:
        remaining = deadline - time.monotonic()
        if remaining <= 0:
            timed_out = status is None
            break
        for key, _ in sel.select(timeout=min(remaining, 0.5)):
            fd = key.fd
            try:
                chunk = os.read(fd, 65536)
            except BlockingIOError:
                continue
            if not chunk:
                sel.unregister(fd)
                open_fds.discard(fd)
                continue
            buf = buffers[fd]
            limit = 32 * 1024 * 1024 if fd == res_r else output_limit
            if len(buf) + len(chunk) > limit:
                buf.extend(chunk[: max(0, limit - len(buf))])
                output_exceeded = True
            else:
                buf.extend(chunk)
        if output_exceeded:
            break
        if status is None:
            wpid, st = os.waitpid(pid, os.WNOHANG)
            if wpid:
                status = st
                # give the pipes a short moment to drain
                deadline = min(deadline, time.monotonic() + 0.5)
    if status is None:
        try:
            os.killpg(pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        try:
            _, status = os.waitpid(pid, 0)
        except ChildProcessError:
            status = 0
    kill_student_processes()
    for fd in buffers:
        try:
            sel.unregister(fd)
        except Exception:
            pass
        os.close(fd)
    duration = time.monotonic() - started

    result = None
    for line in bytes(buffers[res_r]).decode("utf-8", errors="replace").splitlines():
        try:
            msg = json.loads(line)
        except ValueError:
            continue
        if isinstance(msg, dict) and msg.get("k") == "result":
            result = msg.get("result")
    if not isinstance(result, dict):
        result = {"error": None, "value": None, "exit_code": None, "plots": []}
    result["id"] = case.get("id")
    result["stdout"] = bytes(buffers[out_r]).decode("utf-8", errors="replace")
    result["stderr"] = bytes(buffers[err_r]).decode("utf-8", errors="replace")
    result["duration"] = round(duration, 3)
    result["timeout"] = timed_out
    result["output_exceeded"] = output_exceeded
    if timed_out:
        result["error"] = {"type": "Timeout",
                           "message": "Zeitlimit von %g s überschritten" % timeout,
                           "traceback": ""}
    elif output_exceeded:
        result["error"] = {"type": "OutputLimit",
                           "message": "Ausgabelimit überschritten (Endlosschleife?)",
                           "traceback": ""}
    elif result.get("exit_code") is None:
        sig = os.WTERMSIG(status) if os.WIFSIGNALED(status) else None
        if sig == signal.SIGKILL:
            msg = "Programm wurde abgebrochen (Speicherlimit überschritten?)"
        elif sig == signal.SIGXCPU:
            msg = "CPU-Zeitlimit überschritten"
        elif sig is not None:
            msg = "Programm durch Signal %d beendet" % sig
        else:
            msg = "Programm wurde unerwartet beendet"
        result["error"] = {"type": "Crash", "message": msg, "traceback": ""}
    return result


def mode_evaluate(job):
    limits = job.get("limits") or {}
    code = job.get("code", "")
    files = job.get("files") or []
    results = [run_case(case, code, limits, i, files) for i, case in enumerate(job.get("cases", []))]
    out_line({"results": results})


def mode_check(job):
    limits = job.get("limits") or {}
    results = []
    for i, check in enumerate(job.get("checks", [])):
        case = {"id": check.get("id"), "kind": "check", "data": check.get("data", {}),
                "capture_plots": False, "timeout": limits.get("timeout", 10)}
        results.append(run_case(case, check.get("code", ""), limits, i))
    out_line({"results": results})


def mode_interactive(job, stdin_reader):
    limits = job.get("limits") or {}
    timeout = float(limits.get("timeout", 30))
    output_limit = int(limits.get("output_limit", 1024 * 1024))
    file_limit = int(limits.get("file_limit", 10 * 1024 * 1024))
    case = {"kind": "program", "capture_plots": True}
    pid, in_w, out_r, err_r, res_r = spawn(case, job.get("code", ""), "/tmp/run", timeout,
                                           file_limit, interactive=True, files=merge_files(job.get("files"), None))
    sel = selectors.DefaultSelector()
    streams = {out_r: "out", err_r: "err", res_r: "res"}
    for fd in streams:
        os.set_blocking(fd, False)
        sel.register(fd, selectors.EVENT_READ)
    parent_in = stdin_reader.fileno_if_raw()
    if parent_in is not None:
        sel.register(parent_in, selectors.EVENT_READ)
    pending = stdin_reader.leftover()
    if pending and in_w is not None:
        try:
            os.write(in_w, pending)
        except OSError:
            pass
    res_buf = bytearray()
    total = 0
    open_fds = {out_r, err_r, res_r}
    status = None
    exited_at = None
    decoders = {out_r: _decoder(), err_r: _decoder()}
    while open_fds:
        events = sel.select(timeout=0.2)
        for key, _ in events:
            fd = key.fd
            if fd == parent_in:
                try:
                    data = os.read(fd, 65536)
                except BlockingIOError:
                    continue
                if not data:
                    sel.unregister(fd)
                    parent_in = None
                    if in_w is not None:
                        os.close(in_w)
                        in_w = None
                elif in_w is not None:
                    try:
                        os.write(in_w, data)
                    except OSError:
                        pass
                continue
            try:
                chunk = os.read(fd, 65536)
            except BlockingIOError:
                continue
            if not chunk:
                sel.unregister(fd)
                open_fds.discard(fd)
                continue
            if fd == res_r:
                res_buf.extend(chunk)
                while b"\n" in res_buf:
                    line, _, rest = bytes(res_buf).partition(b"\n")
                    res_buf = bytearray(rest)
                    try:
                        msg = json.loads(line.decode("utf-8", errors="replace"))
                    except ValueError:
                        continue
                    if msg.get("k") == "plot":
                        out_line({"t": "plot", "plot": msg.get("plot")})
                continue
            total += len(chunk)
            text = decoders[fd].decode(chunk)
            if text:
                out_line({"t": streams[fd], "d": text})
            if total > output_limit:
                out_line({"t": "err", "d": "\n[Ausgabelimit überschritten – Programm beendet]\n"})
                try:
                    os.killpg(pid, signal.SIGKILL)
                except ProcessLookupError:
                    pass
        if status is None:
            wpid, st = os.waitpid(pid, os.WNOHANG)
            if wpid:
                status = st
                exited_at = time.monotonic()
        if exited_at is not None and time.monotonic() - exited_at > 1.0:
            break
    if status is None:
        try:
            os.killpg(pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        _, status = os.waitpid(pid, 0)
    kill_student_processes()
    code = None
    if os.WIFEXITED(status):
        code = os.WEXITSTATUS(status)
    message = ""
    if os.WIFSIGNALED(status):
        sig = os.WTERMSIG(status)
        if sig == signal.SIGXCPU:
            message = "CPU-Zeitlimit überschritten"
        elif sig == signal.SIGKILL:
            message = "Programm wurde beendet (Speicherlimit überschritten?)"
        else:
            message = "Programm durch Signal %d beendet" % sig
    out_line({"t": "exit", "code": code, "message": message})


def _decoder():
    import codecs
    return codecs.getincrementaldecoder("utf-8")(errors="replace")


class StdinReader:
    """Reads the job line from fd 0 without losing bytes that follow it."""

    def __init__(self):
        self.buf = b""

    def read_job_line(self):
        while b"\n" not in self.buf:
            chunk = os.read(0, 65536)
            if not chunk:
                break
            self.buf += chunk
            if len(self.buf) > 64 * 1024 * 1024:
                raise ValueError("job too large")
        line, _, rest = self.buf.partition(b"\n")
        self.buf = rest
        return line

    def leftover(self):
        data, self.buf = self.buf, b""
        return data

    def fileno_if_raw(self):
        os.set_blocking(0, False)
        return 0


def wipe_scratch():
    """Remove everything a previous job left in the writable areas."""
    import shutil
    for base in ("/tmp", "/dev/shm"):
        try:
            entries = os.listdir(base)
        except OSError:
            continue
        for name in entries:
            if base == "/tmp" and name == ".mplconfig":
                continue
            path = os.path.join(base, name)
            try:
                if os.path.isdir(path) and not os.path.islink(path):
                    shutil.rmtree(path, ignore_errors=True)
                else:
                    os.unlink(path)
            except OSError:
                pass


def worker_loop(max_jobs):
    """Long-lived worker: one evaluate/check job per line, answer per line.

    Between jobs every student process is killed and the scratch space is
    wiped, so no state survives from one job to the next.
    """
    reader = StdinReader()
    for _ in range(max_jobs):
        try:
            line = reader.read_job_line()
        except Exception:
            return
        if not line.strip():
            return
        try:
            job = json.loads(line.decode("utf-8"))
            if job.get("mode") == "evaluate":
                mode_evaluate(job)
            elif job.get("mode") == "check":
                mode_check(job)
            else:
                out_line({"error": "unsupported mode in worker"})
        except Exception as exc:  # keep the protocol in sync
            out_line({"error": "harness: %s" % exc})
        kill_student_processes()
        wipe_scratch()


def main():
    signal.signal(signal.SIGPIPE, signal.SIG_DFL)
    if "--worker" in sys.argv:
        worker_loop(int(os.environ.get("MPL_WORKER_JOBS", "200")))
        return
    reader = StdinReader()
    try:
        line = reader.read_job_line()
    except Exception:
        return
    if not line.strip():
        return
    job = json.loads(line.decode("utf-8"))
    mode = job.get("mode")
    if mode == "evaluate":
        mode_evaluate(job)
    elif mode == "check":
        mode_check(job)
    elif mode == "interactive":
        mode_interactive(job, reader)
    else:
        out_line({"error": "unknown mode"})


if __name__ == "__main__":
    main()
