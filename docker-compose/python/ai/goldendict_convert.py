"""Convert a GoldenDict dictionary (.mdx / .dsl / StarDict) to JSONL.

Stage 1 of the goldendict import pipeline (see the feature spec): the output
is a JSONL written next to the source, one record per dictionary entry::

    {"word": "hello", "alts": ["hi"], "html": "<div>...</div>"}

``alts`` carries the format's alternate headwords. The JSONL stays on disk
like the kaikki dumps — raw article HTML never enters the database — and is
consumed by ``goldendict:import`` (ticket 02), which is format-blind.

Supported inputs (format is picked by file extension; a directory is sniffed
for its StarDict set):

- MDict ``.mdx`` — ``alts`` from ``@@@LINK=`` redirects. Regcode-encrypted
  MDX (header ``Encrypted`` flag) is rejected with a clear one-line error:
  pyglossary would either brute-force the key blocks or silently skip
  undecryptable records, and neither is an importable result.
- ABBYY Lingvo ``.dsl`` — encoding sniffed (UTF-16LE with BOM, UTF-16BE, or
  UTF-8); card markup normalized to HTML by pyglossary's DSL reader
  (``[m1]`` → indented ``<p>``, ``[trn]`` unwrapped, ``[c]``/``[p]`` →
  green font/label, ``[ref]``/``<<x>>`` → ``bword://`` links); ``alts`` from
  the title lines' variants. A ``.dsl`` next to a ``_abrv.dsl`` file gets its
  abbreviations applied automatically.
- StarDict — input may be the set's ``.ifo`` path or the directory holding
  exactly one set; ``.dict.dz`` is decompressed transparently; ``.syn``
  synonyms land in ``alts``. A directory with zero or several ``.ifo`` files
  is an error naming what was found.

Unsupported input exits non-zero with a clear one-line error.

Runs on the host through the bind-mounted venv (no container rebuild needed)::

    docker-compose/python/ai/venv/bin/python \
        docker-compose/python/ai/goldendict_convert.py INPUT [OUTPUT.jsonl]

Output path defaults to ``<input>.jsonl`` next to the source (for a
directory input, ``<dir>.jsonl`` beside it). Entries are streamed through
pyglossary's direct-read mode (one entry in memory at a time), so dictionary
size does not bound memory.
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

# Header attribute values that mean "not encrypted" (readmdit._read_header).
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
    except (OSError, ValueError, struct.error):
        # struct.error: a truncated file is shorter than the 4-byte header
        # length prefix — a parse failure like any other, so pyglossary
        # surfaces the real one-line error.
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


def resolve_input(source: Path) -> Path:
    """Return the file to read, resolving StarDict directory input.

    pyglossary picks the format by file extension, and the StarDict format is
    identified by ``.ifo`` — a bare set directory has no extension, so it is
    resolved here to the single ``.ifo`` it contains.
    """
    if not source.is_dir():
        if source.is_file():
            return source
        raise ValueError(f"{source}: file not found")
    ifos = sorted(source.glob("*.ifo"))
    if not ifos:
        raise ValueError(
            f"{source}: no StarDict .ifo file in directory — pass a .mdx or "
            ".dsl file, a StarDict .ifo, or a directory containing exactly "
            "one .ifo set"
        )
    if len(ifos) > 1:
        names = ", ".join(ifo.name for ifo in ifos)
        raise ValueError(
            f"{source}: {len(ifos)} .ifo files in directory ({names}) — "
            "pass the .ifo path of the set you want"
        )
    return ifos[0]


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="goldendict_convert.py",
        description=(
            "Convert a GoldenDict dictionary (.mdx, .dsl, StarDict .ifo or "
            'directory) to a {"word": str, "alts": [str], "html": str} JSONL '
            "for goldendict:import."
        ),
    )
    parser.add_argument(
        "input",
        help="dictionary file (.mdx, .dsl, StarDict .ifo) or StarDict directory",
    )
    parser.add_argument(
        "output",
        nargs="?",
        help="output JSONL path (default: <input>.jsonl next to the source)",
    )
    args = parser.parse_args(argv)

    source = Path(args.input)
    try:
        input_path = resolve_input(source)
    except ValueError as exc:
        return fail(str(exc))
    output_path = Path(args.output) if args.output else Path(f"{source}.jsonl")

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
