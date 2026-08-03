"""Runtime configuration.

Two layers of env access:

1. Import-time constants (validation limits like EMBED_MAX_TEXT_LENGTH, plus the
   stable MODEL_PATH/ALIGN_MODEL_PATH defaults). These read `os.environ`, which
   docker-compose's `env_file:` populates once at container start. They almost
   never change, so a fast `docker compose up -d python` recreate is acceptable
   on the rare occasion you tweak one.

2. Live per-request accessors (`align_default_window()`, `align_default_threshold()`,
   `align_model_path()`). These re-read the bind-mounted `/app/.env` file on every
   call. Edits to those keys apply on the next request with no container
   recreate or restart — the file is mounted read-only at /app/.env.
"""

import os
from pathlib import Path

BASE_DIR = Path(__file__).parent

# Where the live .env is bind-mounted inside the container.
ENV_FILE = Path(os.environ.get("ENV_FILE", "/app/env/.env"))


def _parse_env_file(path: Path) -> dict[str, str]:
    """Tiny .env parser (KEY=VALUE, ignores blank/# lines and surrounding quotes)."""
    out: dict[str, str] = {}
    if not path.exists():
        return out
    try:
        for raw in path.read_text(encoding="utf-8").splitlines():
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, value = line.partition("=")
            key = key.strip()
            value = value.strip().strip('"').strip("'")
            out[key] = value
    except OSError:
        pass
    return out


def _live_env(name: str, default: str | None = None) -> str | None:
    """Read a key from the bind-mounted .env first, then os.environ."""
    file_val = _parse_env_file(ENV_FILE).get(name)
    if file_val is not None:
        return file_val
    return os.environ.get(name, default)


def _live_int(name: str, default: int) -> int:
    v = _live_env(name)
    return int(v) if v not in ("", None) else default


def _live_float(name: str, default: float) -> float:
    v = _live_env(name)
    return float(v) if v not in ("", None) else default


# --- Import-time constants (stable limits; read from os.environ once) ---
def _env_int(name: str, default: int) -> int:
    v = os.environ.get(name)
    return int(v) if v not in ("", None) else default


EMBED_MAX_TEXT_LENGTH = _env_int("EMBED_MAX_TEXT_LENGTH", 1000000)
EMBED_BATCH_MAX_TEXTS = _env_int("EMBED_BATCH_MAX_TEXTS", 100)
COSINE_MAX_CANDIDATES = _env_int("COSINE_MAX_CANDIDATES", 2000)
SPLIT_MAX_TEXT_LENGTH = _env_int("SPLIT_MAX_TEXT_LENGTH", 5242880)
ALIGN_MAX_SENTENCES = _env_int("ALIGN_MAX_SENTENCES", 500)
ALIGN_MAX_WINDOW = _env_int("ALIGN_MAX_WINDOW", 8)


# --- Live per-request accessors (edits to /app/.env apply without restart) ---
def align_default_window() -> int:
    return _live_int("ALIGN_DEFAULT_WINDOW", 3)


def align_default_threshold() -> float:
    return _live_float("ALIGN_DEFAULT_THRESHOLD", 0.4)


def model_path() -> str:
    return _live_env("MODEL_PATH", str(BASE_DIR.parent / "models" / "bge_m3"))


def align_model_path() -> str:
    return _live_env("ALIGN_MODEL_PATH", str(BASE_DIR.parent / "models" / "minilm"))


# Backwards-compatible import-time constants (still used by code that wants the
# startup default, e.g. validation Field max_length). For live model swaps,
# use the accessor functions above.
MODEL_PATH = os.environ.get("MODEL_PATH", str(BASE_DIR.parent / "models" / "bge_m3"))
ALIGN_MODEL_PATH = os.environ.get("ALIGN_MODEL_PATH", str(BASE_DIR.parent / "models" / "minilm"))


def optional_torch_threads() -> None:
    """Pin torch's CPU thread count if TORCH_NUM_THREADS is set; else autodetect."""
    n = os.environ.get("TORCH_NUM_THREADS", "").strip()
    if n:
        import torch

        torch.set_num_threads(int(n))