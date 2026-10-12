"""Shared fixtures for the python ML service test suite.

Canonical run (inside the service container):

    docker exec ext_python python -m pytest /app/ai -q

No test needs model weights: the aligner/embedding pipeline is driven through
the deterministic :class:`StubModel`, and the api tests plant stubs on
``app.state`` the way ``ModelCache`` caches real models. Optional dependencies
(silero-stress, spaCy, the FastAPI stack) are skipped cleanly when absent so a
partial host-venv run still works.
"""

import numpy as np
import pytest
import torch

from ai import config
from ai.alignment import bilingual_aligner


# --- deterministic stub model (no weights) -----------------------------------

def normalized(vector) -> list[float]:
    return list(np.asarray(vector, dtype=float) / np.linalg.norm(vector))


def N(text: str) -> str:
    """Normalize a stub vector key exactly as the aligner does for window texts."""
    return bilingual_aligner._normalize_text(text)


class StubModel:
    """Deterministic stand-in for SentenceTransformer.

    Maps each text to a fixed (already normalized) vector, and counts encode()
    calls + texts so embedding behavior can be asserted.
    """

    def __init__(self, vectors: dict[str, list[float]]):
        self.vectors = {
            text: torch.tensor(normalized(vector), dtype=torch.float32)
            for text, vector in vectors.items()
        }
        self.encode_calls = 0
        self.encode_total = 0

    def encode(self, texts, **kwargs):
        self.encode_calls += 1
        self.encode_total += len(texts)
        return torch.stack([self.vectors[text] for text in texts])

    def get_embedding_dimension(self) -> int:
        if not self.vectors:
            return 0
        (first,) = self.vectors.values()
        return int(first.shape[0])


@pytest.fixture
def stub_model():
    """Factory fixture: ``model = stub_model({"text": [1.0, 0.0]})``."""
    return StubModel


@pytest.fixture(autouse=True)
def _reset_shared_caches():
    """Keep tests independent of execution order.

    The aligner's module-level embedding cache is keyed by ``id(model)``: a
    freed stub's recycled id would leak its cached vectors into a later test's
    encode counts. The spaCy singleton (phrasal._NLP) is deliberately NOT
    reset — it is a read-only cache and reloading en_core_web_md per test
    would dominate the runtime.
    """
    bilingual_aligner._EMBEDDING_CACHE._entries.clear()
    yield
    bilingual_aligner._EMBEDDING_CACHE._entries.clear()


@pytest.fixture(autouse=True)
def _cache_models_on(monkeypatch):
    """Model caching must be on so stubs planted on (fake) app.state ride the
    same path real models do — CACHE_MODELS=0 would send them down the load
    path (ModelCache reads os.environ, not the env file, for this key)."""
    monkeypatch.setenv("CACHE_MODELS", "1")


# --- config ------------------------------------------------------------------

@pytest.fixture
def env_file(tmp_path, monkeypatch):
    """A writable stand-in for the bind-mounted /app/env/.env.

    config's live accessors read the file layer FIRST (os.environ only as
    fallback), so inside the container os.environ alone cannot steer them.
    Returns a write() helper; ENV_FILE and the splitter env vars are restored
    after the test.
    """
    path = tmp_path / "test.env"

    def write(content: str) -> None:
        path.write_text(content, encoding="utf-8")

    monkeypatch.setattr(config, "ENV_FILE", path)
    monkeypatch.delenv("SPLITTER_ENGINE", raising=False)
    monkeypatch.delenv("SPLITTER_ENGINE_OVERRIDES", raising=False)
    return write


# --- FastAPI app -------------------------------------------------------------

@pytest.fixture
def api_client():
    """TestClient over the real app, with app.state snapshotted and restored.

    The lifespan is not run (plain TestClient), so nothing loads a model;
    tests plant stubs the way ModelCache would and the planted state is
    rolled back after each test.
    """
    from fastapi.testclient import TestClient

    from ai.main import app

    snapshot = dict(app.state._state)
    try:
        yield TestClient(app)
    finally:
        try:
            state_dict = app.state._state
        except Exception:
            # app.state was replaced during the test (e.g. a BrokenState
            # stub); monkeypatch restores the original object itself.
            return
        state_dict.clear()
        state_dict.update(snapshot)


def plant_aligner_model(app, model) -> None:
    """Plant a stub aligner model on app.state with the path stamp matching
    the live config, so ModelCache.aligner_model() reuses it instead of
    loading weights."""
    app.state.align_model = model
    app.state.align_model_path = config.align_model_path()
