from fastapi import APIRouter, HTTPException, Request

from ai.api.schemas import SplitRequest, SplitResponse, SplitSentence
from ai.splitting.typed_splitter import TypedSentenceSplitter

router = APIRouter()


@router.post("/split", response_model=SplitResponse)
def split(req: SplitRequest, request: Request):
    splitters: dict[str, TypedSentenceSplitter] = request.app.state.splitters

    if req.language not in splitters:
        try:
            splitters[req.language] = TypedSentenceSplitter(language=req.language)
        except ValueError as exc:
            raise HTTPException(status_code=400, detail=str(exc)) from exc

    sentences, remainder = splitters[req.language].split(req.text, finalize=req.finalize)

    return SplitResponse(
        sentences=[SplitSentence(**sentence) for sentence in sentences],
        remainder=remainder,
    )
