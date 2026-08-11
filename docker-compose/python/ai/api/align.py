from fastapi import APIRouter, HTTPException, Request

from ai import config
from ai.alignment.bilingual_aligner import BilingualAligner
from ai.api.schemas import AlignRequest, AlignResponse
from ai.models_cache import ModelCache

router = APIRouter()


@router.post("/align", response_model=AlignResponse)
def align(req: AlignRequest, request: Request):
    try:
        align_model = ModelCache(request.app.state).aligner_model()
    except Exception as exc:
        raise HTTPException(status_code=503, detail=f"Model not loaded: {exc}") from exc

    aligner = BilingualAligner(
        model=align_model,
        max_window=req.max_window,
        similarity_threshold=req.similarity_threshold,
        max_total_span=config.align_max_total_span(),
        skip_penalty=config.align_skip_penalty(),
    )

    return AlignResponse(**aligner.align_lists(req.en_sentences, req.ru_sentences))