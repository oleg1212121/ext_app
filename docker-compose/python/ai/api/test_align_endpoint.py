"""Wire-contract tests for POST /align — the seam Laravel's PythonClient
consumes for SentenceAlignmentService.

Pins, against a stub aligner model (no weights):
- the response shape Laravel unwraps: {matches, unmatched_a, unmatched_b}
  with matches of {a_start, a_end, b_start, b_end, score} (mirrored by
  laravel/tests/Support/FakePythonClient.php);
- correct alignment on a clean diagonal;
- landmark pins emitted verbatim with score 1.0;
- invalid pins -> 422 (the aligner's ValueError mapped by the endpoint);
- the sentence-count cap -> 422;
- a failed model load -> 503.

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/api/test_align_endpoint.py -q
"""

import pytest

pytest.importorskip("fastapi")

from ai.main import app
from ai.conftest import N, StubModel, plant_aligner_model


class BrokenState:
    """app.state stand-in whose attributes blow up on access, the way a
    failed lazy load propagates out of ModelCache.aligner_model()."""

    def __getattr__(self, name):
        raise RuntimeError("weights missing")


def _plant(api_client, model):
    plant_aligner_model(app, model)


def _assert_matches(body_matches, expected):
    """Spans must match exactly; scores are float32-derived (approx)."""
    assert len(body_matches) == len(expected), body_matches
    for got, want in zip(body_matches, expected):
        assert (got["a_start"], got["a_end"], got["b_start"], got["b_end"]) == (
            want["a_start"],
            want["a_end"],
            want["b_start"],
            want["b_end"],
        ), (got, want)
        assert got["score"] == pytest.approx(want["score"], abs=1e-4), (got, want)


def test_clean_diagonal_matches_with_wire_shape(api_client):
    _plant(api_client.app, StubModel({
        N("Alpha1"): [1, 0, 0, 0],
        N("Beta1"): [1, 0, 0, 0],
        N("Alpha2"): [0, 1, 0, 0],
        N("Beta2"): [0, 1, 0, 0],
        N("Alpha3"): [0, 0, 1, 0],
        N("Beta3"): [0, 0, 1, 0],
        N("AlphaX"): [0, 0, 0, 1],
    }))

    response = api_client.post(
        "/align",
        json={
            "a_sentences": ["Alpha1", "Alpha2", "Alpha3", "AlphaX"],
            "b_sentences": ["Beta1", "Beta2", "Beta3"],
            "similarity_threshold": 0.55,
            "algorithm": "greedy",
        },
    )

    assert response.status_code == 200, response.text
    body = response.json()
    # The exact envelope Laravel's PythonClient::align() unwraps.
    assert set(body.keys()) == {"matches", "unmatched_a", "unmatched_b"}
    _assert_matches(body["matches"], [
        {"a_start": 0, "a_end": 1, "b_start": 0, "b_end": 1, "score": 1.0},
        {"a_start": 1, "a_end": 2, "b_start": 1, "b_end": 2, "score": 1.0},
        {"a_start": 2, "a_end": 3, "b_start": 2, "b_end": 3, "score": 1.0},
    ])
    assert body["unmatched_a"] == [3]
    assert body["unmatched_b"] == []
    for match in body["matches"]:
        assert set(match.keys()) == {"a_start", "a_end", "b_start", "b_end", "score"}


def test_landmark_pin_is_emitted_verbatim_with_score_one(api_client):
    _plant(api_client.app, StubModel({
        N("One"): [1, 0, 0, 0, 0],
        N("A"): [0.8, 0.6, 0, 0, 0],
        N("Two"): [0, 0, 1, 0, 0],
        N("Two'"): [0, 0, 1, 0, 0],
        N("Three"): [0, 0, 0, 0.85, 0.5268],
        N("C"): [0, 0, 0, 1, 0],
    }))

    response = api_client.post(
        "/align",
        json={
            "a_sentences": ["One", "Two", "Three"],
            "b_sentences": ["A", "Two'", "C"],
            "similarity_threshold": 0.55,
            "algorithm": "greedy",
            "landmarks": [{"a_start": 1, "a_end": 2, "b_start": 1, "b_end": 2}],
        },
    )

    assert response.status_code == 200, response.text
    body = response.json()
    _assert_matches(body["matches"], [
        {"a_start": 0, "a_end": 1, "b_start": 0, "b_end": 1, "score": 0.8},
        {"a_start": 1, "a_end": 2, "b_start": 1, "b_end": 2, "score": 1.0},
        {"a_start": 2, "a_end": 3, "b_start": 2, "b_end": 3, "score": 0.85},
    ])
    assert body["unmatched_a"] == [] and body["unmatched_b"] == []


def test_invalid_landmark_pins_are_422(api_client):
    _plant(api_client.app, StubModel({}))

    for landmarks in (
        [{"a_start": 0, "a_end": 3, "b_start": 0, "b_end": 1}],  # out of range
        [{"a_start": 1, "a_end": 1, "b_start": 0, "b_end": 1}],  # zero length
        [  # crossing
            {"a_start": 0, "a_end": 1, "b_start": 1, "b_end": 2},
            {"a_start": 1, "a_end": 2, "b_start": 0, "b_end": 1},
        ],
    ):
        response = api_client.post(
            "/align",
            json={
                "a_sentences": ["One", "Two"],
                "b_sentences": ["A", "Two'"],
                "landmarks": landmarks,
            },
        )
        assert response.status_code == 422, (landmarks, response.status_code)
        assert "detail" in response.json()


def test_sentence_cap_is_422(api_client):
    response = api_client.post(
        "/align",
        json={
            "a_sentences": ["One"] * 501,
            "b_sentences": ["One"] * 501,
        },
    )
    assert response.status_code == 422


def test_model_failure_is_503(api_client, monkeypatch):
    monkeypatch.setattr(app, "state", BrokenState())

    response = api_client.post(
        "/align",
        json={"a_sentences": ["One"], "b_sentences": ["A"]},
    )

    assert response.status_code == 503
    assert response.json()["detail"] == "Model not loaded: weights missing"
