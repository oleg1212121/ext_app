"""Endpoint tests for POST /enrich.

These pin the wire contract of the enrich endpoint — the seam Laravel's
SentenceEnrichmentService consumes — so the dispatch-registry refactor
(ticket 02 of the belarusian-language feature) can be proven
behavior-identical for ru and en:

1. versions reports exactly the requested keys with the modules' algorithm
   versions; outputs are keyed per enricher in request order.
2. Batched phrasal computation: find_multiword_verbs is called ONCE over the
   whole request and its per-sentence hit lists pair to sentences in order.
3. A missing ru/be model fails loudly: HTTP 503, detail "Stress model not
   loaded: ..." — never silent empty enrichment; the be key rides the
   package's Belarusian accentor (stress_model('bel')).
4. A missing spaCy model/package fails loudly: HTTP 503 carrying the
   RuntimeError message; any other exception is NOT mapped to 503.
5. A partial run requests only some enrichers: untouched modules are never
   loaded and add no output keys.
6. Unknown enricher keys are rejected at the schema (pydantic ValidationError
   -> FastAPI 422).

The handler is called directly with a stubbed starlette Request (only
``request.app.state`` is read), so no server is needed.

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/api/test_enrich_endpoint.py -q
"""

from types import SimpleNamespace

import pytest

pytest.importorskip("fastapi")

from fastapi import HTTPException
from pydantic import ValidationError
from starlette.requests import Request as StarletteRequest

from ai.api import enrich
from ai.api.schemas import EnrichPhrasalHit, EnrichRequest
from ai.enrichment import phrasal

RU_TEXT = "привет мир"
EN_SENTENCES = [
    {
        "id": 7,
        "text": "She gave up the plan.",
        "tokens": [
            {"surface": "She", "start": 0, "end": 3},
            {"surface": "gave", "start": 4, "end": 8, "verb_lemmas": ["give"]},
            {"surface": "up", "start": 9, "end": 11},
            {"surface": "the", "start": 12, "end": 15},
            {"surface": "plan", "start": 16, "end": 20, "ipa": ["ˈplæn"]},
        ],
    },
    {
        "id": 8,
        "text": "They looked it up.",
        "tokens": [
            {"surface": "They", "start": 0, "end": 4},
            {"surface": "looked", "start": 5, "end": 11},
        ],
    },
]
ACUTE = "\u0301"
PHRASAL_HITS = [
    [{"verb": "give", "particles": ["up"], "start": 4, "end": 10, "phrase": "give up"}],
    [],
]


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


def fake_request(app_state) -> "StarletteRequest":
    """A starlette Request stub: the handler only reads request.app.state."""
    return StarletteRequest(
        {"type": "http", "app": SimpleNamespace(state=app_state), "method": "POST"}
    )


def ru_request(enrichers=("ru_stress",)) -> "EnrichRequest":
    return EnrichRequest(
        language="ru",
        enrichers=list(enrichers),
        sentences=[
            {
                "id": 3,
                "text": RU_TEXT,
                "tokens": [{"surface": "привет", "start": 0, "end": 6}],
            }
        ],
    )


def en_request(enrichers=("en_stress", "en_phrasal")):
    return EnrichRequest(
        language="en",
        enrichers=list(enrichers),
        phrasal_lexicon=["give up", "sit in"],
        sentences=EN_SENTENCES,
    )


def recorded_phrasal(hits=None):
    """find_multiword_verbs stand-in recording its calls.

    Returns fresh copies per call: the endpoint consumes (mutates) the
    returned per-sentence lists via pop(0), so handing out the shared
    fixture would leak state between tests.
    """
    calls = []
    hits = PHRASAL_HITS if hits is None else hits

    def fake(sentences, lexicon):
        calls.append((sentences, lexicon))
        return [[dict(hit) for hit in sentence_hits] for sentence_hits in hits]

    return fake, calls


def test_ru_happy_path():
    resp = enrich.enrich(ru_request(), fake_request(SimpleNamespace(stress_model=fake_accentor)))
    assert dict(resp.versions) == {"ru_stress": 1}, resp.versions
    assert len(resp.results) == 1
    result = resp.results[0]
    assert result.id == 3
    assert list(result.output.keys()) == ["ru_stress"]
    assert result.output["ru_stress"] == f"приве{ACUTE}т мир", result.output


def test_be_happy_path():
    # The be_stress key rides the package's Belarusian accentor, cached under
    # its own app.state key (stress_model_bel) next to the Russian one.
    request = EnrichRequest(
        language="be",
        enrichers=["be_stress"],
        sentences=[
            {
                "id": 5,
                "text": "прывітанне свет",
                "tokens": [{"surface": "прывітанне", "start": 0, "end": 10}],
            }
        ],
    )
    resp = enrich.enrich(
        request,
        fake_request(SimpleNamespace(**{"stress_model_bel": fake_bel_accentor})),
    )
    assert dict(resp.versions) == {"be_stress": 1}, resp.versions
    assert len(resp.results) == 1
    result = resp.results[0]
    assert result.id == 5
    assert list(result.output.keys()) == ["be_stress"]
    assert result.output["be_stress"] == f"прыві{ACUTE}танне свет", result.output


