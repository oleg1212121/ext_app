"""Paragraph-start typing tests for the typed sentence splitter.

A body sentence that *opens* a paragraph is typed ``paragraph`` (when it
would otherwise be plain ``sentence``): the first sentence of the document,
the first sentence after a blank line, and the first sentence after a title
line. Plain ``sentence`` rows continue the current paragraph; ``title`` and
``quote`` predictions are unchanged — a paragraph-starting sentence that
would be typed ``quote`` keeps ``quote``.

Paragraph detection runs on the line structure BEFORE the pysbd backend's
``selective_flatten``, so hard-wrapped prose (single newlines mid-sentence)
and dialogue single-newlines still type ``sentence`` under both engines.

The streaming contract: a buffer that begins with a blank line is itself a
paragraph start (the PHP caller marks a document-start buffer with a
leading blank line, and the splitter leaves the same marker on a remainder
that holds a paragraph-start sentence — see
``test_remainder_carries_the_paragraph_marker``).

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/splitting/test_paragraph_typing.py -q
"""

from ai.splitting.sentence_splitter import SentenceSplitter
from ai.splitting.sentence_typer import SentenceTyper
from ai.splitting.typed_splitter import TypedSentenceSplitter

import pytest

ENGINES = ["razdel", "pysbd"]


@pytest.fixture(params=ENGINES)
def backend(request):
    return request.param


def _make_splitter(language: str, backend: str) -> TypedSentenceSplitter:
    """Create a TypedSentenceSplitter with an explicit backend.

    Bypasses the config knob so the tests exercise both engines regardless
    of the SPLITTER_ENGINE env var.
    """
    splitter = TypedSentenceSplitter.__new__(TypedSentenceSplitter)
    splitter.backend = backend
    splitter.splitter = SentenceSplitter(language=language, backend=backend)
    splitter.typer = SentenceTyper()
    return splitter


def test_blank_line_paragraphs_type_their_first_sentence(backend):
    # Leading blank line = document start (the PHP caller marks the first
    # buffer; the splitter marks paragraph-start remainders the same way).
    splitter = _make_splitter("en", backend)
    text = (
        "\n\nPara one opens here. Para one continues after it.\n\n"
        "Para two opens here. Para two continues after it."
    )
    sentences, remainder = splitter.split(text, finalize=True)

    assert remainder == ""
    assert [(s["type"], s["content"]) for s in sentences] == [
        ("paragraph", "Para one opens here."),
        ("sentence", "Para one continues after it."),
        ("paragraph", "Para two opens here."),
        ("sentence", "Para two continues after it."),
    ]


def test_document_start_marker_types_the_first_sentence(backend):
    # The PHP caller marks a document-start buffer (and the splitter marks
    # a paragraph-start remainder) with a leading blank line.
    splitter = _make_splitter("en", backend)
    sentences, remainder = splitter.split(
        "\n\nOpening sentence. Follow-up sentence.", finalize=True
    )

    assert remainder == ""
    assert [(s["type"], s["content"]) for s in sentences] == [
        ("paragraph", "Opening sentence."),
        ("sentence", "Follow-up sentence."),
    ]


def test_sentence_after_a_title_line_types_paragraph(backend):
    splitter = _make_splitter("en", backend)
    text = "CHAPTER ONE\nFirst prose sentence. Second prose sentence."
    sentences, remainder = splitter.split(text, finalize=True)

    assert remainder == ""
    assert [(s["type"], s["content"]) for s in sentences] == [
        ("title", "CHAPTER ONE"),
        ("paragraph", "First prose sentence."),
        ("sentence", "Second prose sentence."),
    ]


def test_paragraph_start_predicted_quote_keeps_quote(backend):
    splitter = _make_splitter("en", backend)
    text = "He closed the book.\n\n\"Why not?\""
    sentences, remainder = splitter.split(text, finalize=True)

    assert remainder == ""
    assert [(s["type"], s["content"]) for s in sentences] == [
        ("sentence", "He closed the book."),
        ("quote", "\"Why not?\""),
    ]


def test_hard_wrapped_prose_stays_sentence(backend):
    # Single newlines inside a paragraph are hard wraps, not paragraph
    # breaks: the wrapped lines collapse into the running sentence, which
    # types paragraph only at the paragraph's opening sentence.
    splitter = _make_splitter("en", backend)
    text = (
        "\n\nOpening sentence here.\n\n"
        "The wrapped prose keeps flowing across\n"
        "several source lines and ends here. Next sentence."
    )
    sentences, remainder = splitter.split(text, finalize=True)

    assert remainder == ""
    assert [(s["type"], s["content"]) for s in sentences] == [
        ("paragraph", "Opening sentence here."),
        (
            "paragraph",
            "The wrapped prose keeps flowing across several source lines "
            "and ends here.",
        ),
        ("sentence", "Next sentence."),
    ]


def test_no_blank_lines_means_no_paragraph_types(backend):
    # A continuation buffer — no leading blank line (doc-start / carried
    # marker), no blank lines, no title — has no paragraph starts: every
    # sentence stays plain (byte-identical typing to the pre-paragraph
    # behavior for mid-document chunks).
    splitter = _make_splitter("en", backend)
    text = "One sentence here. Two follows it. Three closes it."
    sentences, remainder = splitter.split(text, finalize=True)

    assert remainder == ""
    assert [s["type"] for s in sentences] == ["sentence", "sentence", "sentence"]
    assert [s["content"] for s in sentences] == [
        "One sentence here.",
        "Two follows it.",
        "Three closes it.",
    ]


def test_remainder_carries_the_paragraph_marker(backend):
    # Streaming: when the held-back last sentence is paragraph-typed, the
    # remainder is prefixed with a blank line so the next request re-derives
    # the type (the blank line is stripped by normalize and read as the
    # paragraph-start marker).
    splitter = _make_splitter("en", backend)
    text = "Earlier paragraph text.\n\nFinal short paragraph."
    sentences, remainder = splitter.split(text, finalize=False)

    assert [s["content"] for s in sentences] == ["Earlier paragraph text."]
    assert remainder.startswith("\n\n"), repr(remainder)

    # Re-feed exactly like the PHP caller: remainder + next chunk, then
    # finalize the tail.
    tail_sentences, tail_remainder = splitter.split(remainder, finalize=True)
    assert tail_remainder == ""
    assert [(s["type"], s["content"]) for s in tail_sentences] == [
        ("paragraph", "Final short paragraph."),
    ]


def test_plain_remainder_carries_no_marker(backend):
    # A held-back mid-paragraph sentence must not carry the marker: it is
    # a continuation and the next request must keep typing it sentence.
    splitter = _make_splitter("en", backend)
    text = "First sentence ends. Held back continuation."
    sentences, remainder = splitter.split(text, finalize=False)

    assert [s["content"] for s in sentences] == ["First sentence ends."]
    assert not remainder.startswith("\n\n"), repr(remainder)

    tail_sentences, _ = splitter.split(remainder, finalize=True)
    assert [(s["type"], s["content"]) for s in tail_sentences] == [
        ("sentence", "Held back continuation."),
    ]
