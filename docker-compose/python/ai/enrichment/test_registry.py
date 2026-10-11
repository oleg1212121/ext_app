"""Unit tests for the enricher module registry.

Covers the contract that lets a future analysis plug in as one module plus
one registry entry (tickets 02/03 of the belarusian-language feature):

1. Lookup: the registry serves exactly the registered keys; each adapter
   exposes the uniform shape (key, version, prepare, apply) and its version
   is its module's ALGORITHM_VERSION; unknown keys raise KeyError.
2. prepare/apply contract per adapter — prepare runs the once-per-request
   step (model load / batched computation) and hands its result to apply,
   which produces the per-sentence output:
   - ru_stress loads the Russian Silero accentor through ModelCache;
     be_stress loads the package's Belarusian one (stress_model('bel')).
     A load failure raises EnricherUnavailable (the endpoint maps it to
     HTTP 503 — never silent empty enrichment), and apply marks with the
     loaded accentor.
   - en_stress needs no model (prepare is a no-op) and marks from token IPA.
   - en_phrasal runs the batched finder ONCE per request and apply pairs the
     per-sentence hit lists in order; only RuntimeError (missing spaCy
     model/package) becomes EnricherUnavailable.
3. Request validation accepts exactly the registry keys and rejects unknown
   ones (pydantic ValidationError -> FastAPI 422).

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/enrichment/test_registry.py -q
"""

from contextlib import contextmanager
from types import SimpleNamespace

import pytest

from ai.enrichment import be_stress, en_stress, phrasal, ru_stress
from ai.enrichment import registry

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


def fake_bel_accentor(text: str) -> str:
    """Stand-in Silero bel: marks прывітанне with '+' before the stressed vowel."""
    return text.replace("прывітанне", "прыв+ітанне")


class BrokenModelState:
    """app.state whose stress-model attribute blows up on access, the way a
    failed lazy load propagates out of ModelCache.stress_model()."""

    def __init__(self, attr: str = "stress_model"):
        object.__setattr__(self, "broken_attr", attr)

    def __getattr__(self, name):
        if name == self.broken_attr:
            raise RuntimeError("weights missing")
        raise AttributeError(name)


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


def test_registry_lookup_and_shape():
    assert sorted(registry.keys()) == ["be_stress", "en_phrasal", "en_stress", "ru_stress"]
    for key, module, version in (
        ("ru_stress", ru_stress, ru_stress.ALGORITHM_VERSION),
        ("be_stress", be_stress, be_stress.ALGORITHM_VERSION),
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


def test_unknown_key_raises():
    for bad in ("zz_fake", "ru_Stress", ""):
        with pytest.raises(KeyError):
            registry.get(bad)


def test_ru_prepare_loads_accentor_via_model_cache():
    adapter = registry.get("ru_stress")
    prepared = adapter.prepare(SimpleNamespace(stress_model=fake_accentor), [], set())
    assert prepared is fake_accentor, "prepare must return the loaded accentor"


def test_ru_prepare_failure_raises_enricher_unavailable():
    adapter = registry.get("ru_stress")
    with pytest.raises(registry.EnricherUnavailable) as exc_info:
        adapter.prepare(BrokenModelState(), [], set())
    # Same detail style the endpoint has always surfaced (HTTP 503).
    assert str(exc_info.value) == "Stress model not loaded: weights missing"
    assert isinstance(exc_info.value.__cause__, RuntimeError)


def test_ru_apply_marks_with_prepared_accentor():
    adapter = registry.get("ru_stress")
    prepared = adapter.prepare(SimpleNamespace(stress_model=fake_accentor), [], set())
    marked = adapter.apply(
        "привет мир",
        [{"surface": "привет", "start": 0, "end": 6}],
        prepared,
    )
    assert marked == f"приве{ACUTE}т мир", ascii(marked)


def test_be_prepare_loads_bel_accentor_via_model_cache():
    adapter = registry.get("be_stress")
    state = SimpleNamespace(**{"stress_model_bel": fake_bel_accentor})
    prepared = adapter.prepare(state, [], set())
    assert prepared is fake_bel_accentor, "prepare must return the loaded accentor"


def test_be_prepare_failure_raises_enricher_unavailable():
    adapter = registry.get("be_stress")
    with pytest.raises(registry.EnricherUnavailable) as exc_info:
        adapter.prepare(BrokenModelState("stress_model_bel"), [], set())
    assert str(exc_info.value) == "Stress model not loaded: weights missing"
    assert isinstance(exc_info.value.__cause__, RuntimeError)


def test_be_apply_marks_with_prepared_accentor():
    adapter = registry.get("be_stress")
    state = SimpleNamespace(**{"stress_model_bel": fake_bel_accentor})
    prepared = adapter.prepare(state, [], set())
    marked = adapter.apply(
        "прывітанне свет",
        [{"surface": "прывітанне", "start": 0, "end": 10}],
        prepared,
    )
    assert marked == f"прыві{ACUTE}танне свет", ascii(marked)


def test_en_stress_prepare_and_apply():
    adapter = registry.get("en_stress")
    assert adapter.prepare(SimpleNamespace(), [], set()) is None
    marked = adapter.apply(
        "the cat",
        [{"surface": "cat", "start": 4, "end": 7, "ipa": ["\u02c8k\u00e6t"]}],
        None,
    )
    assert marked == f"the ca{ACUTE}t", ascii(marked)


def test_phrasal_prepare_batches_once_and_apply_pairs_in_order():
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


def test_phrasal_runtime_error_raises_enricher_unavailable():
    adapter = registry.get("en_phrasal")

    def broken(sentences, lexicon):
        raise RuntimeError("spaCy model en_core_web_md is not installed")

    with patched(phrasal, "find_multiword_verbs", broken):
        with pytest.raises(registry.EnricherUnavailable) as exc_info:
            adapter.prepare(SimpleNamespace(), [], set())
    assert str(exc_info.value) == "spaCy model en_core_web_md is not installed"


def test_phrasal_other_errors_propagate():
    adapter = registry.get("en_phrasal")

    def buggy(sentences, lexicon):
        raise ValueError("matcher bug")

    with patched(phrasal, "find_multiword_verbs", buggy):
        # Propagates; the endpoint leaves it to FastAPI (500).
        with pytest.raises(ValueError):
            adapter.prepare(SimpleNamespace(), [], set())


@pytest.mark.skipif(not HAS_PYDANTIC, reason="pydantic unavailable — rerun inside ext_python")
def test_schema_accepts_exactly_registry_keys():
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
        with pytest.raises(ValidationError) as exc_info:
            request([bad])
        assert any(
            e.get("loc", ())[0:1] == ("enrichers",) for e in exc_info.value.errors()
        ), exc_info.value.errors()

    with pytest.raises(ValidationError):
        request([])  # an empty enrichers list must fail validation