def test_en_full_run_batches_phrasal_once(monkeypatch):
    fake, calls = recorded_phrasal()
    monkeypatch.setattr(phrasal, "find_multiword_verbs", fake)
    resp = enrich.enrich(en_request(), fake_request(SimpleNamespace()))
    assert dict(resp.versions) == {"en_stress": 1, "en_phrasal": 3}, resp.versions
    # One batched call over the whole request (ADR 0059), lexicon as a set.
    assert len(calls) == 1, calls
    sent_payload, lexicon = calls[0]
    assert lexicon == {"give up", "sit in"}
    assert [s["text"] for s in sent_payload] == [s["text"] for s in EN_SENTENCES]
    assert sent_payload[0]["tokens"][1]["verb_lemmas"] == ["give"]
    # Per-sentence output: en_stress marks, phrasal hits pair in order.
    first, second = resp.results
    assert first.id == 7
    assert list(first.output.keys()) == ["en_stress", "en_phrasal"]
    assert first.output["en_stress"] == f"She gave up the pla{ACUTE}n.", first.output
    # Response validation coerces raw hit dicts into EnrichPhrasalHit.
    assert first.output["en_phrasal"] == [EnrichPhrasalHit(**hit) for hit in PHRASAL_HITS[0]]
    assert second.id == 8
    assert second.output["en_stress"] == "They looked it up."
    assert second.output["en_phrasal"] == []


def test_ru_missing_model_is_503():
    with pytest.raises(HTTPException) as exc_info:
        enrich.enrich(ru_request(), fake_request(BrokenModelState()))
    assert exc_info.value.status_code == 503
    assert exc_info.value.detail == "Stress model not loaded: weights missing"


def test_be_missing_model_is_503():
    with pytest.raises(HTTPException) as exc_info:
        enrich.enrich(
            EnrichRequest(
                language="be",
                enrichers=["be_stress"],
                sentences=[{"id": 5, "text": "прывітанне свет", "tokens": []}],
            ),
            fake_request(BrokenModelState("stress_model_bel")),
        )
    assert exc_info.value.status_code == 503
    assert exc_info.value.detail == "Stress model not loaded: weights missing"


def test_phrasal_runtime_error_is_503(monkeypatch):
    def broken(sentences, lexicon):
        raise RuntimeError("spaCy model en_core_web_md is not installed")

    monkeypatch.setattr(phrasal, "find_multiword_verbs", broken)
    with pytest.raises(HTTPException) as exc_info:
        enrich.enrich(en_request(["en_phrasal"]), fake_request(SimpleNamespace()))
    assert exc_info.value.status_code == 503
    assert exc_info.value.detail == "spaCy model en_core_web_md is not installed"


def test_phrasal_unexpected_error_is_not_503(monkeypatch):
    def buggy(sentences, lexicon):
        raise ValueError("matcher bug")

    monkeypatch.setattr(phrasal, "find_multiword_verbs", buggy)
    # Propagates (FastAPI would surface it as a 500), as before.
    with pytest.raises(ValueError, match="matcher bug"):
        enrich.enrich(en_request(["en_phrasal"]), fake_request(SimpleNamespace()))


def test_partial_run_touches_only_requested_modules(monkeypatch):
    # en_stress alone: no model state at all — nothing besides en_stress may
    # be touched, and no extra output/versions keys appear.
    resp = enrich.enrich(en_request(["en_stress"]), fake_request(SimpleNamespace()))
    assert dict(resp.versions) == {"en_stress": 1}, resp.versions
    assert list(resp.results[0].output.keys()) == ["en_stress"]

    # en_phrasal alone: no en_stress output key, hits still paired.
    fake, calls = recorded_phrasal()
    monkeypatch.setattr(phrasal, "find_multiword_verbs", fake)
    resp = enrich.enrich(en_request(["en_phrasal"]), fake_request(SimpleNamespace()))
    assert dict(resp.versions) == {"en_phrasal": 3}, resp.versions
    assert list(resp.results[0].output.keys()) == ["en_phrasal"]
    assert resp.results[0].output["en_phrasal"] == [
        EnrichPhrasalHit(**hit) for hit in PHRASAL_HITS[0]
    ]


def test_unknown_enricher_key_rejected():
    with pytest.raises(ValidationError) as exc_info:
        ru_request(("zz_fake",))
    errors = exc_info.value.errors()
    assert errors
    assert any(e.get("loc", ())[0:1] == ("enrichers",) for e in errors), errors
