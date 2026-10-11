"""Tests for GET /health — the readiness probe prod polls (ext-python.service).

Health never 503s: with a loadable model it reports ok + dimension; with a
broken/unavailable model it reports "loading" with the failure detail.

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/api/test_health.py -q
"""

import pytest

pytest.importorskip("fastapi")

from ai.main import app
from ai.conftest import N, StubModel


class BrokenState:
    """app.state stand-in whose attributes blow up on access, the way a
    failed lazy load propagates out of ModelCache.signature_model()."""

    def __getattr__(self, name):
        raise RuntimeError("weights missing")


def test_health_ok_reports_model_dimension(api_client):
    app.state.model = StubModel({N("x"): [1, 0, 0, 0]})

    response = api_client.get("/health")

    assert response.status_code == 200
    assert response.json() == {"status": "ok", "dim": 4}


def test_health_reports_loading_when_model_is_unavailable(api_client, monkeypatch):
    monkeypatch.setattr(app, "state", BrokenState())

    response = api_client.get("/health")

    assert response.status_code == 200
    body = response.json()
    assert body["status"] == "loading"
    assert "weights missing" in body["detail"]
