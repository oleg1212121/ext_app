from fastapi import APIRouter, Request

from ai.models_cache import ModelCache

router = APIRouter()


@router.get("/health")
async def health(request: Request):
    # Lazy-load the signature model on first health check; cached after.
    # `dim` is the signature model's dimension (BGE-M3 = 1024).
    cache = ModelCache(request.app.state)
    try:
        m = cache.signature_model()
        return {"status": "ok", "dim": m.get_embedding_dimension()}
    except Exception as exc:
        return {"status": "loading", "detail": str(exc)}