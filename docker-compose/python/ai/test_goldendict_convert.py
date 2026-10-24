"""Tests for goldendict_convert.py — the GoldenDict front door of the
goldendict import pipeline (spec: .scratch/goldendict-import/spec.md).

Seam under test is the converter CLI (subprocess), exactly the way the
Laravel importer will never care about and the operator will use:

    python goldendict_convert.py INPUT [OUTPUT.jsonl]

Contract (ticket 01, MDX; ticket 04, DSL + StarDict):
- exit 0, JSONL written next to the source at ``INPUT.jsonl`` by default
- every line is ``{"word": str, "alts": [str], "html": str}`` — alts from the
  format's alternate headwords (MDX ``@@@LINK=`` redirects, DSL parenthesized
  title variants, StarDict ``.syn`` synonyms)
- regcode-encrypted MDX → non-zero exit, one clear error line, no traceback,
  no output file
- ``.dsl`` in UTF-16LE (BOM) or UTF-8 converts identically, with the DSL card
  markup normalized to HTML by pyglossary's reader (``[m1]`` → indented ``<p>``,
  ``[trn]`` unwrapped, ``[c]``/``[p]`` → green font/label, ``<<x>>`` →
  ``bword://`` link)
- StarDict: input may be an ``.ifo`` path or the set's directory; ``.dict.dz``
  is decompressed transparently; ``.syn`` synonyms land in ``alts``; a
  directory with zero or several ``.ifo`` files is a one-line error

pyglossary (5.x) has no MDX writer, so the fixture binary is produced by the
minimal MDX 2.0 writer below (zlib + struct only, mirroring what pyglossary's
own reader ``pyglossary/plugin_lib/readmdict.py`` parses). The StarDict set is
hand-built by the minimal writer further down (struct + gzip, per the
StarDictFileFormat spec pyglossary's reader consumes). The committed fixtures
must stay byte-identical to what those writers produce — asserted below, so
editing the entry constants forces regenerating the committed files:

    docker-compose/python/ai/venv/bin/python ai/test_goldendict_convert.py
"""

from __future__ import annotations

import gzip
import json
import struct
import subprocess
import sys
import zlib
from pathlib import Path

SCRIPT = Path(__file__).parent / "goldendict_convert.py"
FIXTURES = Path(__file__).parent / "fixtures" / "goldendict"

# Deterministic fixture dictionary. "hi" is an @@@LINK redirect to "hello":
# pyglossary folds it into hello's alternate headwords instead of yielding a
# record for it. The entry:// href exercises the reader's bword:// rewrite —
# the JSONL must store the rewritten html, that is what gets imported.
ENTRIES = [
    ("hello", '<div><b>hello</b> — a greeting; see <a href="entry://world">world</a></div>'),
    ("hi", "@@@LINK=hello"),
    ("world", "<div>the earth and its people</div>"),
    ("привет", "<div>русская форма приветствия</div>"),
]

# Hand-written expectation: record order follows the MDX key block (ASCII
# headwords sort before Cyrillic), the @@@LINK entry is folded into alts,
# entry:// links come back rewritten to bword://, html is otherwise verbatim.
EXPECTED_RECORDS = [
    {
        "word": "hello",
        "alts": ["hi"],
        "html": '<div><b>hello</b> — a greeting; see <a href="bword://world">world</a></div>',
    },
    {"word": "world", "alts": [], "html": "<div>the earth and its people</div>"},
    {"word": "привет", "alts": [], "html": "<div>русская форма приветствия</div>"},
]


# --- minimal MDX 2.0 writer (fixtures only) ------------------------------------
#
# Writes an unencrypted UTF-8 MDX with one key block and one record block —
# exactly the layout pyglossary's reader consumes. Not production code: it
# exists because pyglossary itself is read-only for MDX.


def _zblock(data: bytes) -> bytes:
    """Wrap a payload the way MDict._decode_block expects: 4-byte LE tag
    (compression method 2 = zlib), BE adler32 of the decompressed payload,
    then the zlib stream."""
    return (
        struct.pack("<L", 2)
        + struct.pack(">I", zlib.adler32(data) & 0xFFFFFFFF)
        + zlib.compress(data)
    )


