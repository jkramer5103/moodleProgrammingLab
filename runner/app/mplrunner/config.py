import os


def _int(name, default):
    try:
        return int(os.environ.get(name, default))
    except ValueError:
        return default


def _float(name, default):
    try:
        return float(os.environ.get(name, default))
    except ValueError:
        return default


class Settings:
    def __init__(self):
        self.secret = os.environ.get("MPL_RUNNER_SECRET", "")
        self.image = os.environ.get("MPL_SANDBOX_IMAGE", "mpl-sandbox:latest")
        self.docker = os.environ.get("MPL_DOCKER", "docker")
        self.instance = os.environ.get("MPL_INSTANCE", "default")  # separates several runners on one host
        self.docker_runtime = os.environ.get("MPL_DOCKER_RUNTIME", "")  # e.g. "runsc" for gVisor
        self.pool_size = _int("MPL_POOL_SIZE", 4)
        self.max_parallel = _int("MPL_MAX_PARALLEL", max(4, (os.cpu_count() or 2) * 2))
        self.workers = _int("MPL_WORKERS", max(4, min(os.cpu_count() or 2, 8)))
        self.worker_jobs = _int("MPL_WORKER_JOBS", 200)
        self.max_sessions = _int("MPL_MAX_SESSIONS", 40)
        self.session_idle = _int("MPL_SESSION_IDLE", 120)
        self.session_max = _int("MPL_SESSION_MAX", 600)
        self.default_memory_mb = _int("MPL_MEMORY_MB", 512)
        self.max_memory_mb = _int("MPL_MAX_MEMORY_MB", 1024)
        self.default_timeout = _float("MPL_TIMEOUT", 30)
        self.max_timeout = _float("MPL_MAX_TIMEOUT", 120)
        self.cpus = _float("MPL_CPUS", 1.0)
        self.pids_limit = _int("MPL_PIDS_LIMIT", 128)
        self.tmpfs_mb = _int("MPL_TMPFS_MB", 64)
        self.max_repeat = _int("MPL_MAX_REPEAT", 20)
        self.reference_cache = _int("MPL_REFERENCE_CACHE", 5000)
        self.max_eval_seconds = _float("MPL_MAX_EVAL_SECONDS", 300)
        # AI hints (OpenAI-compatible API, default OpenRouter).
        self.ai_api_key = os.environ.get("MPL_AI_API_KEY", "")
        self.ai_base_url = os.environ.get("MPL_AI_BASE_URL", "https://openrouter.ai/api/v1")
        self.ai_model = os.environ.get("MPL_AI_MODEL", "deepseek/deepseek-v4.1-flash")
        self.ai_timeout = _float("MPL_AI_TIMEOUT", 45)
        self.ai_max_tokens = _int("MPL_AI_MAX_TOKENS", 700)


settings = Settings()
