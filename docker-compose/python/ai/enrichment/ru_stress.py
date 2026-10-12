"""Russian stress marking: Silero Stress primary, dictionary fallback.

The Russian instantiation of the shared Cyrillic machinery
(:mod:`ai.enrichment.cyrillic_stress`, which owns the full algorithm
docstring): Silero's ``+`` markers become U+0301 combining acutes after the
stressed vowel (omitted on ё, which is stressed by definition), and tokens
Silero leaves unmarked fall back to the caller-supplied dictionary candidates
(``words.word`` / ``forms.form`` with U+0301); candidates that disagree on
the stress position leave the token unmarked.
"""

from ai.enrichment.cyrillic_stress import (
    COMBINING_ACUTE,
    RU_VOWELS,
    WORD_RE,
    strip_marks,
)
from ai.enrichment.cyrillic_stress import _dict_fallback as _shared_dict_fallback
from ai.enrichment.cyrillic_stress import mark_sentence as _shared_mark_sentence

# Reported on every /enrich response and stamped by Laravel; a bump here
# must be mirrored in RussianStressEnricher::pythonVersion() (ADR 0067).
ALGORITHM_VERSION = 1


def mark_sentence(text: str, tokens: list[dict], accentor) -> str:
    """Return ``text`` with Russian stress marks applied."""
    return _shared_mark_sentence(text, tokens, accentor, RU_VOWELS)


def _dict_fallback(surface: str, candidates: list[str] | None) -> str:
    """Mark ``surface`` from dictionary forms (see cyrillic_stress)."""
    return _shared_dict_fallback(surface, candidates, RU_VOWELS)