def write_mdx_bytes(entries: list[tuple[str, str]], encrypted: str = "0") -> bytes:
    """Serialize (word, defi) pairs to a minimal MDX 2.0 dictionary."""
    entries = sorted(entries)

    key_blob = bytearray()
    records = bytearray()
    for word, defi in entries:
        key_blob += struct.pack(">Q", len(records))  # record offset
        key_blob += word.encode("utf-8") + b"\x00"
        records += defi.encode("utf-8")

    head = entries[0][0].encode("utf-8")
    tail = entries[-1][0].encode("utf-8")
    key_block = _zblock(bytes(key_blob))
    key_block_info = (
        struct.pack(">Q", len(entries))
        + struct.pack(">H", len(head))
        + head
        + b"\x00"
        + struct.pack(">H", len(tail))
        + tail
        + b"\x00"
        + struct.pack(">QQ", len(key_block), len(key_blob))
    )
    info_section = (
        b"\x02\x00\x00\x00"
        + struct.pack(">I", zlib.adler32(key_block_info) & 0xFFFFFFFF)
        + zlib.compress(key_block_info)
    )
    summary = struct.pack(
        ">QQQQQ",
        1,  # number of key blocks
        len(entries),
        len(key_block_info),  # key block info size, decompressed
        len(info_section),
        len(key_block),
    )
    summary += struct.pack(">I", zlib.adler32(summary) & 0xFFFFFFFF)

    record_block = _zblock(bytes(records))
    record_section = (
        struct.pack(
            ">QQQQ",
            1,  # number of record blocks
            len(entries),
            16,  # record block info size (1 block x compressed+decompressed)
            len(record_block),
        )
        + struct.pack(">QQ", len(record_block), len(records))
        + record_block
    )

    header = (
        '<?xml version="1.0"?>'
        '<Dictionary Title="ext_app goldendict fixture" '
        'Description="pytest round-trip sample" '
        'GeneratedByEngineVersion="2.0" RequiredEngineVersion="2.0" '
        f'Compat="False" Encrypted="{encrypted}" Encoding="UTF-8" '
        'Format="Html" />'
    )
    header_bytes = header.encode("utf-16-le") + b"\x00\x00"

    return (
        struct.pack(">I", len(header_bytes))
        + header_bytes
        # header checksum is stored little-endian (readmdict._read_header)
        + struct.pack("<I", zlib.adler32(header_bytes) & 0xFFFFFFFF)
        + summary
        + info_section
        + key_block
        + record_section
    )


# --- converter CLI ------------------------------------------------------------


def run_converter(*args: str) -> subprocess.CompletedProcess:
    return subprocess.run(
        [sys.executable, str(SCRIPT), *args],
        capture_output=True,
        text=True,
        timeout=120,
    )


def read_records(path: Path) -> list[dict]:
    return [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines()]


# --- tests ---------------------------------------------------------------------


def test_mdx_round_trip(tmp_path: Path) -> None:
    """Write→convert→assert: a fresh .mdx converts to the expected records,
    with the output defaulting to <input>.jsonl next to the source."""
    mdx = tmp_path / "sample.mdx"
    mdx.write_bytes(write_mdx_bytes(ENTRIES))

    result = run_converter(str(mdx))

    assert result.returncode == 0, result.stderr
    out = tmp_path / "sample.mdx.jsonl"
    assert out.is_file()
    records = read_records(out)
    assert records == EXPECTED_RECORDS
    for record in records:
        assert set(record) == {"word", "alts", "html"}
        assert isinstance(record["word"], str)
        assert isinstance(record["alts"], list)
        assert all(isinstance(alt, str) for alt in record["alts"])
        assert isinstance(record["html"], str)


def test_committed_fixture_stays_in_sync(tmp_path: Path) -> None:
    """The committed sample.mdx converts to the committed hand-written
    expected JSONL, and the fixture writer regenerates the .mdx byte-for-byte
    (keeps the committed binary and ENTRIES from drifting apart)."""
    assert (FIXTURES / "sample.mdx").read_bytes() == write_mdx_bytes(ENTRIES)

    out = tmp_path / "out.jsonl"
    result = run_converter(str(FIXTURES / "sample.mdx"), str(out))

    assert result.returncode == 0, result.stderr
    assert read_records(out) == read_records(FIXTURES / "sample.expected.jsonl")


def test_encrypted_mdx_is_rejected_with_one_line_error(tmp_path: Path) -> None:
    """A regcode-encrypted MDX (header Encrypted != 0) exits non-zero with a
    single clear error line naming the file — before pyglossary can attempt
    its brute-force/silent-skip paths — and writes no output."""
    mdx = tmp_path / "locked.mdx"
    mdx.write_bytes(write_mdx_bytes(ENTRIES, encrypted="2"))

    result = run_converter(str(mdx))

    assert result.returncode != 0
    assert "Traceback" not in result.stderr
    lines = [line for line in result.stderr.splitlines() if line]
    assert len(lines) == 1
    assert "locked.mdx" in lines[0]
    assert "encrypt" in lines[0].lower()
    assert not (tmp_path / "locked.mdx.jsonl").exists()


