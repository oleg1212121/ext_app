from fastapi import APIRouter, HTTPException, Request

from ai.api.schemas import EmbedRequest, EmbedResponse
from ai.models_cache import ModelCache

router = APIRouter()


@router.post("/embed", response_model=EmbedResponse)
def embed(req: EmbedRequest, request: Request):
    try:
        signature = ModelCache(request.app.state).signature_service()
    except Exception as exc:
        raise HTTPException(status_code=503, detail=f"Model not loaded: {exc}") from exc

    return EmbedResponse(vector=signature.generate(req.text, req.language))
