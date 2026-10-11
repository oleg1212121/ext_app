"""Unit tests for per-language splitter engine resolution.

Covers the resolution contract that lets a new language split out of the box:

1. The global engine still serves the languages it has an entry for
   (en, ru under the live pysbd global).
2. A language the global engine cannot serve (pysbd has no ``be`` entry) —
   and unknown codes — fall back to the rule-based engine automatically
   instead of erroring at construction time.
3. An explicit ``SPLITTER_ENGINE_OVERRIDES`` entry wins, including the
   redundant case and the deliberate pin onto an engine that cannot serve
   the language (operator's call; construction surfaces the error).
4. Override parsing is whitespace-tolerant and silently ignores malformed
   entries and unknown engine names — a typo must never take a language down.

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/splitting/test_engine_resolution.py -q
"""

import pytest

from ai.config import parse_engine_overrides, resolve_splitter_engine
from ai.splitting.sentence_splitter import SUPPORTED_LANGUAGES
from ai.splitting.typed_splitter import TypedSentenceSplitter

# Preconditions from the environment this contract is about: pysbd ships an
# entry for en/ru/pl but NOT for be. If a future pysbd adds Belarusian the
# fallback for be simply stops triggering; these guards make that visible.
assert "en" in SUPPORTED_LANGUAGES, "pysbd must know 'en' for these tests"
assert "ru" in SUPPORTED_LANGUAGES, "pysbd must know 'ru' for these tests"
assert "pl" in SUPPORTED_LANGUAGES, "pysbd must know 'pl' for these tests"
assert "be" not in SUPPORTED_LANGUAGES, "these tests assume pysbd lacks 'be'"


def test_global_engine_still_serves_its_languages():
    assert resolve_splitter_engine("en", "pysbd", {}) == "pysbd"
    assert resolve_splitter_engine("ru", "pysbd", {}) == "pysbd"
    assert resolve_splitter_engine("en", "razdel", {}) == "razdel"
    assert resolve_splitter_engine("ru", "razdel", {}) == "razdel"


def test_unservable_language_falls_back_to_razdel():
    # pysbd has no Belarusian entry — today this fails the split request
    # outright; the resolver substitutes the rule-based engine.
    assert resolve_splitter_engine("be", "pysbd", {}) == "razdel"


def test_unknown_code_falls_back_instead_of_erroring():
    # Laravel validates codes against the languages registry; python side
    # degrades gracefully either way.
    assert resolve_splitter_engine("zz", "pysbd", {}) == "razdel"
    assert resolve_splitter_engine("zz", "razdel", {}) == "razdel"


def test_explicit_override_wins():
    # A language the global engine CAN serve can still be pinned elsewhere.
    assert resolve_splitter_engine("pl", "razdel", {"pl": "pysbd"}) == "pysbd"
    # Redundant override is a no-op.
    assert resolve_splitter_engine("be", "razdel", {"be": "razdel"}) == "razdel"
    assert resolve_splitter_engine("ru", "pysbd", {"ru": "pysbd"}) == "pysbd"
    # The fallback does not overrule an explicit pin: be=pysbd is the
    # operator's call, and the construction error (ValueError -> 400) is the
    # honest outcome.
    assert resolve_splitter_engine("be", "pysbd", {"be": "pysbd"}) == "pysbd"


def test_override_parsing():
    assert parse_engine_overrides(None) == {}
    assert parse_engine_overrides("") == {}
    assert parse_engine_overrides("   ") == {}
    assert parse_engine_overrides("be=razdel") == {"be": "razdel"}
    # Whitespace-tolerant, trailing comma, mixed case.
    assert parse_engine_overrides("  be = razdel , PL = pysbd ,") == {
        "be": "razdel",
        "pl": "pysbd",
    }
    # Malformed entries are ignored, not fatal.
    assert parse_engine_overrides("be") == {}
    assert parse_engine_overrides("=razdel") == {}
    assert parse_engine_overrides("be=") == {}
    assert parse_engine_overrides("be=razdel,zzz") == {"be": "razdel"}
    # Unknown engine values are ignored (fall through to global resolution).
    assert parse_engine_overrides("be=weird") == {}
    assert parse_engine_overrides("ru=weird,be=razdel") == {"be": "razdel"}


def test_typed_splitter_resolves_per_language(env_file):
    """TypedSentenceSplitter picks the engine per language, not globally."""
    # config's live accessors read the env FILE first, so steer the resolution
    # through the writable stand-in (os.environ alone cannot override it in
    # the container).
    env_file("SPLITTER_ENGINE=pysbd\n")
    # pysbd-served language keeps the global engine; Belarusian falls back to
    # the rule-based engine instead of failing to construct.
    assert TypedSentenceSplitter("ru").backend == "pysbd"
    assert TypedSentenceSplitter("be").backend == "razdel"
    # An explicit backend argument still bypasses resolution (regression
    # scripts use it to exercise both engines) — and a deliberate pin onto an
    # engine that cannot serve the language raises at construction, which the
    # /split endpoint maps to HTTP 400.
    with pytest.raises(ValueError):
        TypedSentenceSplitter("be", backend="pysbd")