def test_truncated_mdx_is_a_clean_error(tmp_path: Path) -> None:
    """A truncated .mdx (shorter than the 4-byte header-length prefix) fails
    with the one-line error shape: the header probe's struct.error must not
    escape as a traceback — mdx_encryption returns None and the real error
    surfaces from pyglossary instead."""
    mdx = tmp_path / "truncated.mdx"
    mdx.write_bytes(b"MD")

    result = run_converter(str(mdx))

    assert result.returncode != 0
    assert "Traceback" not in result.stderr
    lines = [line for line in result.stderr.splitlines() if line]
    assert len(lines) == 1
    assert "truncated.mdx" in lines[0]
    assert not (tmp_path / "truncated.mdx.jsonl").exists()


def test_unsupported_input_is_a_clean_error(tmp_path: Path) -> None:
    """A non-dictionary file fails with the one-line error shape, not a
    traceback."""
    bogus = tmp_path / "not-a-dictionary.xyz"
    bogus.write_text("hello")

    result = run_converter(str(bogus))

    assert result.returncode != 0
    assert "Traceback" not in result.stderr


# --- ABBYY Lingvo DSL (ticket 04) -----------------------------------------------
#
# DSL is plain text, so the fixture is the source itself, written in the two
# encodings real dictionaries ship in: UTF-16LE with a BOM (the Lingvo
# default) and UTF-8. pyglossary's reader sniffs the encoding and normalizes
# the card markup to HTML.

DSL_SOURCE = (
    '#NAME "ext_app goldendict DSL fixture"\n'
    '#INDEX_LANGUAGE "English"\n'
    '#CONTENTS_LANGUAGE "Russian"\n'
    "\n"
    "cat\n"
    "tom\n"
    "\t[m1][b]cat[/b] [i]noun[/i]\n"
    "\t[m1][trn]кот, кошка[/trn]\n"
    "\t[m1][c]see also[/c] <<dog>>\n"
    "dog\n"
    "\t[m1][b]dog[/b] [p]noun[/p]\n"
    "\t[m1][trn]пёс, собака[/trn]\n"
)

# Hand-written expectation. The two consecutive title lines "cat"/"tom" are
# DSL's way of declaring alternate headwords, so "tom" lands in alts;
# [m1] becomes an indented <p>, [trn] is unwrapped, [c] and [p] become green
# font/label spans, <<dog>> becomes a bword:// cross-link.
EXPECTED_DSL_RECORDS = [
    {
        "word": "cat",
        "alts": ["tom"],
        "html": (
            '<p style="padding-left:1em;margin:0"><b>cat</b> <i>noun</i>'
            '<p style="padding-left:1em;margin:0">кот, кошка'
            '<p style="padding-left:1em;margin:0"><font color="green">'
            "see also</font> <a href=\"bword://dog\">dog</a>"
        ),
    },
    {
        "word": "dog",
        "alts": [],
        "html": (
            '<p style="padding-left:1em;margin:0"><b>dog</b> '
            '<i class="p"><font color="green">noun</font></i>'
            '<p style="padding-left:1em;margin:0">пёс, собака'
        ),
    },
]


def test_dsl_utf8_round_trip(tmp_path: Path) -> None:
    """A UTF-8 .dsl converts to the same records as any other format."""
    dsl = tmp_path / "sample.dsl"
    dsl.write_text(DSL_SOURCE, encoding="utf-8")

    result = run_converter(str(dsl))

    assert result.returncode == 0, result.stderr
    assert read_records(tmp_path / "sample.dsl.jsonl") == EXPECTED_DSL_RECORDS


def test_dsl_utf16le_round_trip(tmp_path: Path) -> None:
    """A UTF-16LE-with-BOM .dsl (the Lingvo default encoding) converts to
    exactly the same records as the UTF-8 copy — the encoding is sniffed."""
    dsl = tmp_path / "sample-le.dsl"
    dsl.write_bytes(b"\xff\xfe" + DSL_SOURCE.encode("utf-16-le"))

    out = tmp_path / "out.jsonl"
    result = run_converter(str(dsl), str(out))

    assert result.returncode == 0, result.stderr
    assert read_records(out) == EXPECTED_DSL_RECORDS


