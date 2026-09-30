from fastapi import APIRouter, HTTPException, Request

from ai.api.schemas import EnrichRequest, EnrichResponse, EnrichResult
from ai.enrichment import en_stress, phrasal, ru_stress
from ai.models_cache import ModelCache

router = APIRouter()


@router.post("/enrich", response_model=EnrichResponse)
def enrich(req: EnrichRequest, request: Request):
    accentor = None
    if req.language == "ru":
        try:
            accentor = ModelCache(request.app.state).stress_model()
        except Exception as exc:
            raise HTTPException(status_code=503, detail=f"Stress model not loaded: {exc}") from exc

    lexicon = set(req.phrasal_lexicon)
    results: list[EnrichResult] = []
    for sentence in req.sentences:
        tokens = [t.model_dump() for t in sentence.tokens]

        stressed = None
        if req.language == "ru":
            stressed = ru_stress.mark_sentence(sentence.text, tokens, accentor)
        elif req.language == "en":
            stressed = en_stress.mark_sentence(sentence.text, tokens)

        phrasal_verbs = (
            phrasal.find_phrasal_verbs(tokens, lexicon) if req.language == "en" else None
        )

        results.append(
            EnrichResult(
                id=sentence.id,
                stressed=stressed,
                phrasal_verbs=phrasal_verbs,
            )
        )
    return EnrichResponse(results=results)
