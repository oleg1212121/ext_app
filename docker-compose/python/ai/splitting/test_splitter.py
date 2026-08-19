"""Regression test for the sentence splitter (plain python, no pytest).

Guards two behaviors that both hinge on how newlines are fed to the segmenter.

1. Quote-region bug (Book Thief EN import, Aug 2026): when line breaks were
   flattened to spaces before segmentation, pysbd's quote heuristic swallowed
   long dialogue spans into one "sentence". The production entity text split
   into 547 sentences, one of them 1761 chars long. With line structure
   preserved it splits into 1036 clean sentences.

2. Hard-wrap fragmentation (Book Thief RU "Книжный вор 2", Aug 2026): the
   source is hard-wrapped prose (each paragraph wrapped at ~70 cols with a
   single newline). pysbd treats every newline as a sentence boundary, so
   "Когда Лизель оглядывалась … оказывались" and "едва ли не самыми яркими
   воспоминаниями." came out as two "sentences" (9926 total). Selectively
   flattening mid-sentence newlines (keeping only those that follow sentence
   punctuation) collapses them into ~7148 real sentences.

3. Curly-quote dialogue block (Book Thief EN, Aug 2026): a blank-line
   separated dialogue span whose lines close with curly quotes (\u201d) merged
   into one 464-char "sentence" because selective_flatten flattened the line
   breaks (the closing \u201d was not in the keep-set), and the two collapsed
   spaces per blank line broke pysbd's quote-end re-split. Preserving those
   newlines splits the block into its individual dialogue lines.

Both pysbd triggers only reproduce with the full document as a single buffer
(pysbd's quote-region state is global), so each fixture is the exact entity
file the import used.  The ``razdel`` backend is not affected by these bugs,
but the test still validates that it produces clean splits.

Run from anywhere (adds the package root to sys.path):

    docker exec ext_python python /app/ai/splitting/test_splitter.py
"""

import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from ai.splitting.typed_splitter import TypedSentenceSplitter

FIXTURES = Path(__file__).resolve().parent / "fixtures"
EN_FIXTURE = FIXTURES / "book_thief_en.txt"
RU_FIXTURE = FIXTURES / "knizhny_ru.txt"

FRAGMENT = "едва ли не самыми яркими воспоминаниями."

# A dialogue block whose lines end with *curly* closing quotes and are
# separated by blank lines (the exact passage the user reported merged into
# one 464-char "sentence"). selective_flatten must preserve those line breaks
# or pysbd collapses the block (two spaces per blank line break the
# quote-end re-split) and swallows everything up to the next unquoted period.
CURLY_QUOTE_DIALOGUE = (
    "The girl: \u201cTell me. What do you see when you dream like that?\u201d\n\n"
    "The Jew: \u201c\u2026 I see myself turning around, and waving goodbye.\u201d\n\n"
    "It would be nice to say that after this small breakthrough, neither "
    "Liesel nor Max dreamed their bad visions again."
)


def _make_splitter(language: str, backend: str) -> TypedSentenceSplitter:
    """Create a TypedSentenceSplitter with an explicit backend.

    Bypasses the config knob so the test can exercise both engines
    regardless of the SPLITTER_ENGINE env var.
    """
    from ai.splitting.sentence_splitter import SentenceSplitter
    from ai.splitting.sentence_typer import SentenceTyper

    splitter = TypedSentenceSplitter.__new__(TypedSentenceSplitter)
    splitter.backend = backend
    splitter.splitter = SentenceSplitter(language=language, backend=backend)
    splitter.typer = SentenceTyper()
    return splitter


def check_en(splitter: TypedSentenceSplitter) -> list[str]:
    text = EN_FIXTURE.read_text(encoding="utf-8")
    sentences, remainder = splitter.split(text, finalize=True)
    failures = []

    if remainder:
        failures.append(f"expected empty remainder, got {remainder[:60]!r}")

    # Buggy behavior: 547 sentences, a 1761-char merged dialogue sentence,
    # 15 sentences over 500 chars. Correct behavior: ~1036 / 0 / max ~222.
    if len(sentences) < 900:
        failures.append(f"expected >= 900 sentences, got {len(sentences)}")

    max_len = max(len(s["content"]) for s in sentences)
    if max_len > 500:
        failures.append(f"a sentence is {max_len} chars (quote region swallowed)")

    contents = [s["content"] for s in sentences]
    merged = [c for c in contents if c.startswith('" ') and "Perhaps it was Rudy" in c]
    if merged:
        failures.append(f"merged dialogue sentence: {merged[0][:60]!r}...")

    return failures


def check_curly_quote_dialogue(splitter: TypedSentenceSplitter) -> list[str]:
    sentences, remainder = splitter.split(CURLY_QUOTE_DIALOGUE, finalize=True)
    failures = []

    if remainder:
        failures.append(f"expected empty remainder, got {remainder[:60]!r}")

    contents = [s["content"] for s in sentences]

    # pysbd's selective_flatten merges dialogue lines at quote boundaries → 3
    # sentences.  razdel splits at every period inside quotes → 5 sentences.
    # Both are valid; just check that no single sentence swallowed the whole block.
    if len(sentences) < 3 or len(sentences) > 5:
        failures.append(
            f"expected 3-5 sentences, got {len(sentences)}: "
            f"{[c[:50] for c in contents]}"
        )

    # Every expected substring must appear in some sentence.
    expected_parts = [
        "Tell me. What do you see when you dream like that?",
        "I see myself turning around, and waving goodbye.",
        "It would be nice to say that after this small breakthrough",
    ]
    combined = " ".join(contents)
    for expected in expected_parts:
        if expected not in combined:
            failures.append(f"missing expected text: {expected!r}")

    return failures


def check_ru(splitter: TypedSentenceSplitter) -> list[str]:
    text = RU_FIXTURE.read_text(encoding="utf-8")
    sentences, remainder = splitter.split(text, finalize=True)
    failures = []

    if remainder:
        failures.append(f"expected empty remainder, got {remainder[:60]!r}")

    # Buggy behavior: 9926 sentences, mid-sentence hard-wrap fragments.
    # Correct behavior: ~7148, none starting with the wrap fragment.
    if len(sentences) > 7500:
        failures.append(
            f"expected <= 7500 sentences, got {len(sentences)} (hard wraps split)"
        )

    max_len = max(len(s["content"]) for s in sentences)
    if max_len > 500:
        failures.append(f"a sentence is {max_len} chars")

    contents = [s["content"] for s in sentences]
    fragments = [c for c in contents if c.startswith(FRAGMENT)]
    if fragments:
        failures.append(f"mid-sentence hard wrap split off: {fragments[0][:60]!r}")

    titles = sum(1 for s in sentences if s["type"] == "title")
    if titles < 150:
        failures.append(f"expected >= 150 titles, got {titles}")

    return failures


def main() -> int:
    failures: list[str] = []
    engines = ["razdel", "pysbd"]

    for engine in engines:
        print(f"\n=== Testing {engine} backend ===")
        failures += [f"[{engine}] {f}" for f in check_en(_make_splitter("en", engine))]
        failures += [f"[{engine}] {f}" for f in check_ru(_make_splitter("ru", engine))]
        failures += [f"[{engine}] {f}" for f in check_curly_quote_dialogue(_make_splitter("en", engine))]

    for failure in failures:
        print("FAIL:", failure)

    if failures:
        print(f"\n{len(failures)} failure(s)")
        return 1

    print("\nOK: EN and RU fixtures split cleanly (razdel + pysbd)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