# --- StarDict (ticket 04) --------------------------------------------------------
#
# Hand-built minimal set per the StarDictFileFormat spec
# (github.com/huzheng001/stardict-3/blob/master/dict/doc/StarDictFileFormat):
#   .ifo  utf-8 key=value metadata, magic first line
#   .idx  records of  word\0  big-endian u32 offset  big-endian u32 size
#   .dict the concatenated definition data (sametypesequence=h → utf-8 html)
#   .dict.dz  gzip-compressed .dict — dictzip adds a chunk table in the gzip
#         FEXTRA field for random access, but it stays a plain gzip stream,
#         which is all pyglossary's reader (gzip.open) needs
#   .syn  records of  synonym\0  big-endian u32 index into .idx order

STARDICT_ENTRIES = [
    ("cat", "<div><b>cat</b> — small domestic feline</div>"),
    ("dog", "<div><b>dog</b> — loyal canine</div>"),
    ("пёс", "<div>русская запись для dog</div>"),
]
STARDICT_SYNONYMS = [
    ("feline", 0),  # alt headword of "cat" (first .idx record)
    ("hound", 1),  # alt headword of "dog"
]

EXPECTED_STARDICT_RECORDS = [
    {
        "word": "cat",
        "alts": ["feline"],
        "html": "<div><b>cat</b> — small domestic feline</div>",
    },
    {
        "word": "dog",
        "alts": ["hound"],
        "html": "<div><b>dog</b> — loyal canine</div>",
    },
    {"word": "пёс", "alts": [], "html": "<div>русская запись для dog</div>"},
]


def write_stardict_set(
    set_dir: Path,
    entries: list[tuple[str, str]] | None = None,
    synonyms: list[tuple[str, int]] | None = None,
    *,
    dict_dz: bool = True,
    name: str = "sample",
) -> Path:
    """Write a minimal StarDict set named ``<name>.*`` into ``set_dir`` and
    return the ``.ifo`` path."""
    entries = STARDICT_ENTRIES if entries is None else entries
    synonyms = STARDICT_SYNONYMS if synonyms is None else synonyms
    set_dir.mkdir(parents=True, exist_ok=True)

    dict_blob = b"".join(html.encode("utf-8") for _, html in entries)
    idx = bytearray()
    offset = 0
    for word, html in entries:
        data = html.encode("utf-8")
        idx += word.encode("utf-8") + b"\x00"
        idx += struct.pack(">II", offset, len(data))
        offset += len(data)
    syn = bytearray()
    for alt, entry_index in synonyms:
        syn += alt.encode("utf-8") + b"\x00" + struct.pack(">I", entry_index)
    ifo = (
        "StarDict's dict ifo file\n"
        "version=2.4.2\n"
        "bookname=ext_app goldendict StarDict fixture\n"
        f"wordcount={len(entries)}\n"
        f"idxfilesize={len(idx)}\n"
        "sametypesequence=h\n"
    ).encode("utf-8")

    (set_dir / f"{name}.ifo").write_bytes(ifo)
    (set_dir / f"{name}.idx").write_bytes(bytes(idx))
    if dict_dz:
        # mtime=0 keeps the gzip stream deterministic for the sync assertion.
        (set_dir / f"{name}.dict.dz").write_bytes(gzip.compress(dict_blob, mtime=0))
    else:
        (set_dir / f"{name}.dict").write_bytes(dict_blob)
    if syn:
        (set_dir / f"{name}.syn").write_bytes(bytes(syn))
    return set_dir / f"{name}.ifo"


def test_stardict_ifo_path_with_dictdz_and_syn(tmp_path: Path) -> None:
    """A StarDict set referenced by its .ifo converts with the .dict.dz
    definitions decompressed and the .syn synonyms surfaced as alts."""
    ifo = write_stardict_set(tmp_path / "eng-rus")

    result = run_converter(str(ifo))

    assert result.returncode == 0, result.stderr
    assert read_records(tmp_path / "eng-rus" / "sample.ifo.jsonl") == (
        EXPECTED_STARDICT_RECORDS
    )


def test_stardict_directory_input(tmp_path: Path) -> None:
    """The set's directory converts too (uncompressed .dict variant), with
    the JSONL defaulting to <dir>.jsonl next to the directory."""
    set_dir = tmp_path / "eng-rus"
    write_stardict_set(set_dir, dict_dz=False)

    result = run_converter(str(set_dir))

    assert result.returncode == 0, result.stderr
    assert read_records(tmp_path / "eng-rus.jsonl") == EXPECTED_STARDICT_RECORDS


