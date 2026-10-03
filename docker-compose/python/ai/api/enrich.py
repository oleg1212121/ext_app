from fastapi import APIRouter, HTTPException, Request

from ai.api.schemas import EnrichRequest, EnrichResponse, EnrichResult
from ai.enrichment import en_stress, phrasal, ru_stress
from ai.models_cache import ModelCache

router = APIRouter()


@router.post("/enrich", response_model=EnrichResponse)
def enrich(req: EnrichRequest, request: Request):
    accentor = None
    if "ru_stress" in req.enrichers:
        try:
            accentor = ModelCache(request.app.state).stress_model()
        except Exception as exc:
            raise HTTPException(status_code=503, detail=f"Stress model not loaded: {exc}") from exc

    lexicon = set(req.phrasal_lexicon)

    # spaCy matching runs batched over the whole request (ADR 0059); a
    # missing model/model package fails loudly (503) instead of writing
    # empty enrichment that would get stamped as done.
    phrasal_hits: list | None = None
    if "en_phrasal" in req.enrichers:
        try:
            phrasal_hits = phrasal.find_multiword_verbs(
                [
                    {"text": s.text, "tokens": [t.model_dump() for t in s.tokens]}
                    for s in req.sentences
                ],
                lexicon,
            )
        except RuntimeError as exc:
            raise HTTPException(status_code=503, detail=str(exc)) from exc

    results: list[EnrichResult] = []
    for sentence in req.sentences:
        tokens = [t.model_dump() for t in sentence.tokens]

        output: dict[str, object] = {}
        if "ru_stress" in req.enrichers:
            output["ru_stress"] = ru_stress.mark_sentence(sentence.text, tokens, accentor)
        if "en_stress" in req.enrichers:
            output["en_stress"] = en_stress.mark_sentence(sentence.text, tokens)
        if phrasal_hits is not None:
            output["en_phrasal"] = phrasal_hits.pop(0)

        results.append(EnrichResult(id=sentence.id, output=output))
    return EnrichResponse(results=results)
