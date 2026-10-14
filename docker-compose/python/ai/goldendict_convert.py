"""Convert a GoldenDict/MDict ``.mdx`` dictionary to JSONL for import.

Stage 1 of the goldendict import pipeline (see the feature spec): the output
is a JSONL written next to the source, one record per dictionary entry::

    {"word": "hello", "alts": ["hi"], "html": "<div>...</div>"}

``alts`` carries the format's alternate headwords (MDX ``@@@LINK=``
redirects). The JSONL stays on disk like the kaikki dumps — raw article HTML
never enters the database — and is consumed by ``goldendict:import``
(ticket 02).

Runs on the host through the bind-mounted venv (no container rebuild needed)::

    docker-compose/python/ai/venv/bin/python \
        docker-compose/python/ai/goldendict_convert.py DICT.mdx [DICT.mdx.jsonl]

Output path defaults to ``<input>.jsonl`` next to the source. Entries are
streamed through pyglossary's direct-read mode (one entry in memory at a
time), so dictionary size does not bound memory.

Regcode-encrypted MDX files (header ``Encrypted`` flag) are rejected with a
clear one-line error: pyglossary would either brute-force the key blocks or
silently skip undecryptable records, and neither is an importable result.
"""

from __future__ import annotations

import argparse
import json
import logging
import re
import struct
import sys
from pathlib import Path

PROGRESS_EVERY = 50_000

# Header attribute values that mean "not encrypted" (readmdict._read_header).
_UNENCRYPTED_VALUES = {"", "No", "0"}


def fail(message: str) -> int:
    print(f"error: {' '.join(message.split())}", file=sys.stderr)
    return 1


def mdx_encryption(path: Path) -> str | None:
    """Return the MDX header's ``Encrypted`` value, or None if not encrypted.

    Mirrors the header parsing of pyglossary's readmdict (UTF-16LE XML
    prefixed by a big-endian u32 length). Any parse failure returns None so
    the real error surfaces from pyglossary itself instead of a misdiagnosis.
    """
    try:
        with open(path, "rb") as stream:
            size = struct.unpack(">I", stream.read(4))[0]
            header_bytes = stream.read(size)
        if header_bytes[-2:] != b"\x00\x00":
            return None
        header = header_bytes[:-2].decode("utf-16")
    except (OSError, ValueError):
        return None
    match = re.search(r'Encrypted="([^"]*)"', header)
    if match is None or match.group(1) in _UNENCRYPTED_VALUES:
        return None
    return match.group(1)


def convert(input_path: Path, output_path: Path) -> int:
    """Stream the dictionary to JSONL; returns the record count.

    Must be called only from :func:`main` — pyglossary's ``Glossary.init()``
    (plugin scan) must have run exactly once per process.
    """
    from pyglossary.glossary_v2 import Glossary

    glossary = Glossary()
    glossary.progressbar = False
    glossary.directRead(str(input_path))

    count = 0
    with open(output_path, "w", encoding="utf-8", newline="\n") as out:
        for entry in glossary:
            if entry.isData():
                continue
            terms = [term for term in entry.l_term if term and term.strip()]
            if not terms:
                continue
            record = {
                "word": terms[0],
                "alts": terms[1:],
                "html": entry.defi,
            }
            out.write(json.dumps(record, ensure_ascii=False) + "\n")
            count += 1
            if count % PROGRESS_EVERY == 0:
                print(f"... {count} entries", file=sys.stderr)
    return count


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="goldendict_convert.py",
        description=(
            "Convert a GoldenDict/MDict .mdx dictionary to a "
            '{"word": str, "alts": [str], "html": str} JSONL '
            "for goldendict:import."
        ),
    )
    parser.add_argument("input", help="dictionary file (.mdx)")
    parser.add_argument(
        "output",
        nargs="?",
        help="output JSONL path (default: <input>.jsonl next to the source)",
    )
    args = parser.parse_args(argv)

    input_path = Path(args.input)
    if not input_path.is_file():
        return fail(f"{input_path}: file not found")
    output_path = Path(args.output) if args.output else Path(f"{input_path}.jsonl")

    if input_path.suffix.lower() == ".mdx":
        encrypted = mdx_encryption(input_path)
        if encrypted is not None:
            return fail(
                f'{input_path}: encrypted MDX (header Encrypted="{encrypted}") '
                "is not supported — regcode-protected dictionaries cannot be "
                "converted; use an unencrypted copy"
            )

    # Import pyglossary BEFORE touching its logger: getLogger would otherwise
    # pre-create it with the standard Logger class, shadowing pyglossary's
    # custom one (core.isDebug() needs it). The plugin scan then warns about
    # optional lxml-only formats — silence that so failures read as one line.
    from pyglossary.glossary_v2 import Glossary

    logging.getLogger("pyglossary").setLevel(logging.ERROR)
    Glossary.init()

    partial_path = output_path.with_name(f"{output_path.name}.part")
    try:
        count = convert(input_path, partial_path)
    except Exception as exc:  # noqa: BLE001 — CLI boundary: one-line error, no traceback
        partial_path.unlink(missing_ok=True)
        return fail(f"{input_path}: {type(exc).__name__}: {exc}")
    partial_path.replace(output_path)

    print(f"wrote {count} records -> {output_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
