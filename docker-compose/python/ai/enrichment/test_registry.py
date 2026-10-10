"""Unit tests for the enricher module registry (plain python, no pytest).

Covers the contract that lets a future analysis plug in as one module plus
one registry entry (ticket 02 of the belarusian-language feature):

1. Lookup: the registry serves exactly the registered keys; each adapter
   exposes the uniform shape (key, version, prepare, apply) and its version
   is its module's ALGORITHM_VERSION; unknown keys raise KeyError.
2. prepare/apply contract per adapter — prepare runs the once-per-request
   step (model load / batched computation) and hands its result to apply,
   which produces the per-sentence output:
   - ru_stress loads the Silero accentor through ModelCache; a load failure
     raises EnricherUnavailable (the endpoint maps it to HTTP 503 — never
     silent empty enrichment), and apply marks with the loaded accentor.
   - en_stress needs no model (prepare is a no-op) and marks from token IPA.
   - en_phrasal runs the batched finder ONCE per request and apply pairs the
     per-sentence hit lists in order; only RuntimeError (missing spaCy
     model/package) becomes EnricherUnavailable.
3. Request validation accepts exactly the registry keys and rejects unknown
   ones (pydantic ValidationError -> FastAPI 422). Needs pydantic — inside
   the container; skipped with a notice on a bare venv.

Run from anywhere (adds the package root to sys.path):

    docker-compose/python/ai/venv/bin/python ai/enrichment/test_registry.py
    docker exec ext_python python /app/ai/enrichment/test_registry.py
"""

import os
import sys
from contextlib import contextmanager
from pathlib import Path
from types import SimpleNamespace

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

# Model caching must be on so fake accentors can ride a fake app.state the
# way the real loaded model rides the FastAPI app state (see ModelCache).
os.environ["CACHE_MODELS"] = "1"

try:
    from ai.enrichment import en_stress, phrasal, ru_stress
    from ai.enrichment import registry
except ImportError as exc:
    registry = None
    REGISTRY_MISSING = f"{type(exc).__name__}: {exc}"

try:
    from pydantic import ValidationError

    from ai.api.schemas import EnrichRequest

    HAS_PYDANTIC = True
except ImportError:
    HAS_PYDANTIC = False

ACUTE = "\u0301"


def fake_accentor(text: str) -> str:
    """Stand-in Silero: marks привет with '+' before the stressed vowel."""
    return text.replace("привет", "прив+ет")


class BrokenModelState:
    """app.state whose stress_model attribute blows up on access, the way a
    failed lazy load propagates out of ModelCache.stress_model()."""

    @property
    def stress_model(self):
        raise RuntimeError("weights missing")


@contextmanager
def patched(obj, attr, value):
    sentinel = object()
    original = getattr(obj, attr, sentinel)
    setattr(obj, attr, value)
    try:
        yield
    finally:
        if original is sentinel:
            delattr(obj, attr)
        else:
            setattr(obj, attr, original)


def recorded_phrasal(hits):
    """find_multiword_verbs stand-in recording its calls (fresh copies per
    call: adapters may consume the returned per-sentence lists)."""
    calls = []

    def fake(sentences, lexicon):
        calls.append((sentences, lexicon))
        return [[dict(hit) for hit in sentence_hits] for sentence_hits in hits]

    return fake, calls


def check_registry_lookup_and_shape():
    assert sorted(registry.keys()) == ["en_phrasal", "en_stress", "ru_stress"]
    for key, module, version in (
        ("ru_stress", ru_stress, ru_stress.ALGORITHM_VERSION),
        ("en_stress", en_stress, en_stress.ALGORITHM_VERSION),
        ("en_phrasal", phrasal, phrasal.ALGORITHM_VERSION),
    ):
        adapter = registry.get(key)
        assert adapter.key == key, adapter.key
        # The adapter surfaces the module's ALGORITHM_VERSION — the number
        # Laravel's pythonVersion() parity check (ADR 0067) compares against.
        assert adapter.version == version, (key, adapter.version)
        assert callable(adapter.prepare), key
        assert callable(adapter.apply), key


def check_unknown_key_raises():
    for bad in ("zz_fake", "ru_Stress", ""):
        try:
            registry.get(bad)
        except KeyError:
            pass
        else:
            raise AssertionError(f"unknown key {bad!r} must raise KeyError")


def check_ru_prepare_loads_accentor_via_model_cache():
    adapter = registry.get("ru_stress")
    prepared = adapter.prepare(SimpleNamespace(stress_model=fake_accentor), [], set())
    assert prepared is fake_accentor, "prepare must return the loaded accentor"


def check_ru_prepare_failure_raises_enricher_unavailable():
    adapter = registry.get("ru_stress")
    try:
        adapter.prepare(BrokenModelState(), [], set())
    except registry.EnricherUnavailable as exc:
        # Same detail style the endpoint has always surfaced (HTTP 503).
        assert str(exc) == "Stress model not loaded: weights missing", str(exc)
        assert isinstance(exc.__cause__, RuntimeError), exc.__cause__
    else:
        raise AssertionError("a failed accentor load must raise EnricherUnavailable")


