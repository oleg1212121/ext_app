"""Typed sentence segmentation for the /split endpoint.

Combines pysbd sentence boundaries with the title/quote typing heuristics
ported from the old PHP splitter. Title detection is line-based (a short
stand-alone line). Buffered prose is handed to pysbd with newlines
selectively flattened: only newlines that follow sentence-ending
punctuation survive, so pysbd's quote-region heuristic cannot merge long
dialogue spans into a single "sentence" while mid-sentence hard wraps are
still collapsed (pysbd otherwise treats every newline as a boundary and
splits wrapped prose into fragments).
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

    @staticmethod
    def selective_flatten(text: str) -> str:
        """Keep a newline only when it follows sentence-ending punctuation.

        pysbd treats a bare newline as a sentence boundary even when the line
        ends mid-sentence (hard-wrapped prose), producing fragments like
        "Когда Лизель оглядывалась … оказывались" / "едва ли не самыми яркими
        воспоминаниями." as two "sentences". Collapsing those newlines to
        spaces lets pysbd segment on punctuation alone (respecting Mr./Ms.
        abbreviations), while newlines after `. ! ? … » " ' ) ” ’` still mark
        real paragraph breaks and keep pysbd's quote-region heuristic from
        merging long dialogue spans. The curly closers `”` (U+201D) and `’`
        (U+2019) matter: dialogue lines closed with curly quotes (the Book
        Thief uses `“...”`) must keep their line break, otherwise pysbd masks
        the punctuation inside the quote span and merges the whole dialogue
        block into one "sentence".
        """
        out: list[str] = []

        for char in text:
            if char != "\n":
                out.append(char)
                continue

            # Walk back over trailing spaces to the last emitted character.
            index = len(out) - 1
            while index >= 0 and out[index] in (" ", "\t"):
                index -= 1

            if index > 0 and out[index] in ".!?…»\"')\u201d\u2019":
                out.append("\n")
            else:
                out.append(" ")

        return "".join(out)

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
                # Collapse mid-sentence hard wraps, keep paragraph breaks, so
                # pysbd segments on punctuation instead of every newline.
                pending = self.selective_flatten("\n".join(buffer_lines)).strip()
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
