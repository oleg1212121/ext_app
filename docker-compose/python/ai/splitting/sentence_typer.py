"""Sentence type classification (sentence / title / quote).

Ported from the PHP App\\Classes\\SentenceSplitter heuristics so that
sentences produced by this service keep the same `sentence_type_id`
semantics the database and the simulator UI rely on.
"""

import regex as re

TITLE_MAX_LENGTH = 120

_TITLE_STARTERS_RE = re.compile(
    r"^(chapter|part|book|volume|section|глава|часть|книга|раздел)\b", re.IGNORECASE
)
_LETTER_RE = re.compile(r"\p{L}")
_TRAILING_PUNCT_RE = re.compile(r"[.!?…:;,]$")
_LEADING_LOWER_RE = re.compile(r"^\p{Ll}")
_NON_LETTER_RE = re.compile(r"[^\p{L}]")
_NON_UPPER_RE = re.compile(r"[^\p{Lu}]")
_TITLE_CASE_WORD_RE = re.compile(r"^(?:[\p{Lu}\d]|[IVXLCDM]+$)")
_EDGE_QUOTES_RE = re.compile(r"^[\p{Pi}\p{Pf}\s]+|[\p{Pi}\p{Pf}\s]+$")
_ASCII_LAT_CYR_RE = re.compile(r"[^A-Za-zА-Яа-яЁё]")
_ASCII_LAT_CYR_UPPER_RE = re.compile(r"[^A-ZА-ЯЁ]")

_QUOTE_RES = [
    re.compile(r'^"[^"]+"$'),
    re.compile(r"^'[^']+'$"),
    re.compile(r"^\u00ab[^\u00bb]+\u00bb$"),
    re.compile(r"^\u201c[^\u201d]+\u201d$"),
    re.compile(r"^\u2018[^\u2019]+\u2019$"),
]


class SentenceTyper:
    def is_likely_title_line(self, line: str) -> bool:
        line = line.strip()
        length = len(line)

        if length == 0 or length > TITLE_MAX_LENGTH:
            return False

        if not _LETTER_RE.search(line):
            return False

        if _TRAILING_PUNCT_RE.search(line):
            return False

        if _LEADING_LOWER_RE.match(line):
            return False

        if _TITLE_STARTERS_RE.match(line):
            return True

        letters = _NON_LETTER_RE.sub("", line)
        upper_letters = _NON_UPPER_RE.sub("", line)

        if len(letters) > 3 and len(upper_letters) / len(letters) > 0.7:
            return True

        words = line.split()

        if len(words) > 12:
            return False

        title_case_words = sum(1 for word in words if _TITLE_CASE_WORD_RE.match(word))

        return title_case_words / len(words) >= 0.6

    def predict_type(self, sentence: str) -> str:
        if len(sentence.strip()) < 80:
            trimmed = _EDGE_QUOTES_RE.sub("", sentence)
            letters = _ASCII_LAT_CYR_RE.sub("", trimmed)
            upper_letters = _ASCII_LAT_CYR_UPPER_RE.sub("", trimmed)

            if len(letters) > 3 and len(upper_letters) / len(letters) > 0.7:
                return "title"

        for quote_re in _QUOTE_RES:
            if quote_re.match(sentence):
                return "quote"

        return "sentence"
