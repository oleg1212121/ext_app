"""Razdel-based sentence splitter backend.

``razdel`` is a rule-based sentence tokenizer trained on Russian that also
works well for many European languages.  It does not have the quote-region
merging bug that ``pysbd`` exhibits, so the ``selective_flatten`` step in
``TypedSentenceSplitter`` is unnecessary when this backend is active.
"""

from __future__ import annotations


class RazdelSplitter:
    """Thin wrapper around ``razdel.sentenize`` matching the ``SentenceSplitter`` interface."""

    def __init__(self, language: str = "en") -> None:
        self.language = language

    def split_text(self, text: str) -> list[str]:
        from razdel import sentenize

        return [s.text for s in sentenize(text)]
