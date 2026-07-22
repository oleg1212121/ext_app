import re

import pysbd
from pysbd.languages import LANGUAGE_CODES


SUPPORTED_LANGUAGES: list[str] = sorted(LANGUAGE_CODES.keys())


class SentenceSplitter:
    SUPPORTED_LANGUAGES = SUPPORTED_LANGUAGES

    def __init__(self, language: str = "en", chunk_size: int = 1048576) -> None:
        self.chunk_size = chunk_size
        self.language: str = ""
        self.segmenter: pysbd.Segmenter | None = None
        self._set_language(language)

    def _set_language(self, language: str) -> None:
        if language not in SUPPORTED_LANGUAGES:
            raise ValueError(
                f"Unsupported language '{language}'. Supported: {', '.join(SUPPORTED_LANGUAGES)}"
            )
        if language == self.language:
            return
        self.language = language
        self.segmenter = pysbd.Segmenter(language=language, clean=False)

    def normalize_whitespace(self, text: str) -> str:
        return re.sub(r"\s+", " ", text).strip()

    def split_text(self, text: str) -> list[str]:
        text = self.normalize_whitespace(text)
        raw_sentences = self.segmenter.segment(text)
        return [self.normalize_whitespace(s) for s in raw_sentences if self.normalize_whitespace(s)]

    def split_file(self, input_path: str, output_path: str, language: str = "en") -> int:
        self._set_language(language)
        sentence_count = 0
        remainder = ""

        try:
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
        finally:
            pass

        return sentence_count