def check_ru_apply_marks_with_prepared_accentor():
    adapter = registry.get("ru_stress")
    prepared = adapter.prepare(SimpleNamespace(stress_model=fake_accentor), [], set())
    marked = adapter.apply(
        "привет мир",
        [{"surface": "привет", "start": 0, "end": 6}],
        prepared,
    )
    assert marked == f"приве{ACUTE}т мир", ascii(marked)


def check_en_stress_prepare_and_apply():
    adapter = registry.get("en_stress")
    assert adapter.prepare(SimpleNamespace(), [], set()) is None
    marked = adapter.apply(
        "the cat",
        [{"surface": "cat", "start": 4, "end": 7, "ipa": ["\u02c8k\u00e6t"]}],
        None,
    )
    assert marked == f"the ca{ACUTE}t", ascii(marked)


def check_phrasal_prepare_batches_once_and_apply_pairs_in_order():
    hits = [[{"verb": "give", "start": 4}], [{"verb": "look", "start": 5}]]
    fake, calls = recorded_phrasal(hits)
    adapter = registry.get("en_phrasal")
    sentences = [
        {"text": "She gave up.", "tokens": [{"surface": "gave", "start": 4, "end": 8}]},
        {"text": "They looked it up.", "tokens": []},
    ]
    with patched(phrasal, "find_multiword_verbs", fake):
        prepared = adapter.prepare(SimpleNamespace(), sentences, {"give up"})
    # Batched exactly once over the whole request (ADR 0059), payload and
    # lexicon passed through.
    assert len(calls) == 1, calls
    assert calls[0][0] is sentences
    assert calls[0][1] == {"give up"}
    # apply pairs the batched hit lists to sentences in order.
    assert adapter.apply("She gave up.", sentences[0]["tokens"], prepared) == hits[0]
    assert adapter.apply("They looked it up.", sentences[1]["tokens"], prepared) == hits[1]


def check_phrasal_runtime_error_raises_enricher_unavailable():
    adapter = registry.get("en_phrasal")

    def broken(sentences, lexicon):
        raise RuntimeError("spaCy model en_core_web_md is not installed")

    with patched(phrasal, "find_multiword_verbs", broken):
        try:
            adapter.prepare(SimpleNamespace(), [], set())
        except registry.EnricherUnavailable as exc:
            assert str(exc) == "spaCy model en_core_web_md is not installed", str(exc)
        else:
            raise AssertionError("a missing spaCy model must raise EnricherUnavailable")


def check_phrasal_other_errors_propagate():
    adapter = registry.get("en_phrasal")

    def buggy(sentences, lexicon):
        raise ValueError("matcher bug")

    with patched(phrasal, "find_multiword_verbs", buggy):
        try:
            adapter.prepare(SimpleNamespace(), [], set())
        except registry.EnricherUnavailable:
            raise AssertionError("only RuntimeError is a 503-worthy failure")
        except ValueError:
            pass  # propagates; the endpoint leaves it to FastAPI (500)


def check_schema_accepts_exactly_registry_keys():
    def request(enrichers):
        return EnrichRequest(
            language="en",
            enrichers=enrichers,
            sentences=[{"id": 1, "text": "Hi.", "tokens": []}],
        )

    # Every registered key is accepted, in any combination; the informational
    # language field stays free-form (Laravel's registry owns the mapping).
    request(list(registry.keys()))
    request(["en_phrasal", "ru_stress"])

    for bad in ("zz_fake", "ru_stress "):
        try:
            request([bad])
        except ValidationError as exc:
            assert any(
                e.get("loc", ())[0:1] == ("enrichers",) for e in exc.errors()
            ), exc.errors()
        else:
            raise AssertionError(f"unknown enricher {bad!r} must fail validation (422)")

    try:
        request([])
    except ValidationError:
        pass
    else:
        raise AssertionError("an empty enrichers list must fail validation")


def main() -> int:
    if registry is None:
        print(f"FAIL registry import: {REGISTRY_MISSING}")
        return 1

    checks = [
        check_registry_lookup_and_shape,
        check_unknown_key_raises,
        check_ru_prepare_loads_accentor_via_model_cache,
        check_ru_prepare_failure_raises_enricher_unavailable,
        check_ru_apply_marks_with_prepared_accentor,
        check_en_stress_prepare_and_apply,
        check_phrasal_prepare_batches_once_and_apply_pairs_in_order,
        check_phrasal_runtime_error_raises_enricher_unavailable,
        check_phrasal_other_errors_propagate,
    ]
    failures = 0
    for check in checks:
        try:
            check()
            print(f"PASS {check.__name__}")
        except AssertionError as exc:
            failures += 1
            print(f"FAIL {check.__name__}: {exc}")

    if HAS_PYDANTIC:
        try:
            check_schema_accepts_exactly_registry_keys()
            print("PASS check_schema_accepts_exactly_registry_keys")
        except AssertionError as exc:
            failures += 1
            print(f"FAIL check_schema_accepts_exactly_registry_keys: {exc}")
        checks.append(check_schema_accepts_exactly_registry_keys)
    else:
        print(
            "SKIP check_schema_accepts_exactly_registry_keys "
            "(pydantic unavailable — rerun inside ext_python)"
        )

    print(f"{len(checks) - failures}/{len(checks)} checks passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
