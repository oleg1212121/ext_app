"""Regression test for the sentence splitter (plain python, no pytest).

Guards the quote-region bug found during the Book Thief import (Aug 2026):
when line breaks were flattened to spaces before segmentation, pysbd's
quote heuristic swallowed long dialogue spans into one "sentence". The
production entity text split into 547 sentences, one of them 1761 chars
long. With line structure preserved it splits into 1036 clean sentences.

The trigger only reproduces with the full document as a single buffer
(pysbd's quote-region state is global), so the fixture is the exact
entity file the import used.

Run from anywhere (adds the package root to sys.path):

    docker exec ext_python python /app/ai/splitting/test_splitter.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from ai.splitting.typed_splitter import TypedSentenceSplitter

FIXTURE = Path(__file__).resolve().parent / "fixtures" / "book_thief_en.txt"


def main() -> int:
    text = FIXTURE.read_text(encoding="utf-8")
    splitter = TypedSentenceSplitter(language="en")
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

    for failure in failures:
        print("FAIL:", failure)

    if failures:
        print(f"\n{len(failures)} failure(s)")
        return 1

    print(f"OK: {len(sentences)} sentences, max {max_len} chars, none >500")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
