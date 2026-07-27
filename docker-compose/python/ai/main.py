import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI
from sentence_transformers import SentenceTransformer

from ai import config
from ai.alignment.bilingual_aligner import BilingualAligner
from ai.api import align, cosine, embed, health, split
from ai.signatures.text_signature import TextSignature

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI):
    logger.info("Loading model from %s", config.MODEL_PATH)
    model = SentenceTransformer(config.MODEL_PATH)
    app.state.model = model
    app.state.signature = TextSignature(model=model)
    app.state.splitters = {}
    logger.info("Model loaded (dim=%s)", model.get_embedding_dimension())
    yield


app = FastAPI(title="Python ML Service", lifespan=lifespan)

app.include_router(health.router)
app.include_router(embed.router)
app.include_router(cosine.router)
app.include_router(split.router)
app.include_router(align.router)
