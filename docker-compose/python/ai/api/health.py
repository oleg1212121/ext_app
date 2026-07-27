from fastapi import APIRouter, HTTPException, Request

router = APIRouter()


@router.get("/health")
async def health(request: Request):
    model = getattr(request.app.state, "model", None)
    if model is None:
        raise HTTPException(status_code=503, detail="Model not loaded")

    return {"status": "ok", "dim": model.get_embedding_dimension()}
