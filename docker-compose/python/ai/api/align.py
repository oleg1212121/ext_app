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
        algorithm=req.algorithm or config.align_algorithm(),
        anchor_threshold=req.anchor_threshold or config.align_anchor_threshold(),
        high_confidence=req.high_confidence or config.align_high_confidence(),
        band_width=req.band_width if req.band_width is not None else config.align_band_width(),
        window_embed=req.window_embed or config.align_window_embed(),
    )

    try:
        result = aligner.align_lists(
            req.en_sentences,
            req.ru_sentences,
            [p.model_dump() for p in req.landmarks],
        )
    except ValueError as exc:
        # Invalid landmark pins (zero-length, out of range, crossing/overlapping).
        raise HTTPException(status_code=422, detail=str(exc)) from exc

    return AlignResponse(**result)