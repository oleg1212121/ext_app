"""Regression tests for the /split endpoint's per-language engine cache.

The endpoint caches one ``TypedSentenceSplitter`` per language and rebuilds it
when its backend no longer matches the *resolved* engine for that language —
not the raw global one. That distinction is the whole point of per-language
resolution: with the global engine pysbd, a Belarusian request resolves to
razdel, so a razdel-backed cached instance must be REUSED (not rebuilt into a
ValueError) and a stale pysbd-backed one must be REPLACED.

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/api/test_split_endpoint.py -q
"""

import pytest

pytest.importorskip("fastapi")

from ai import config
from ai.main import app


class FakeSplitter:
    """Stand-in cached splitter whose identity/markers reveal reuse vs rebuild."""

    def __init__(self, backend: str) -> None:
        self.backend = backend

    def split(self, text: str, finalize: bool = False):
        return [{"content": "MARKER-from-fake", "type": "sentence"}], ""


def _post(api_client, language: str, text: str):
    return api_client.post(
        "/split", json={"language": language, "text": text, "finalize": True}
    )


def _other(engine: str) -> str:
    return "pysbd" if engine == "razdel" else "razdel"


def test_belarusian_splits_without_error(api_client):
    response = _post(api_client, "be", "Першае сказанне. Другое сказанне.")
    assert response.status_code == 200, (
        f"be split must not fail: {response.status_code} {response.text}"
    )
    assert len(response.json()["sentences"]) == 2


def test_cache_reuses_resolved_backend(api_client):
    # For EVERY language (fallback ones and globally-served ones alike), a
    # cached instance whose backend equals the RESOLVED engine is reused.
    for language in ("be", "en", "ru"):
        resolved = config.splitter_engine_for(language)
        planted = FakeSplitter(backend=resolved)
        app.state.splitters = {language: planted}

        response = _post(api_client, language, "Первое. Второе.")

        assert response.status_code == 200
        assert response.json()["sentences"][0]["content"] == "MARKER-from-fake"
        assert app.state.splitters[language] is planted, (
            f"{language}: cached {resolved} instance must be reused (resolved engine)"
        )


def test_cache_replaces_stale_backend(api_client):
    # A cached instance whose backend differs from the resolved engine is
    # rebuilt — including the case where it still matches the raw global
    # engine but not the per-language resolution.
    for language in ("be", "en", "ru"):
        resolved = config.splitter_engine_for(language)
        stale = FakeSplitter(backend=_other(resolved))
        app.state.splitters = {language: stale}

        response = _post(api_client, language, "Первое. Второе.")

        assert response.status_code == 200
        assert "MARKER-from-fake" not in response.text
        assert app.state.splitters[language].backend == resolved, (
            f"{language}: stale {_other(resolved)} instance must be replaced"
        )
