from fastapi import APIRouter, HTTPException, Request

from ai import config
from ai.api.schemas import SplitRequest, SplitResponse, SplitSentence
from ai.splitting.typed_splitter import TypedSentenceSplitter

router = APIRouter()


@router.post("/split", response_model=SplitResponse)
def split(req: SplitRequest, request: Request):
    # Splitters are pure-python (no model); lazily init the cache dict so the
    # lifespan stays empty (let uvicorn --reload restart cheaply).
    splitters = getattr(request.app.state, "splitters", None)
    if splitters is None:
        splitters = {}
        request.app.state.splitters = splitters

    cached = splitters.get(req.language)
    engine = config.splitter_engine()

    if cached is None or cached.backend != engine:
        try:
            splitters[req.language] = TypedSentenceSplitter(language=req.language)
        except ValueError as exc:
            raise HTTPException(status_code=400, detail=str(exc)) from exc

    sentences, remainder = splitters[req.language].split(req.text, finalize=req.finalize)

    return SplitResponse(
        sentences=[SplitSentence(**sentence) for sentence in sentences],
        remainder=remainder,
    )