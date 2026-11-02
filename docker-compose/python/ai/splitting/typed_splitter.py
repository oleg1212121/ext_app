"""Typed sentence segmentation for the /split endpoint.

Combines a configurable sentence boundary detector (``razdel`` or ``pysbd``)
with title/quote/paragraph typing heuristics ported from the old PHP
splitter (paragraph typing is new — see ``split``).

Title detection is line-based (a short stand-alone line).  Buffered prose
is handed to the chosen backend.  When the backend is ``pysbd``,
newlines are selectively flattened so that pysbd's quote-region heuristic
cannot merge long dialogue spans into a single "sentence" while mid-sentence
hard wraps are still collapsed.  When the backend is ``razdel`` this step
is skipped because razdel does not have the quote-region bug.  Paragraph
detection runs on the line structure BEFORE that flattening: the buffer is
cut into blocks at blank lines, and the first sentence of each block (plus
the sentence right after a title line, plus a document-start buffer —
marked by a leading blank line) types ``paragraph`` instead of plain
``sentence`` when nothing else claims it.
"""

from __future__ import annotations

import re

from ai import config
from ai.splitting.preprocessing import postprocess, preprocess
from ai.splitting.sentence_splitter import SentenceSplitter
from ai.splitting.sentence_typer import SentenceTyper


class TypedSentenceSplitter:
    def __init__(self, language: str = "en", backend: str | None = None) -> None:
        # Engine resolves per language: explicit pins via SPLITTER_ENGINE_OVERRIDES,
        # then the global engine, with the rule-based engine substituted whenever
        # the global one cannot serve the language (pysbd has no Belarusian
        # entry, etc.). An explicit *argument* bypasses resolution (regression
        # scripts use it to exercise both engines).
        self.backend = backend if backend is not None else config.splitter_engine_for(language)
        self.splitter = SentenceSplitter(language=language, backend=self.backend)
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
        abbreviations), while newlines after `. ! ? … » " ' ) " '` still mark
        real paragraph breaks and keep pysbd's quote-region heuristic from
        merging long dialogue spans. The curly closers `"` (U+201D) and `'`
        (U+2019) matter: dialogue lines closed with curly quotes (the Book
        Thief uses `"...`) must keep their line break, otherwise pysbd masks
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

        A sentence predicted ``sentence`` is typed ``paragraph`` when it
        opens a paragraph: the first sentence of the document, the first
        after a blank line, or the first after a title line (``quote`` /
        ``title`` predictions keep their type — they break flow visually
        anyway). Streaming carries the paragraph-start position in the
        text itself: a buffer that *begins* with a blank line is a
        paragraph start — the PHP caller marks a document-start buffer
        that way, and when the held-back sentence below is
        paragraph-typed the remainder gets the same blank-line prefix so
        the next request re-derives the type (normalize strips the
        leading blank line, so the marker must be read off the raw text
        here, before normalize).
        """
        starts_paragraph = text.startswith("\n\n")
        normalized = self.normalize(text)
        # Apply language-specific preprocessing (quote normalisation, noise
        # removal, German date protection, etc.) before splitting.
        normalized = preprocess(normalized, self.splitter.language)

        sentences: list[dict] = []

        if normalized:
            buffer_lines: list[str] = []
            # Whether the first sentence of the buffer's first block opens
            # a paragraph (document start / carried marker / title above).
            pending_paragraph_start = starts_paragraph

            def flush_buffer() -> None:
                nonlocal pending_paragraph_start
                if not buffer_lines:
                    return
                # Split the buffer into paragraph blocks at blank lines
                # BEFORE pysbd's selective newline flattening: a blank line
                # is a paragraph break, and flattening first would reduce
                # it to whitespace inside one text (pysbd's dialogue
                # handling stays untouched — blocks flatten exactly as
                # before, just one per paragraph).
                blocks: list[list[str]] = []
                current: list[str] = []
                for line in buffer_lines:
                    if line.strip():
                        current.append(line)
                    elif current:
                        blocks.append(current)
                        current = []
                if current:
                    blocks.append(current)
                buffer_lines.clear()

                for block_index, block in enumerate(blocks):
                    raw = "\n".join(block)
                    # pysbd needs selective newline flattening; razdel does not.
                    if self.backend == "pysbd":
                        raw = self.selective_flatten(raw)
                    pending = raw.strip()
                    if not pending:
                        continue
                    for sentence_index, sentence in enumerate(
                        self.splitter.split_text(pending)
                    ):
                        sentence_type = self.typer.predict_type(sentence)
                        if (
                            sentence_index == 0
                            and sentence_type == "sentence"
                            and (block_index > 0 or pending_paragraph_start)
                        ):
                            sentence_type = "paragraph"
                        sentences.append(
                            {"content": sentence, "type": sentence_type}
                        )
                pending_paragraph_start = False

            for line in normalized.split("\n"):
                if line and self.typer.is_likely_title_line(line):
                    flush_buffer()
                    sentences.append({"content": line, "type": "title"})
                    pending_paragraph_start = True
                else:
                    buffer_lines.append(line)

            flush_buffer()

        # Apply language-specific postprocessing (French » reattachment,
        # German date restoration, etc.) after splitting.
        contents = [s["content"] for s in sentences]
        contents = postprocess(contents, self.splitter.language)
        sentences = [
            {"content": c, "type": sentences[i]["type"]}
            for i, c in enumerate(contents)
            if c.strip()
        ]

        remainder = ""
        if sentences and not finalize:
            held_back = sentences.pop()
            remainder = self._raw_tail(text, held_back["content"])
            if held_back["type"] == "paragraph":
                # Carry the paragraph-start position across the chunk
                # boundary: the blank line rides on the raw remainder (it is
                # the caller's stitching prefix, never sentence content) and
                # re-marks the sentence as a paragraph start on the next
                # request. Title/quote types re-derive from content alone,
                # so they need no carry.
                remainder = "\n\n" + remainder

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
