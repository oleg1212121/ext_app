"""Typed sentence segmentation for the /split endpoint.

Combines pysbd sentence boundaries with the title/quote typing heuristics
ported from the old PHP splitter. Title detection is line-based (a short
stand-alone line). Buffered prose is handed to pysbd with its line breaks
intact: flattening them to spaces first lets pysbd's quote-region heuristic
merge long dialogue spans into a single "sentence".
"""

import re

from ai.splitting.sentence_splitter import SentenceSplitter
from ai.splitting.sentence_typer import SentenceTyper


class TypedSentenceSplitter:
    def __init__(self, language: str = "en") -> None:
        self.splitter = SentenceSplitter(language=language)
        self.typer = SentenceTyper()

    @staticmethod
    def normalize(text: str) -> str:
        # Same streaming normalization the PHP splitter used:
        # unify newlines, collapse horizontal whitespace, cap blank lines.
        text = text.replace("\r\n", "\n").replace("\r", "\n")
        lines = [" ".join(line.split()) for line in text.split("\n")]
        text = "\n".join(lines)
        while "\n\n\n" in text:
            text = text.replace("\n\n\n", "\n\n")
        return text.strip()

    def split(self, text: str, finalize: bool = False) -> tuple[list[dict], str]:
        """Split text into typed sentences.

        Returns (sentences, remainder). With finalize=False the last
        sentence is held back as the remainder so callers can stream a
        file chunk by chunk without cutting a sentence in half.
        """
        normalized = self.normalize(text)
        sentences: list[dict] = []

        if normalized:
            buffer_lines: list[str] = []

            def flush_buffer() -> None:
                if not buffer_lines:
                    return
                # Join with newlines (not spaces) so pysbd still sees the line
                # structure when it segments the buffered prose.
                pending = "\n".join(buffer_lines).strip()
                buffer_lines.clear()
                if not pending:
                    return
                for sentence in self.splitter.split_text(pending):
                    sentences.append(
                        {"content": sentence, "type": self.typer.predict_type(sentence)}
                    )

            for line in normalized.split("\n"):
                if line and self.typer.is_likely_title_line(line):
                    flush_buffer()
                    sentences.append({"content": line, "type": "title"})
                else:
                    buffer_lines.append(line)

            flush_buffer()

        remainder = ""
        if sentences and not finalize:
            remainder = self._raw_tail(text, sentences.pop()["content"])

        return sentences, remainder

    @staticmethod
    def _raw_tail(raw: str, sentence: str) -> str:
        # Normalization only alters whitespace, so the sentence's tokens
        # appear verbatim in the raw input. Returning the raw text from the
        # sentence start keeps the remainder byte-exact, which lets the PHP
        # caller stitch file chunks back together without losing or adding
        # whitespace at the cut point.
        tokens = [re.escape(token) for token in sentence.split()]
        if not tokens:
            return raw

        pattern = re.compile(r"\s+".join(tokens))
        start = -1
        for match in pattern.finditer(raw):
            start = match.start()

        return raw[start:] if start >= 0 else sentence
