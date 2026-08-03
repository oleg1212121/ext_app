import logging
from contextlib import asynccontextmanager

from fastapi import FastAPI

from ai import config
from ai.api import align, cosine, embed, health, split

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Models deliberately NOT loaded here so `uvicorn --reload` can restart
    # the app in ~1-2s after a source edit without re-loading multi-GB models.
    # The first request that needs a model lazy-loads it (see ModelCache).
    config.optional_torch_threads()
    logger.info("App ready (models load lazily on first request)")
    yield


app = FastAPI(title="Python ML Service", lifespan=lifespan)

app.include_router(health.router)
app.include_router(embed.router)
app.include_router(cosine.router)
app.include_router(split.router)
app.include_router(align.router)