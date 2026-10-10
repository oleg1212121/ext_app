"""Unit tests for per-language splitter engine resolution (plain python, no pytest).

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

Run from anywhere (adds the package root to sys.path):

    docker-compose/python/ai/venv/bin/python ai/splitting/test_engine_resolution.py
    docker exec ext_python python /app/ai/splitting/test_engine_resolution.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from ai.config import parse_engine_overrides, resolve_splitter_engine
from ai.splitting.sentence_splitter import SUPPORTED_LANGUAGES

# Preconditions from the environment this contract is about: pysbd ships an
# entry for en/ru/pl but NOT for be. If a future pysbd adds Belarusian the
# fallback for be simply stops triggering; these guards make that visible.
assert "en" in SUPPORTED_LANGUAGES, "pysbd must know 'en' for these tests"
assert "ru" in SUPPORTED_LANGUAGES, "pysbd must know 'ru' for these tests"
assert "pl" in SUPPORTED_LANGUAGES, "pysbd must know 'pl' for these tests"
assert "be" not in SUPPORTED_LANGUAGES, "these tests assume pysbd lacks 'be'"


def check_global_engine_still_serves_its_languages() -> None:
    assert resolve_splitter_engine("en", "pysbd", {}) == "pysbd"
    assert resolve_splitter_engine("ru", "pysbd", {}) == "pysbd"
    assert resolve_splitter_engine("en", "razdel", {}) == "razdel"
    assert resolve_splitter_engine("ru", "razdel", {}) == "razdel"


def check_unservable_language_falls_back_to_razdel() -> None:
    # pysbd has no Belarusian entry — today this fails the split request
    # outright; the resolver substitutes the rule-based engine.
    assert resolve_splitter_engine("be", "pysbd", {}) == "razdel"


def check_unknown_code_falls_back_instead_of_erroring() -> None:
    # Laravel validates codes against the languages registry; python side
    # degrades gracefully either way.
    assert resolve_splitter_engine("zz", "pysbd", {}) == "razdel"
    assert resolve_splitter_engine("zz", "razdel", {}) == "razdel"


def check_explicit_override_wins() -> None:
    # A language the global engine CAN serve can still be pinned elsewhere.
    assert resolve_splitter_engine("pl", "razdel", {"pl": "pysbd"}) == "pysbd"
    # Redundant override is a no-op.
    assert resolve_splitter_engine("be", "razdel", {"be": "razdel"}) == "razdel"
    assert resolve_splitter_engine("ru", "pysbd", {"ru": "pysbd"}) == "pysbd"
    # The fallback does not overrule an explicit pin: be=pysbd is the
    # operator's call, and the construction error (ValueError -> 400) is the
    # honest outcome.
    assert resolve_splitter_engine("be", "pysbd", {"be": "pysbd"}) == "pysbd"


def check_override_parsing() -> None:
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


def check_typed_splitter_resolves_per_language() -> None:
    """TypedSentenceSplitter picks the engine per language, not globally."""
    import os

    from ai.splitting.typed_splitter import TypedSentenceSplitter

    os.environ["SPLITTER_ENGINE"] = "pysbd"
    os.environ.pop("SPLITTER_ENGINE_OVERRIDES", None)
    # pysbd-served language keeps the global engine; Belarusian falls back to
    # the rule-based engine instead of failing to construct.
    assert TypedSentenceSplitter("ru").backend == "pysbd"
    assert TypedSentenceSplitter("be").backend == "razdel"
    # An explicit backend argument still bypasses resolution (regression
    # scripts use it to exercise both engines) — and a deliberate pin onto an
    # engine that cannot serve the language raises at construction, which the
    # /split endpoint maps to HTTP 400.
    try:
        TypedSentenceSplitter("be", backend="pysbd")
    except ValueError:
        pass
    else:
        raise AssertionError("be + explicit pysbd pin must raise ValueError")


def main() -> int:
    checks = [
        check_global_engine_still_serves_its_languages,
        check_unservable_language_falls_back_to_razdel,
        check_unknown_code_falls_back_instead_of_erroring,
        check_explicit_override_wins,
        check_override_parsing,
        check_typed_splitter_resolves_per_language,
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
