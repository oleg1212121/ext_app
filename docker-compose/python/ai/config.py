import os
from pathlib import Path

BASE_DIR = Path(__file__).parent

# Path to a local sentence-transformers model directory (mounted volume in docker).
MODEL_PATH = os.environ.get("MODEL_PATH", str(BASE_DIR / "bge_m3_local"))

# API limits (mirrors of the contracts the Laravel side relies on).
EMBED_MAX_TEXT_LENGTH = int(os.environ.get("EMBED_MAX_TEXT_LENGTH", "1000000"))
EMBED_BATCH_MAX_TEXTS = int(os.environ.get("EMBED_BATCH_MAX_TEXTS", "100"))
COSINE_MAX_CANDIDATES = int(os.environ.get("COSINE_MAX_CANDIDATES", "2000"))
SPLIT_MAX_TEXT_LENGTH = int(os.environ.get("SPLIT_MAX_TEXT_LENGTH", "5242880"))
ALIGN_MAX_SENTENCES = int(os.environ.get("ALIGN_MAX_SENTENCES", "500"))
ALIGN_MAX_WINDOW = int(os.environ.get("ALIGN_MAX_WINDOW", "8"))

# Default alignment behaviour (overridable per request).
ALIGN_DEFAULT_WINDOW = int(os.environ.get("ALIGN_DEFAULT_WINDOW", "3"))
ALIGN_DEFAULT_THRESHOLD = float(os.environ.get("ALIGN_DEFAULT_THRESHOLD", "0.4"))
