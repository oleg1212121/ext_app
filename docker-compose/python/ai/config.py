"""Runtime configuration.

Two layers of env access:

1. Import-time constants (validation limits like EMBED_MAX_TEXT_LENGTH, plus the
   stable MODEL_PATH/ALIGN_MODEL_PATH defaults). These read `os.environ`, which
   docker-compose's `env_file:` populates once at container start. They almost
   never change, so a fast `docker compose up -d python` recreate is acceptable
   on the rare occasion you tweak one.

2. Live per-request accessors (`align_default_window()`, `align_primary_window()`,
   `align_default_threshold()`, `align_model_path()`). These re-read the
   bind-mounted `/app/.env` file on every call. Edits to those keys apply on the next request with no container
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
SPLIT_MAX_TEXT_LENGTH = _env_int("SPLIT_MAX_TEXT_LENGTH", 5242880)


def splitter_engine() -> str:
    """Global sentence splitter backend: 'razdel' (default) or 'pysbd'."""
    value = (_live_env("SPLITTER_ENGINE", "razdel") or "razdel").strip().lower()
    return value if value in ("razdel", "pysbd") else "razdel"


def parse_engine_overrides(raw: str | None) -> dict[str, str]:
    """Parse ``SPLITTER_ENGINE_OVERRIDES`` — per-language engine pins.

    Format: comma-separated ``<language>=<engine>`` entries, e.g.
    ``be=razdel,pl=pysbd``. Whitespace-tolerant; keys and values are
    lowercased. Defensive by design: malformed entries (no ``=``, empty
    key/value) and unknown engine names are *ignored*, never raised — a typo
    in an override must not take a language's splitting down; the entry just
    falls through to the global resolution.
    """
    if not raw:
        return {}
    out: dict[str, str] = {}
    for entry in raw.split(","):
        key, sep, value = entry.strip().partition("=")
        if not sep:
            continue
        key = key.strip().lower()
        value = value.strip().lower()
        if not key or value not in ("razdel", "pysbd"):
            continue
        out[key] = value
    return out


def _pysbd_supported_languages() -> set[str]:
    """Language codes pysbd's engine has an entry for.

    Imported lazily (keeps config free of import cycles) and defensively: if
    pysbd is broken/unimportable the set is empty, so every language falls
    back to the rule-based engine instead of the worker crashing.
    """
    try:
        from pysbd.languages import LANGUAGE_CODES

        return set(LANGUAGE_CODES.keys())
    except Exception:
        return set()


def resolve_splitter_engine(
    language: str, global_engine: str, overrides: dict[str, str]
) -> str:
    """Which engine splits *language* (pure; see ``splitter_engine_for``).

    Resolution order: an explicit override wins unconditionally (including a
    deliberate pin onto an engine that cannot serve the language — the
    construction error is the honest outcome); otherwise the global engine;
    except when the global engine cannot serve the language — pysbd without
    a pysbd language entry — the rule-based engine (razdel) is substituted,
    so unregistered/unknown codes fall back instead of erroring (Laravel
    validates codes against the languages registry). razdel serves any code.
    """
    override = overrides.get((language or "").strip().lower())
    if override:
        return override
    if global_engine == "pysbd" and language not in _pysbd_supported_languages():
        return "razdel"
    return global_engine


def splitter_engine_for(language: str) -> str:
    """Per-language splitter engine, resolved from the live env on each call."""
    return resolve_splitter_engine(language, splitter_engine(), parse_engine_overrides(_live_env("SPLITTER_ENGINE_OVERRIDES")))
ALIGN_MAX_SENTENCES = _env_int("ALIGN_MAX_SENTENCES", 500)
ALIGN_MAX_WINDOW = _env_int("ALIGN_MAX_WINDOW", 8)
ENRICH_MAX_SENTENCES = _env_int("ENRICH_MAX_SENTENCES", 75)


# --- Live per-request accessors (edits to /app/.env apply without restart) ---
def align_default_window() -> int:
    return _live_int("ALIGN_DEFAULT_WINDOW", 3)


def align_primary_window() -> int:
    """Greedy gap-search ladder: compare steps 1..primary as one set, then
    widen one step per side up to max_window if nothing clears the bar."""
    return _live_int("ALIGN_PRIMARY_WINDOW", 3)


def align_default_threshold() -> float:
    return _live_float("ALIGN_DEFAULT_THRESHOLD", 0.4)


def align_max_total_span() -> int:
    return _live_int("ALIGN_MAX_TOTAL_SPAN", 6)


def align_skip_penalty() -> float:
    return _live_float("ALIGN_SKIP_PENALTY", -0.5)


def align_algorithm() -> str:
    value = (_live_env("ALIGN_ALGORITHM", "greedy") or "greedy").strip().lower()
    return value if value in ("greedy", "dp") else "greedy"


def align_anchor_threshold() -> float:
    return _live_float("ALIGN_ANCHOR_THRESHOLD", 0.6)


def align_merge_margin() -> float:
    """Greedy orphan-merge: extend a match over a single-sided orphan run
    only if the pooled window beats the match's score by at least this margin."""
    return _live_float("ALIGN_MERGE_MARGIN", 0.02)


