"""Configurable sentence splitter with pluggable backends.

Supports two backends:

* ``pysbd`` — ML-heuristic segmenter with quote-region tracking (requires
  ``selective_flatten`` in ``TypedSentenceSplitter`` to avoid merging
  dialogue spans).
* ``razdel`` — rule-based Russian-trained tokenizer that generalises well
  to European languages and does not need newline manipulation.

The backend is selected via the ``SPLITTER_ENGINE`` environment variable
(or ``config.splitter_engine()``), defaulting to ``razdel``.
"""

from __future__ import annotations

import re

SUPPORTED_LANGUAGES: list[str] = []


def _load_pysbd_languages() -> list[str]:
    from pysbd.languages import LANGUAGE_CODES

    return sorted(LANGUAGE_CODES.keys())


# Eagerly populate for callers that check SUPPORTED_LANGUAGES before any
# splitter is constructed (e.g. schema validation).
SUPPORTED_LANGUAGES = _load_pysbd_languages()


class SentenceSplitter:
    """Facade that delegates to either ``pysbd`` or ``razdel``."""

    def __init__(
        self,
        language: str = "en",
        *,
        backend: str = "razdel",
        chunk_size: int = 1048576,
    ) -> None:
        self.chunk_size = chunk_size
        self.backend = backend
        self.language: str = ""
        self._backend_instance: object | None = None
        self._set_language(language)

    # -- language / backend switching ------------------------------------------

    def _set_language(self, language: str) -> None:
        if self.backend == "pysbd" and language not in SUPPORTED_LANGUAGES:
            raise ValueError(
                f"Unsupported language '{language}'. "
                f"Supported: {', '.join(SUPPORTED_LANGUAGES)}"
            )
        if language == self.language and self._backend_instance is not None:
            return
        self.language = language
        self._init_backend()

    def _init_backend(self) -> None:
        if self.backend == "razdel":
            from ai.splitting.razdel_splitter import RazdelSplitter

            self._backend_instance = RazdelSplitter(language=self.language)
        else:
            import pysbd

            self._backend_instance = pysbd.Segmenter(
                language=self.language, clean=False,
            )

    # -- public API -----------------------------------------------------------

    @staticmethod
    def normalize_whitespace(text: str) -> str:
        return re.sub(r"\s+", " ", text).strip()

    def split_text(self, text: str) -> list[str]:
        if self.backend == "razdel":
            return self._split_razdel(text)
        return self._split_pysbd(text)

    def _split_razdel(self, text: str) -> list[str]:
        sentences: list[str] = []
        for sentence in self._backend_instance.split_text(text):  # type: ignore[union-attr]
            cleaned = self.normalize_whitespace(sentence)
            if cleaned:
                sentences.append(cleaned)
        return sentences

    def _split_pysbd(self, text: str) -> list[str]:
        # By the time prose reaches pysbd it has been selectively flattened
        # (see TypedSentenceSplitter.selective_flatten), so the only remaining
        # newlines follow sentence-ending punctuation. Keeping those intact
        # still matters: flattening everything to spaces lets pysbd's
        # quote-region heuristic merge long dialogue spans (a lone `"` left
        # over at a line end can swallow every sentence up to the next closing
        # quote).
        sentences: list[str] = []
        for raw in self._backend_instance.segment(text):  # type: ignore[union-attr]
            sentence = self.normalize_whitespace(raw)
            if sentence:
                sentences.append(sentence)
        return sentences

    def split_file(self, input_path: str, output_path: str, language: str | None = None) -> int:
        if language is not None:
            self._set_language(language)
        sentence_count = 0
        remainder = ""

        with open(input_path, "r", encoding="utf-8") as infile, \
             open(output_path, "w", encoding="utf-8") as outfile:
            while True:
                chunk = infile.read(self.chunk_size)
                if not chunk and not remainder:
                    break

                text = remainder + chunk
                sentences = self.split_text(text)

                if chunk:
                    remainder = sentences.pop() if sentences else ""
                else:
                    remainder = ""

                for sentence in sentences:
                    outfile.write(sentence + "\n")
                    sentence_count += 1

            if remainder and self.normalize_whitespace(remainder):
                outfile.write(self.normalize_whitespace(remainder) + "\n")
                sentence_count += 1

        return sentence_count
