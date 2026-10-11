"""Regression tests for the sentence splitter.

Guards two behaviors that both hinge on how newlines are fed to the segmenter,
using small committed fixtures (the original book texts were gitignored):

1. Quote-region bug (Book Thief EN import, Aug 2026): when line breaks were
   flattened to spaces before segmentation, pysbd's quote heuristic swallowed
   long dialogue spans into one "sentence". With line structure preserved
   (selective_flatten keeps breaks after sentence punctuation / quote closers)
   each dialogue line splits into its own sentence.

2. Hard-wrap fragmentation (Book Thief RU "Книжный вор 2", Aug 2026): the
   source is hard-wrapped prose (each paragraph wrapped at ~70 cols with a
   single newline). pysbd treats every newline as a sentence boundary, so
   mid-sentence lines come out as fragments. Selectively flattening
   mid-sentence newlines collapses them into real sentences.

The ``razdel`` backend is not affected by these bugs, but the tests still
validate that it produces clean splits.

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/splitting/test_splitter.py -q
"""

from pathlib import Path

import pytest

from ai.splitting.typed_splitter import TypedSentenceSplitter

FIXTURES = Path(__file__).resolve().parent / "fixtures"
EN_FIXTURE = FIXTURES / "book_thief_en.txt"
RU_FIXTURE = FIXTURES / "knizhny_ru.txt"

# RU hard-wrap fragments: each must be flattened into the previous sentence,
# never start a sentence of its own.
RU_WRAP_FRAGMENTS = (
    "едва ли не самыми яркими воспоминаниями,",
    "которые оставались с ней на долгие годы.",
    "слушала, как скрипит старый дом.",
    "нёс на спине чемодан, а в чемодане было",
    "терпение человека, который их писал.",
)

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

ENGINES = ["razdel", "pysbd"]


@pytest.fixture(params=ENGINES)
def backend(request):
    return request.param


def _make_splitter(language: str, backend: str) -> TypedSentenceSplitter:
    """Create a TypedSentenceSplitter with an explicit backend.

    Bypasses the config knob so the tests exercise both engines regardless
    of the SPLITTER_ENGINE env var.
    """
    from ai.splitting.sentence_splitter import SentenceSplitter
    from ai.splitting.sentence_typer import SentenceTyper

    splitter = TypedSentenceSplitter.__new__(TypedSentenceSplitter)
    splitter.backend = backend
    splitter.splitter = SentenceSplitter(language=language, backend=backend)
    splitter.typer = SentenceTyper()
    return splitter


def test_en_fixture_dialogue_lines_split_cleanly(backend):
    text = EN_FIXTURE.read_text(encoding="utf-8")
    splitter = _make_splitter("en", backend)
    sentences, remainder = splitter.split(text, finalize=True)

    assert remainder == ""

    # Correct behavior: every dialogue line is its own sentence (~34).
    # Flattened-newline regression: the quote heuristic merges dialogue spans
    # (29 sentences, one 134-char merged dialogue sentence).
    assert len(sentences) >= 30, (
        f"dialogue lines must split individually, got {len(sentences)}"
    )
    max_len = max(len(s["content"]) for s in sentences)
    assert max_len <= 120, f"a sentence is {max_len} chars (quote region swallowed)"

    contents = [s["content"] for s in sentences]
    for expected in (
        '"I had a brother," the girl whispered.',
        '"You will not forget the words," he said.',
        "She climbed the tallest one and waited.",
    ):
        assert expected in contents, f"missing sentence: {expected!r}"

    # Exactly one title: the document heading line.
    titles = [s for s in sentences if s["type"] == "title"]
    assert len(titles) == 1 and titles[0]["content"] == "The Book Thief Test"


def test_ru_fixture_hard_wraps_are_collapsed(backend):
    text = RU_FIXTURE.read_text(encoding="utf-8")
    splitter = _make_splitter("ru", backend)
    sentences, remainder = splitter.split(text, finalize=True)

    assert remainder == ""

    # Correct behavior: mid-sentence newlines collapse (~25 sentences).
    # Newline-as-boundary regression: 42 fragments.
    assert len(sentences) <= 35, (
        f"hard wraps must not split sentences, got {len(sentences)}"
    )
    max_len = max(len(s["content"]) for s in sentences)
    assert max_len <= 200, f"a sentence is {max_len} chars"

    for fragment in RU_WRAP_FRAGMENTS:
        starters = [s["content"] for s in sentences if s["content"].startswith(fragment)]
        assert not starters, f"mid-sentence hard wrap split off: {starters[0]!r}"

    # The 8 "Глава N" stand-alone lines are detected as titles.
    titles = [s["content"] for s in sentences if s["type"] == "title"]
    assert len(titles) == 8, titles


def test_curly_quote_dialogue_block_stays_split(backend):
    splitter = _make_splitter("en", backend)
    sentences, remainder = splitter.split(CURLY_QUOTE_DIALOGUE, finalize=True)

    assert remainder == ""

    contents = [s["content"] for s in sentences]

    # pysbd's selective_flatten merges dialogue lines at quote boundaries → 3
    # sentences.  razdel splits at every period inside quotes → 5 sentences.
    # Both are valid; just check that no single sentence swallowed the block.
    assert 3 <= len(sentences) <= 5, [c[:50] for c in contents]

    expected_parts = [
        "Tell me. What do you see when you dream like that?",
        "I see myself turning around, and waving goodbye.",
        "It would be nice to say that after this small breakthrough",
    ]
    combined = " ".join(contents)
    for expected in expected_parts:
        assert expected in combined, f"missing expected text: {expected!r}"


def test_selective_flatten_keeps_breaks_after_sentence_punctuation():
    keep = ".!?…\"')»\u201d\u2019"
    for char in keep:
        assert (
            TypedSentenceSplitter.selective_flatten(f"done{char}\nnext")
            == f"done{char}\nnext"
        ), char
    # Trailing whitespace before the break stays; the walk-back only decides
    # whether the break itself survives.
    assert (
        TypedSentenceSplitter.selective_flatten("done.  \nnext") == "done.  \nnext"
    )


def test_selective_flatten_collapses_mid_sentence_breaks():
    assert TypedSentenceSplitter.selective_flatten("word,\nnext") == "word, next"
    assert TypedSentenceSplitter.selective_flatten("hard\nwrap") == "hard wrap"
    assert TypedSentenceSplitter.selective_flatten("wrap \n now") == "wrap   now"


def test_normalize_unifies_newlines_and_caps_blank_runs():
    assert TypedSentenceSplitter.normalize("A  B\r\nC\t D") == "A B\nC D"
    assert TypedSentenceSplitter.normalize("\n\n\n\nX\n\n\n") == "X"
    assert TypedSentenceSplitter.normalize(" P ") == "P"
