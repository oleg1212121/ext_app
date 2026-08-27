#!/usr/bin/env bash
#
# install-models.sh — populate the `ai_models` named volume with the ML models
# the ext_python FastAPI service needs, so they survive container recreates
# and don't have to be re-downloaded on every `docker compose up`.
#
# Idempotent: models already present in the volume are skipped.
# Run once after `docker compose up -d` (dev or prod — same `ext_python` container).
#
# Usage:
#   bash install-models.sh
#
# Models provisioned:
#   sentence-transformers/LaBSE  -> /app/models/labse   (active: signatures + alignment)
#   BAAI/bge-m3                  -> /app/models/bge_m3  (staged for future use)

set -euo pipefail

CONTAINER="ext_python"

# --- sanity: container must be running -------------------------------------
if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
  echo "ERROR: '$CONTAINER' is not running." >&2
  echo "Start the stack first, e.g.: docker compose up -d" >&2
  exit 1
fi

# --- helpers ---------------------------------------------------------------

# has_model <dir>: true if a fully-saved SentenceTransformer exists there.
has_model() {
  docker exec "$CONTAINER" sh -c \
    "[ -f '$1/config.json' ] && ( [ -f '$1/pytorch_model.bin' ] || [ -f '$1/model.safetensors' ] )" \
    2>/dev/null
}

# download <hf_repo_id> <target_dir>: pull only if missing.
download() {
  local repo="$1" target="$2"
  if has_model "$target"; then
    echo "present : $target (skip)"
    return 0
  fi
  # HF_HUB_OFFLINE is 1 at runtime; override for this exec only.
  echo "download: $repo -> $target"
  docker exec -e HF_HUB_OFFLINE=0 "$CONTAINER" \
    python /app/scripts/download_model.py "$repo" "$target"
}

# --- models ----------------------------------------------------------------
download sentence-transformers/LaBSE /app/models/labse
download BAAI/bge-m3                 /app/models/bge_m3

# --- verify both load OFFLINE (proves they are local, not fetched) ---------
echo "verify : loading both models with HF_HUB_OFFLINE=1"
docker exec -e HF_HUB_OFFLINE=1 "$CONTAINER" python -c \
  "from sentence_transformers import SentenceTransformer as S; \
   S('/app/models/labse'); S('/app/models/bge_m3'); \
   print('models OK')"

echo "done."
