from typing import Literal

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


class AlignMatch(BaseModel):
    a_start: int
    a_end: int
    b_start: int
    b_end: int
    score: float


class AlignLandmark(BaseModel):
    """A hard landmark pin: a human-made committed match (score is always 1.0),
    given as index spans into the submitted sentence lists."""

    a_start: int
    a_end: int
    b_start: int
    b_end: int


class AlignRequest(BaseModel):
    a_sentences: list[str] = Field(..., max_length=config.ALIGN_MAX_SENTENCES)
    b_sentences: list[str] = Field(..., max_length=config.ALIGN_MAX_SENTENCES)
    # default_factory is evaluated per request, so edits to .env apply without restart.
    max_window: int = Field(default_factory=config.align_default_window, ge=1, le=config.ALIGN_MAX_WINDOW)
    similarity_threshold: float = Field(default_factory=config.align_default_threshold, ge=0.0, le=1.0)
    # "greedy" (anchor-first, fast) or "dp" (full window DP); None -> config default.
    algorithm: str | None = Field(default=None, pattern="^(greedy|dp)$")
    # 1:1 anchor confidence for the greedy mode; None -> config default.
    anchor_threshold: float | None = Field(default=None, ge=0.0, le=1.0)
    # Plan 02 knobs: reserved for plans 03-06, currently passed through and
    # stored on the aligner but not yet applied. None -> config default.
    # 1:1 high-confidence prepass anchor bar.
    high_confidence: float | None = Field(default=None, ge=0.0, le=1.0)
    # Diagonal band half-width around the expected length-ratio diagonal.
    band_width: int | None = Field(default=None, ge=1, le=50)
    # Multi-sentence window embedding mode.
    window_embed: str | None = Field(default=None, pattern="^(aggregate|joined)$")
    # Hard landmark pins (human-made committed matches with score 1.0), honored
    # by plan 06: emitted verbatim, split sub-pools, never crossed/overlapped
    # by machine output. Invalid pins -> 422.
    landmarks: list[AlignLandmark] = Field(default_factory=list)


class AlignResponse(BaseModel):
    matches: list[AlignMatch]
    unmatched_a: list[int]
    unmatched_b: list[int]


class EnrichTokenPart(BaseModel):
    """Hyphenated-compound part with its own IPA variants (English)."""

    surface: str
    ipa: list[str] | None = None


class EnrichToken(BaseModel):
    surface: str
    start: int = Field(ge=0)
    end: int = Field(gt=0)
    # Word-class slug from the dictionary link (e.g. "verb", "noun").
    cls: str | None = None
    # Dictionary headword for the token (lemma); used for inflected phrasal
    # leads ("gave up" -> lead lemma "give").
    lemma: str | None = None
    # English: Wiktionary IPA variants for this token, with ˈ kept.
    ipa: list[str] | None = None
    # English: per-part IPA for hyphenated compounds the whole-token lookup
    # could not resolve ("seven-sided" -> seven + sided).
    parts: list[EnrichTokenPart] | None = None
    # Russian: dictionary stressed-form candidates carrying U+0301.
    stressed: list[str] | None = None
    # English: all verb-class headwords for the surface (direct rows + forms
    # table), so a phrasal lead buried under another class's page ("came" ->
    # the stained-glass noun) still matches (ADR 0058).
    verb_lemmas: list[str] | None = None


class EnrichSentence(BaseModel):
    id: int
    text: str = Field(min_length=1, max_length=20000)
    tokens: list[EnrichToken] = Field(default_factory=list, max_length=500)


class EnrichRequest(BaseModel):
    # Informational (logging); dispatch keys off enrichers — Laravel's
    # registry owns the language -> enricher mapping (ADR 0057).
    language: str = Field(min_length=1, max_length=12)
    # Which analyses to run for this batch; validity = dispatchability.
    enrichers: list[Literal["ru_stress", "en_stress", "en_phrasal"]] = Field(
        ..., min_length=1
    )
    # Multi-word verb headwords from the dictionary (English phrasal verbs).
    phrasal_lexicon: list[str] = Field(default_factory=list, max_length=50000)
    sentences: list[EnrichSentence] = Field(..., min_length=1, max_length=config.ENRICH_MAX_SENTENCES)


class EnrichPhrasalHit(BaseModel):
    verb: str
    particles: list[str]
    start: int
    end: int
    # The lexicon headword the match came through ("gave up" -> "give up").
    phrase: str | None = None


class EnrichResult(BaseModel):
    id: int
    # Enricher key -> that enricher's output for the sentence; the shape
    # differs per enricher (ru_stress/en_stress carry the marked string,
    # en_phrasal the hit list).
    output: dict[str, str | list[EnrichPhrasalHit] | None] = Field(default_factory=dict)


class EnrichResponse(BaseModel):
    results: list[EnrichResult]
