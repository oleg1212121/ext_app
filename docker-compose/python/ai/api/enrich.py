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
    results: list[EnrichResult] = []
    for sentence in req.sentences:
        tokens = [t.model_dump() for t in sentence.tokens]

        output: dict[str, object] = {}
        if "ru_stress" in req.enrichers:
            output["ru_stress"] = ru_stress.mark_sentence(sentence.text, tokens, accentor)
        if "en_stress" in req.enrichers:
            output["en_stress"] = en_stress.mark_sentence(sentence.text, tokens)
        if "en_phrasal" in req.enrichers:
            output["en_phrasal"] = phrasal.find_phrasal_verbs(tokens, lexicon)

        results.append(EnrichResult(id=sentence.id, output=output))
    return EnrichResponse(results=results)
