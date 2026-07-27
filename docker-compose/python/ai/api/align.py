from fastapi import APIRouter, HTTPException, Request

from ai.alignment.bilingual_aligner import BilingualAligner
from ai.api.schemas import AlignRequest, AlignResponse

router = APIRouter()


@router.post("/align", response_model=AlignResponse)
def align(req: AlignRequest, request: Request):
    model = getattr(request.app.state, "model", None)
    if model is None:
        raise HTTPException(status_code=503, detail="Model not loaded")

    aligner = BilingualAligner(
        model=model,
        max_window=req.max_window,
        similarity_threshold=req.similarity_threshold,
    )

    result = aligner.align_lists(req.en_sentences, req.ru_sentences)

    return AlignResponse(**result)
