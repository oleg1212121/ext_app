"""Regression tests for the /split endpoint's per-language engine cache (plain python, no pytest).

The endpoint caches one ``TypedSentenceSplitter`` per language and rebuilds it
when its backend no longer matches the *resolved* engine for that language —
not the raw global one. That distinction is the whole point of per-language
resolution: with the global engine pysbd, a Belarusian request resolves to
razdel, so a razdel-backed cached instance must be REUSED (not rebuilt into a
ValueError) and a stale pysbd-backed one must be REPLACED.

Runs only where fastapi is installed (the service container):

    docker exec ext_python python /app/ai/api/test_split_endpoint.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from fastapi.testclient import TestClient

from ai import config
from ai.main import app

client = TestClient(app)


class FakeSplitter:
    """Stand-in cached splitter whose identity/markers reveal reuse vs rebuild."""

    def __init__(self, backend: str) -> None:
        self.backend = backend

    def split(self, text: str, finalize: bool = False):
        return [{"content": "MARKER-from-fake", "type": "sentence"}], ""


def _post(language: str, text: str):
    return client.post(
        "/split", json={"language": language, "text": text, "finalize": True}
    )


def _reset_cache() -> None:
    if hasattr(app.state, "splitters"):
        del app.state.splitters


def _other(engine: str) -> str:
    return "pysbd" if engine == "razdel" else "razdel"


def check_belarusian_splits_without_error() -> None:
    _reset_cache()
    response = _post("be", "Першае сказанне. Другое сказанне.")
    assert response.status_code == 200, f"be split must not fail: {response.status_code} {response.text}"
    assert len(response.json()["sentences"]) == 2


def check_cache_reuses_resolved_backend() -> None:
    # For EVERY language (fallback ones and globally-served ones alike), a
    # cached instance whose backend equals the RESOLVED engine is reused.
    for language in ("be", "en", "ru"):
        _reset_cache()
        resolved = config.splitter_engine_for(language)
        planted = FakeSplitter(backend=resolved)
        app.state.splitters = {language: planted}

        response = _post(language, "Первое. Второе.")

        assert response.status_code == 200
        assert response.json()["sentences"][0]["content"] == "MARKER-from-fake"
        assert app.state.splitters[language] is planted, (
            f"{language}: cached {resolved} instance must be reused (resolved engine)"
        )


def check_cache_replaces_stale_backend() -> None:
    # A cached instance whose backend differs from the resolved engine is
    # rebuilt — including the case where it still matches the raw global
    # engine but not the per-language resolution.
    for language in ("be", "en", "ru"):
        _reset_cache()
        resolved = config.splitter_engine_for(language)
        stale = FakeSplitter(backend=_other(resolved))
        app.state.splitters = {language: stale}

        response = _post(language, "Первое. Второе.")

        assert response.status_code == 200
        assert "MARKER-from-fake" not in response.text
        assert app.state.splitters[language] is not stale, (
            f"{language}: stale {_other(resolved)} instance must be replaced"
        )


def main() -> int:
    checks = [
        check_belarusian_splits_without_error,
        check_cache_reuses_resolved_backend,
        check_cache_replaces_stale_backend,
    ]
    failures = 0
    for check in checks:
        try:
            check()
            print(f"PASS {check.__name__}")
        except AssertionError as exc:
            failures += 1
            print(f"FAIL {check.__name__}: {exc}")
    print(f"{len(checks) - failures}/{len(checks)} checks passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