def test_stardict_directory_without_ifo_is_a_clean_error(tmp_path: Path) -> None:
    """A directory holding no StarDict set is rejected with the one-line
    error shape."""
    result = run_converter(str(tmp_path))

    assert result.returncode != 0
    assert "Traceback" not in result.stderr
    lines = [line for line in result.stderr.splitlines() if line]
    assert len(lines) == 1
    assert ".ifo" in lines[0]


def test_stardict_directory_with_several_ifo_is_a_clean_error(tmp_path: Path) -> None:
    """A directory holding several StarDict sets is ambiguous and rejected
    with the one-line error naming the sets."""
    write_stardict_set(tmp_path, name="eng-rus")
    write_stardict_set(tmp_path, name="rus-eng")

    result = run_converter(str(tmp_path))

    assert result.returncode != 0
    assert "Traceback" not in result.stderr
    lines = [line for line in result.stderr.splitlines() if line]
    assert len(lines) == 1
    assert "eng-rus.ifo" in lines[0]
    assert "rus-eng.ifo" in lines[0]


# --- committed fixture sync (tickets 01 + 04) ------------------------------------


def test_committed_dsl_and_stardict_fixtures_stay_in_sync(tmp_path: Path) -> None:
    """The committed DSL fixtures match DSL_SOURCE in both encodings, the
    committed StarDict set matches the writer, and both convert to their
    committed hand-written expected JSONL."""
    assert (FIXTURES / "dsl-utf8.dsl").read_bytes() == DSL_SOURCE.encode("utf-8")
    assert (FIXTURES / "dsl-utf16le.dsl").read_bytes() == (
        b"\xff\xfe" + DSL_SOURCE.encode("utf-16-le")
    )
    write_stardict_set(tmp_path / "ref")
    star_dir = FIXTURES / "stardict"
    for part in ("sample.ifo", "sample.idx", "sample.dict.dz", "sample.syn"):
        assert (star_dir / part).read_bytes() == (tmp_path / "ref" / part).read_bytes()

    dsl_out = tmp_path / "dsl.jsonl"
    assert run_converter(str(FIXTURES / "dsl-utf16le.dsl"), str(dsl_out)).returncode == 0
    assert read_records(dsl_out) == read_records(FIXTURES / "dsl.expected.jsonl")
    assert read_records(FIXTURES / "dsl.expected.jsonl") == EXPECTED_DSL_RECORDS

    sd_out = tmp_path / "sd.jsonl"
    assert run_converter(str(star_dir), str(sd_out)).returncode == 0
    assert read_records(sd_out) == read_records(FIXTURES / "stardict.expected.jsonl")
    assert read_records(FIXTURES / "stardict.expected.jsonl") == EXPECTED_STARDICT_RECORDS


if __name__ == "__main__":
    FIXTURES.mkdir(parents=True, exist_ok=True)
    (FIXTURES / "sample.mdx").write_bytes(write_mdx_bytes(ENTRIES))
    (FIXTURES / "sample.expected.jsonl").write_text(
        "".join(json.dumps(r, ensure_ascii=False) + "\n" for r in EXPECTED_RECORDS),
        encoding="utf-8",
    )
    (FIXTURES / "dsl-utf8.dsl").write_bytes(DSL_SOURCE.encode("utf-8"))
    (FIXTURES / "dsl-utf16le.dsl").write_bytes(
        b"\xff\xfe" + DSL_SOURCE.encode("utf-16-le")
    )
    (FIXTURES / "dsl.expected.jsonl").write_text(
        "".join(json.dumps(r, ensure_ascii=False) + "\n" for r in EXPECTED_DSL_RECORDS),
        encoding="utf-8",
    )
    import tempfile

    with tempfile.TemporaryDirectory() as tmp:
        write_stardict_set(Path(tmp))
        for part in ("sample.ifo", "sample.idx", "sample.dict.dz", "sample.syn"):
            (FIXTURES / "stardict").mkdir(exist_ok=True)
            (FIXTURES / "stardict" / part).write_bytes((Path(tmp) / part).read_bytes())
    (FIXTURES / "stardict.expected.jsonl").write_text(
        "".join(
            json.dumps(r, ensure_ascii=False) + "\n" for r in EXPECTED_STARDICT_RECORDS
        ),
        encoding="utf-8",
    )
    print(f"regenerated fixtures under {FIXTURES}")
