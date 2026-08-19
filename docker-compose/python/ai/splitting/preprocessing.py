"""Language-specific pre/post-processing for sentence splitting.

Ported from ``lingtrain-aligner/splitter.py`` (preprocessing_rules,
postprocessing_rules) and ``lingtrain-aligner/preprocessor.py`` (noise
cleanup patterns).  These rules normalise punctuation and whitespace
*before* the sentence splitter sees the text, and fix up edge cases
*after* splitting.
"""

from __future__ import annotations

import re
from typing import Callable

# ---------------------------------------------------------------------------
# Compiled patterns (ported from lingtrain-aligner)
# ---------------------------------------------------------------------------

DOUBLE_SPACES = re.compile(r"[^\S\n]{2,}")
DOUBLE_COMMAS = re.compile(r"[,]{2,}")
DOUBLE_DASH = re.compile(r"[-—]{2,}")
GERMAN_QUOTES = re.compile(r'[»«"„"]+')
RUSSIAN_NOISE = re.compile(r"[\/<>•]+")

# German date protection: "5. Januar" → "5%@% Januar" during split,
# restored in postprocessing.  Prevents the period in dates from being
# treated as a sentence boundary.
_GERMAN_FOO = "%@%"
GERMAN_MONTHS = (
    "Januar|Jänner|Janner|Februar|März|Marz|April|Mai|Juni|Juli|"
    "August|September|Oktober|October|November|Dezember"
)
GERMAN_DATE_PRE = re.compile(
    rf"(\s)(\d{{1,2}})\.(\s+)({GERMAN_MONTHS})"
)
GERMAN_DATE_POST = re.compile(
    rf"(\s)(\d{{1,2}}){_GERMAN_FOO}(\s+)({GERMAN_MONTHS})"
)

# French: reattach « to the previous sentence after split.
_FRENCH_CLOSE = "\u00bb"  # »

# ---------------------------------------------------------------------------
# Preprocessing: applied *before* sentence splitting
# ---------------------------------------------------------------------------

_DEFAULT_PREPROCESSING: list[tuple[re.Pattern[str], str]] = [
    (DOUBLE_SPACES, " "),
    (DOUBLE_COMMAS, ","),
    (DOUBLE_DASH, "—"),
]

_PREPROCESSING_RULES: dict[str, list[tuple[re.Pattern[str], str]]] = {
    "ru": [(RUSSIAN_NOISE, ""), *_DEFAULT_PREPROCESSING],
    "de": [
        (GERMAN_QUOTES, '"'),
        (GERMAN_DATE_PRE, rf"\1\2{_GERMAN_FOO}\3\4"),
        *_DEFAULT_PREPROCESSING,
    ],
}


def preprocess(text: str, lang: str) -> str:
    """Apply language-specific regex cleanup to *text* before splitting."""
    rules = _PREPROCESSING_RULES.get(lang, list(_DEFAULT_PREPROCESSING))
    for pattern, replacement in rules:
        text = pattern.sub(replacement, text)
    return text


# ---------------------------------------------------------------------------
# Postprocessing: applied *after* sentence splitting
# ---------------------------------------------------------------------------


def _after_fr(sentences: list[str]) -> list[str]:
    """French typography: reattach » opening quote to previous sentence."""
    for i, s in enumerate(sentences):
        if s and s[0] == _FRENCH_CLOSE and i > 0:
            sentences[i - 1] = sentences[i - 1] + " »"
            sentences[i] = s[1:]
    return sentences


def _after_de(sentences: list[str]) -> list[str]:
    """German: restore date formatting that was protected during preprocessing."""
    for i, s in enumerate(sentences):
        sentences[i] = GERMAN_DATE_POST.sub(r"\1\2.\3\4", s)
    return sentences


_POSTPROCESSING_RULES: dict[str, Callable[[list[str]], list[str]]] = {
    "fr": _after_fr,
    "de": _after_de,
}


def postprocess(sentences: list[str], lang: str) -> list[str]:
    """Apply language-specific fixups to *sentences* after splitting."""
    fn = _POSTPROCESSING_RULES.get(lang)
    if fn is not None:
        sentences = fn(sentences)
    return sentences
