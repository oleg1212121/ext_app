"""Tests for BilingualAligner.process() — the file-based chunked flow.

``process()`` reads one sentence per line from two files, aligns in
``chunk_size`` slices (committing up to the last confident anchor so the
uncertain tail is re-aligned with fresh context), re-offsets matches to
global sentence indices, and writes a human-readable report. Only demo.py
uses it, but the chunk-commit logic is where a cursor desync would start.

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/alignment/test_aligner_process.py -q
"""

from ai.alignment.bilingual_aligner import BilingualAligner
from ai.conftest import N, StubModel


def _diagonal_model(dim=8):
    """Four clean 1:1 pairs, each in its own dimension pair."""
    vectors = {}
    for i in range(4):
        en, ru = [0.0] * dim, [0.0] * dim
        en[i * 2] = 1.0
        ru[i * 2] = 1.0
        vectors[N(f"Alpha{i + 1}")] = en
        vectors[N(f"Beta{i + 1}")] = ru
    return StubModel(vectors)


def _write(tmp_path, name, sentences):
    path = tmp_path / name
    path.write_text("\n".join(sentences) + "\n", encoding="utf-8")
    return path


def _read_match_blocks(output_path):
    """(header, en_line, ru_line) per match block."""
    blocks = []
    lines = output_path.read_text(encoding="utf-8").splitlines()
    for i, line in enumerate(lines):
        if line.startswith("--- Match #"):
            blocks.append((line, lines[i + 1], lines[i + 2]))
    return blocks


def test_process_aligns_across_chunks_with_global_indices(tmp_path):
    en_path = _write(tmp_path, "en.txt", [f"Alpha{i + 1}" for i in range(4)])
    ru_path = _write(tmp_path, "ru.txt", [f"Beta{i + 1}" for i in range(4)])
    output_path = tmp_path / "matches.txt"

    # chunk_size=3 forces two chunks: [1-3] + [4], exercising the
    # anchor-commit + offset-carry logic.
    aligner = BilingualAligner(
        model=_diagonal_model(),
        chunk_size=3,
        similarity_threshold=0.55,
        algorithm="greedy",
    )
    aligner.process(en_path, ru_path, output_path)

    text = output_path.read_text(encoding="utf-8")
    blocks = _read_match_blocks(output_path)
    assert len(blocks) == 4, text
    # Global 1-based indices survive the chunk boundaries.
    for i, (header, en_line, ru_line) in enumerate(blocks, 1):
        assert f"EN [{i}-{i}]" in en_line, (header, en_line)
        assert f"RU [{i}-{i}]" in ru_line, (header, ru_line)
    # A clean run has no unmatched sections and no LOW flags.
    assert "Unmatched" not in text
    assert "[LOW]" not in text


def test_process_reports_unmatched_tail(tmp_path):
    en_path = _write(tmp_path, "en.txt", ["Alpha1", "Alpha2"])
    ru_path = _write(tmp_path, "ru.txt", ["Beta1", "Beta2", "Beta3"])
    output_path = tmp_path / "matches.txt"

    aligner = BilingualAligner(
        model=_diagonal_model(),
        chunk_size=10,
        similarity_threshold=0.55,
        algorithm="greedy",
    )
    aligner.process(en_path, ru_path, output_path)

    text = output_path.read_text(encoding="utf-8")
    assert len(_read_match_blocks(output_path)) == 2, text
    assert "--- Unmatched RU sentences ---" in text
    assert 'RU [3]: "Beta3"' in text