def align_rescue_threshold() -> float:
    """Greedy gap walk: a mutual-best 1:1 (each sentence's best in-band
    partner is the other) scoring below similarity_threshold is still emitted
    as a match carrying its true score when it clears this bar, so a genuine
    pair just under the match bar lands in the PHP side's Needs review as a
    low-similarity row instead of two single-sided skip rows. 0 disables."""
    return _live_float("ALIGN_RESCUE_THRESHOLD", 0.45)


def align_high_confidence() -> float:
    """1:1 prepass anchor bar: mutually-best cells at/above this cosine are
    locked as committed matches that split the chunk into sub-pools."""
    return _live_float("ALIGN_HIGH_CONFIDENCE", 0.9)


def align_max_anchor_distance() -> int | None:
    """Prepass anchor distance cap: a high-confidence mutually-best 1:1 more
    than this many cells off the expected length-ratio diagonal is never
    locked as a prepass anchor, so a short-heading cognate far downstream
    (e.g. "Introduction" -> the part heading "Введение") cannot teleport the
    cursor past a whole translated block. <= 0 disables the cap (unbanded
    prepass)."""
    v = _live_int("ALIGN_MAX_ANCHOR_DISTANCE", 30)
    return v if v > 0 else None


def align_band_width() -> int | None:
    """Diagonal band half-width around the expected length-ratio diagonal
    (match edges restricted to the band). None -> derived per chunk as
    max(2, max_window)."""
    v = _live_env("ALIGN_BAND_WIDTH")
    return int(v) if v not in ("", None) else None


def align_window_embed() -> str:
    """Multi-sentence window embedding mode: "aggregate" (length-weighted mean
    of per-sentence vectors, L2-normalized) or "joined" (today's join-then-
    embed). Coerces anything else to "aggregate"."""
    value = (_live_env("ALIGN_WINDOW_EMBED", "aggregate") or "aggregate").strip().lower()
    return value if value in ("aggregate", "joined") else "aggregate"


def model_path() -> str:
    return _live_env("MODEL_PATH", str(BASE_DIR.parent / "models" / "labse"))


def align_model_path() -> str:
    return _live_env("ALIGN_MODEL_PATH", str(BASE_DIR.parent / "models" / "minilm"))


# Backwards-compatible import-time constants (still used by code that wants the
# startup default, e.g. validation Field max_length). For live model swaps,
# use the accessor functions above.
MODEL_PATH = os.environ.get("MODEL_PATH", str(BASE_DIR.parent / "models" / "labse"))
ALIGN_MODEL_PATH = os.environ.get("ALIGN_MODEL_PATH", str(BASE_DIR.parent / "models" / "minilm"))


def optional_torch_threads() -> None:
    """Pin torch's CPU thread count if TORCH_NUM_THREADS is set; else autodetect."""
    n = os.environ.get("TORCH_NUM_THREADS", "").strip()
    if n:
        import torch

        torch.set_num_threads(int(n))