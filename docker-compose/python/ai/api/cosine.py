from fastapi import APIRouter, HTTPException

from ai.api.schemas import CosineBatchRequest, CosineBatchResponse
from ai.similarity.cosine import batch_cosine_similarity

router = APIRouter()


@router.post("/cosine/batch", response_model=CosineBatchResponse)
async def cosine_batch(req: CosineBatchRequest):
    try:
        similarities = batch_cosine_similarity(req.query, req.candidates)
    except ValueError as exc:
        raise HTTPException(status_code=400, detail=str(exc)) from exc

    return CosineBatchResponse(similarities=similarities)
