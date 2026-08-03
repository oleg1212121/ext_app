"""Lazy model loader.

Models are NOT loaded in the FastAPI lifespan so that `uvicorn --reload` can
restart the app in ~1-2s after a source edit without re-loading the multi-GB
model files. The first endpoint call that needs a model pays the load; later
calls reuse the cached instance on `app.state`.

Swapping models at runtime:
  - Edit `ALIGN_MODEL_PATH` in `docker-compose/python/.env`.
  - On the next `/align` request, `aligner_model()` re-reads the env, sees the
    new path, and (because the previous cached model was bound to the old
    path) loads the new one. To force a reload after a path change, call
    `reload_aligner()` — exposed for future admin endpoints.
"""

import logging
from pathlib import Path

from sentence_transformers import SentenceTransformer

from ai import config
from ai.signatures.text_signature import TextSignature

logger = logging.getLogger(__name__)


class ModelCache:
    def __init__(self, app_state):
        self.state = app_state

    def _get(self, key):
        return getattr(self.state, key, None)

    def _set(self, key, value):
        setattr(self.state, key, value)

    def signature_model(self) -> SentenceTransformer:
        m = self._get("model")
        if m is None:
            path = config.model_path()
            logger.info("Lazy-loading signature model: %s", path)
            m = SentenceTransformer(path)
            self._set("model", m)
            logger.info("Signature model ready (dim=%s)", m.get_embedding_dimension())
        return m

    def signature_service(self) -> TextSignature:
        svc = self._get("signature")
        if svc is None:
            svc = TextSignature(model=self.signature_model())
            self._set("signature", svc)
        return svc

    def aligner_model(self) -> SentenceTransformer:
        m = self._get("align_model")
        cached_path = self._get("align_model_path")
        current_path = config.align_model_path()
        if m is None or cached_path != current_path:
            if current_path and Path(current_path).exists():
                logger.info("Lazy-loading aligner model: %s", current_path)
                m = SentenceTransformer(current_path)
                logger.info("Aligner model ready (dim=%s)", m.get_embedding_dimension())
            else:
                logger.warning(
                    "Aligner path %s missing; falling back to signature model",
                    current_path,
                )
                m = self.signature_model()
            self._set("align_model", m)
            self._set("align_model_path", current_path)
        return m

    def reload_aligner(self) -> SentenceTransformer:
        """Drop the cached aligner so the next call re-reads env and re-loads."""
        self._set("align_model", None)
        self._set("align_model_path", None)
        return self.aligner_model()