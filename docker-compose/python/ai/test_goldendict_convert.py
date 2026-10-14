"""Tests for goldendict_convert.py — the GoldenDict/MDict front door of the
goldendict import pipeline (spec: .scratch/goldendict-import/spec.md).

Seam under test is the converter CLI (subprocess), exactly the way the
Laravel importer will never care about and the operator will use:

    python goldendict_convert.py INPUT.mdx [OUTPUT.jsonl]

Contract (ticket 01):
- exit 0, JSONL written next to the source at ``INPUT.mdx.jsonl`` by default
- every line is ``{"word": str, "alts": [str], "html": str}`` — alts from the
  format's alternate headwords (MDX ``@@@LINK=`` redirects)
- regcode-encrypted MDX → non-zero exit, one clear error line, no traceback,
  no output file

pyglossary (5.x) has no MDX writer, so the fixture binary is produced by the
minimal MDX 2.0 writer below (zlib + struct only, mirroring what pyglossary's
own reader ``pyglossary/plugin_lib/readmdict.py`` parses). The committed
``fixtures/goldendict/sample.mdx`` must stay byte-identical to what that
writer produces from ENTRIES — asserted below, so editing ENTRIES forces
regenerating the committed pair:

    docker-compose/python/ai/venv/bin/python ai/test_goldendict_convert.py
"""

from __future__ import annotations

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


def test_unsupported_input_is_a_clean_error(tmp_path: Path) -> None:
    """A non-dictionary file fails with the one-line error shape, not a
    traceback."""
    bogus = tmp_path / "not-a-dictionary.xyz"
    bogus.write_text("hello")

    result = run_converter(str(bogus))

    assert result.returncode != 0
    assert "Traceback" not in result.stderr


if __name__ == "__main__":
    FIXTURES.mkdir(parents=True, exist_ok=True)
    (FIXTURES / "sample.mdx").write_bytes(write_mdx_bytes(ENTRIES))
    (FIXTURES / "sample.expected.jsonl").write_text(
        "".join(json.dumps(r, ensure_ascii=False) + "\n" for r in EXPECTED_RECORDS),
        encoding="utf-8",
    )
    print(f"regenerated {FIXTURES}/sample.mdx and sample.expected.jsonl")
