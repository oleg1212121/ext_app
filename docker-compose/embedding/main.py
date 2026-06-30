from contextlib import asynccontextmanager
from typing import Optional

import numpy as np
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, Field
from sentence_transformers import SentenceTransformer

model: Optional[SentenceTransformer] = None


@asynccontextmanager
async def lifespan(app: FastAPI):
    global model
    model = SentenceTransformer("intfloat/multilingual-e5-small")
    yield


app = FastAPI(title="Embedding Service", lifespan=lifespan)


class EmbedRequest(BaseModel):
    text: str = Field(..., min_length=1, max_length=1_000_000)


class EmbedResponse(BaseModel):
    vector: list[float]


class EmbedBatchRequest(BaseModel):
    texts: list[str] = Field(..., min_length=1, max_length=100)


class EmbedBatchResponse(BaseModel):
    vectors: list[list[float]]


class CosineBatchRequest(BaseModel):
    query: list[float] = Field(..., min_length=1)
    candidates: list[list[float]] = Field(..., min_length=0, max_length=2_000)


class CosineBatchResponse(BaseModel):
    similarities: list[float]


def chunk_text(text: str, chunk_size: int = 1500) -> list[str]:
    if len(text) <= chunk_size:
        return [text]

    chunks = []
    sentences = text.replace("\n", " ").split(". ")

    current = ""
    for sentence in sentences:
        if len(current) + len(sentence) + 2 > chunk_size and current:
            chunks.append(current.strip())
            current = sentence
        else:
            current = f"{current}. {sentence}" if current else sentence

    if current.strip():
        chunks.append(current.strip())

    return chunks


@app.post("/embed", response_model=EmbedResponse)
async def embed(req: EmbedRequest):
    if model is None:
        raise HTTPException(status_code=503, detail="Model not loaded")

    chunks = chunk_text(req.text)
    embeddings = model.encode(chunks, normalize_embeddings=True)

    if len(embeddings) > 1:
        vector = np.mean(embeddings, axis=0).tolist()
    else:
        vector = embeddings[0].tolist()

    return EmbedResponse(vector=vector)


@app.post("/embed/batch", response_model=EmbedBatchResponse)
async def embed_batch(req: EmbedBatchRequest):
    if model is None:
        raise HTTPException(status_code=503, detail="Model not loaded")

    chunked_texts: list[list[str]] = []
    flat_chunks: list[str] = []

    for text in req.texts:
        chunks = chunk_text(text)
        chunked_texts.append(chunks)
        flat_chunks.extend(chunks)

    embeddings = model.encode(flat_chunks, normalize_embeddings=True)
    all_vectors = []
    offset = 0

    for chunks in chunked_texts:
        chunk_count = len(chunks)
        text_embeddings = embeddings[offset : offset + chunk_count]
        offset += chunk_count

        if chunk_count > 1:
            vector = np.mean(text_embeddings, axis=0)
            norm = np.linalg.norm(vector)
            if norm > 1e-15:
                vector = vector / norm
            all_vectors.append(vector.tolist())
        else:
            all_vectors.append(text_embeddings[0].tolist())

    return EmbedBatchResponse(vectors=all_vectors)


@app.post("/cosine/batch", response_model=CosineBatchResponse)
async def cosine_batch(req: CosineBatchRequest):
    if not req.candidates:
        return CosineBatchResponse(similarities=[])

    q = np.array(req.query, dtype=np.float64)
    c = np.array(req.candidates, dtype=np.float64)

    if c.ndim == 1:
        c = c.reshape(1, -1)
    if q.shape[0] != c.shape[1]:
        raise HTTPException(
            status_code=400,
            detail="query and candidate dimension mismatch",
        )

    # Cosine against each row of c (same as PHP: dot / (na * nb), rows need not be normalized)
    norms = np.linalg.norm(c, axis=1)
    norm_q = float(np.linalg.norm(q))
    if norm_q < 1e-15 or np.any(norms < 1e-15):
        similarities: list[float] = []
        for i in range(c.shape[0]):
            na = float(norms[i])
            nb = norm_q
            if na < 1e-15 or nb < 1e-15:
                similarities.append(0.0)
            else:
                similarities.append(float(np.dot(c[i], q) / (na * nb)))
    else:
        similarities = (c @ q / (norms * norm_q)).tolist()

    return CosineBatchResponse(similarities=similarities)


@app.get("/health")
async def health():
    if model is None:
        raise HTTPException(status_code=503, detail="Model not loaded")
    return {"status": "ok"}
