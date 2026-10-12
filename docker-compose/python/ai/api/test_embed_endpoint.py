"""Wire-contract tests for POST /embed — the seam Laravel's PythonClient
consumes for TextSignatureService.

The signature service is planted on app.state (the exact seam ModelCache
caches through), so no BGE-M3 weights are needed.

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/api/test_embed_endpoint.py -q
"""

import pytest

pytest.importorskip("fastapi")

from ai import config
from ai.main import app


class FakeSignature:
    """Stand-in TextSignature: returns a fixed unit vector, records calls."""

    def __init__(self, dim: int = 4):
        self.dim = dim
        self.calls: list[tuple[str, str]] = []

    def generate(self, text: str, language: str = "en") -> list[float]:
        self.calls.append((text, language))
        return [1.0] * self.dim


class BrokenState:
    """app.state stand-in whose attributes blow up on access, the way a
    failed lazy load propagates out of ModelCache.signature_service()."""

    def __getattr__(self, name):
        raise RuntimeError("weights missing")


def test_embed_returns_vector_and_passes_language(api_client):
    service = FakeSignature(dim=4)
    app.state.signature = service

    response = api_client.post(
        "/embed", json={"text": "Hello world.", "language": "ru"}
    )

    assert response.status_code == 200, response.text
    assert response.json() == {"vector": [1.0, 1.0, 1.0, 1.0]}
    assert service.calls == [("Hello world.", "ru")]


def test_embed_empty_text_is_rejected(api_client):
    response = api_client.post("/embed", json={"text": "", "language": "en"})
    assert response.status_code == 422


def test_embed_overlong_text_is_rejected(api_client):
    response = api_client.post(
        "/embed", json={"text": "a" * (config.EMBED_MAX_TEXT_LENGTH + 1)}
    )
    assert response.status_code == 422


def test_embed_model_failure_is_503(api_client, monkeypatch):
    monkeypatch.setattr(app, "state", BrokenState())

    response = api_client.post("/embed", json={"text": "Hello."})

    assert response.status_code == 503
    assert response.json()["detail"] == "Model not loaded: weights missing"
