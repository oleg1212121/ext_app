from fastapi import APIRouter, HTTPException, Request

from ai.api.schemas import (
    EmbedBatchRequest,
    EmbedBatchResponse,
    EmbedRequest,
    EmbedResponse,
)

router = APIRouter()


@router.post("/embed", response_model=EmbedResponse)
def embed(req: EmbedRequest, request: Request):
    signature = getattr(request.app.state, "signature", None)
    if signature is None:
        raise HTTPException(status_code=503, detail="Model not loaded")

    return EmbedResponse(vector=signature.generate(req.text, req.language))


@router.post("/embed/batch", response_model=EmbedBatchResponse)
def embed_batch(req: EmbedBatchRequest, request: Request):
    signature = getattr(request.app.state, "signature", None)
    if signature is None:
        raise HTTPException(status_code=503, detail="Model not loaded")

    return EmbedBatchResponse(vectors=signature.generate_batch(req.texts, req.language))
