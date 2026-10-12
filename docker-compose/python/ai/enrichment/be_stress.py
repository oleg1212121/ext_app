"""Belarusian stress marking: Silero Stress primary, dictionary fallback.

The Belarusian instantiation of the shared Cyrillic machinery
(:mod:`ai.enrichment.cyrillic_stress`, which owns the full algorithm
docstring) — the same Silero primary/dictionary-fallback algorithm as
:mod:`ai.enrichment.ru_stress`, closed over the Belarusian vowel letters
а е ё і о у ы э ю я (і instead of Russian и; non-syllabic ў is not a vowel;
ё/Ё carry stress by definition, as in Russian).

The primary accentor is the package's own Belarusian-trained model —
``load_accentor(lang='bel')`` (``accentor-bel.pt``), loaded via
``ModelCache.stress_model('bel')`` by the registry adapter. (The Russian
accentor imposed Russian stress positions on Belarusian orthography and was
rejected by the ticket 03 quality gate; see the ticket's quality-gate
record.)
"""

from ai.enrichment.cyrillic_stress import (
    BE_VOWELS,
    COMBINING_ACUTE,
    WORD_RE,
    strip_marks,
)
from ai.enrichment.cyrillic_stress import _dict_fallback as _shared_dict_fallback
from ai.enrichment.cyrillic_stress import mark_sentence as _shared_mark_sentence

# Reported on every /enrich response and stamped by Laravel; a bump here
# must be mirrored in BelarusianStressEnricher::pythonVersion() (ADR 0067).
ALGORITHM_VERSION = 1


def mark_sentence(text: str, tokens: list[dict], accentor) -> str:
    """Return ``text`` with Belarusian stress marks applied."""
    return _shared_mark_sentence(text, tokens, accentor, BE_VOWELS)


def _dict_fallback(surface: str, candidates: list[str] | None) -> str:
    """Mark ``surface`` from dictionary forms (see cyrillic_stress)."""
    return _shared_dict_fallback(surface, candidates, BE_VOWELS)
