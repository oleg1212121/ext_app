from fastapi import APIRouter, HTTPException, Request

from ai.api.schemas import EnrichRequest, EnrichResponse, EnrichResult
from ai.enrichment import registry

router = APIRouter()


@router.post("/enrich", response_model=EnrichResponse)
def enrich(req: EnrichRequest, request: Request):
    # Once per request, in the order Laravel listed the keys: each requested
    # module reports its algorithm version and runs its prepare step (model
    # load / batched computation over the whole request, ADR 0059). A prepare
    # failure is a missing model/tooling — 503, never silent empty enrichment
    # that would get stamped as done.
    sentences = [
        {"text": s.text, "tokens": [t.model_dump() for t in s.tokens]}
        for s in req.sentences
    ]
    lexicon = set(req.phrasal_lexicon)

    versions: dict[str, int] = {}
    prepared: dict[str, object] = {}
    for key in dict.fromkeys(req.enrichers):
        module = registry.get(key)
        versions[key] = module.version
        try:
            prepared[key] = module.prepare(request.app.state, sentences, lexicon)
        except registry.EnricherUnavailable as exc:
            raise HTTPException(status_code=503, detail=str(exc)) from exc

    # Per-sentence phase: each requested module's apply produces its output
    # under its key; the batched phrasal hit lists pair to sentences in order.
    results: list[EnrichResult] = []
    for sentence, payload in zip(req.sentences, sentences):
        output = {
            key: registry.get(key).apply(sentence.text, payload["tokens"], prepared[key])
            for key in dict.fromkeys(req.enrichers)
        }
        results.append(EnrichResult(id=sentence.id, output=output))
    return EnrichResponse(results=results, versions=versions)
