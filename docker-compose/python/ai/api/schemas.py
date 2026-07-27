from pydantic import BaseModel, Field

from ai import config


class EmbedRequest(BaseModel):
    text: str = Field(..., min_length=1, max_length=config.EMBED_MAX_TEXT_LENGTH)
    language: str = "en"


class EmbedResponse(BaseModel):
    vector: list[float]


class EmbedBatchRequest(BaseModel):
    texts: list[str] = Field(..., min_length=1, max_length=config.EMBED_BATCH_MAX_TEXTS)
    language: str = "en"


class EmbedBatchResponse(BaseModel):
    vectors: list[list[float]]


class CosineBatchRequest(BaseModel):
    query: list[float] = Field(..., min_length=1)
    candidates: list[list[float]] = Field(..., max_length=config.COSINE_MAX_CANDIDATES)


class CosineBatchResponse(BaseModel):
    similarities: list[float]


class SplitRequest(BaseModel):
    text: str = Field(..., max_length=config.SPLIT_MAX_TEXT_LENGTH)
    language: str = "en"
    finalize: bool = False


class SplitSentence(BaseModel):
    content: str
    type: str


class SplitResponse(BaseModel):
    sentences: list[SplitSentence]
    remainder: str


class AlignRequest(BaseModel):
    en_sentences: list[str] = Field(..., max_length=config.ALIGN_MAX_SENTENCES)
    ru_sentences: list[str] = Field(..., max_length=config.ALIGN_MAX_SENTENCES)
    max_window: int = Field(config.ALIGN_DEFAULT_WINDOW, ge=1, le=config.ALIGN_MAX_WINDOW)
    similarity_threshold: float = Field(config.ALIGN_DEFAULT_THRESHOLD, ge=0.0, le=1.0)


class AlignMatch(BaseModel):
    en_start: int
    en_end: int
    ru_start: int
    ru_end: int
    score: float


class AlignResponse(BaseModel):
    matches: list[AlignMatch]
    unmatched_en: list[int]
    unmatched_ru: list[int]
