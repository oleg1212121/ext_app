"""Regression test for the aligner's skip branch, span cap and embedding cache.

Guards the aligner-precision work (Aug 2026): the DP force-aligned every
sentence, so a sentence with no counterpart was matched to an unrelated window
(a <0.6 garbage meaning match). The skip branch lets the DP consume a sentence
without emitting a match; the span cap rejects 1:5 / 5:1 edges; the embedding
cache stops re-encoding identical window texts across chunks/entities.

Uses a stub model with hand-picked vectors so no weights are needed. Run from
anywhere (adds the package root to sys.path):

    docker exec ext_python python /app/ai/alignment/test_aligner.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

import numpy as np
import torch

from ai.alignment.bilingual_aligner import BilingualAligner


def normalized(vector):
    return list(np.asarray(vector, dtype=float) / np.linalg.norm(vector))


class StubModel:
    """Deterministic stand-in for SentenceTransformer.

    Maps each joined-window text to a fixed (already normalized) vector, and
    counts encode() calls so the cache behavior can be asserted.
    """

    def __init__(self, vectors: dict[str, list[float]]):
        self.vectors = {
            text: torch.tensor(normalized(vector), dtype=torch.float32)
            for text, vector in vectors.items()
        }
        self.encode_calls = 0

    def encode(self, texts, **kwargs):
        self.encode_calls += 1
        return torch.stack([self.vectors[text] for text in texts])


def skips_an_unmatchable_sentence_instead_of_force_matching():
    # EN = [Cat, Extra], RU = [Cat translation, D]. "Extra" has no counterpart.
    # Old DP: 1:1 Cat + 1:1 Extra (garbage, score 0). New DP: 1:1 Cat + skip.
    model = StubModel({
        "Cat": [1, 0, 0, 0],
        "Extra": [0, 1, 0, 0],
        "Cat Extra": [0.5, 0, 1, 0],
        "Cat translation": [1, 0, 0, 0],
        "Cat translation D": [0, 0, 0, 1],
        "D": [0, 0, 0, 1],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        skip_penalty=-0.5,
        max_total_span=6,
    )

    result = aligner.align_lists(["Cat", "Extra"], ["Cat translation", "D"])

    assert result["matches"] == [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 1.0}
    ], f"unexpected matches: {result['matches']}"
    assert result["unmatched_en"] == [1], result["unmatched_en"]
    assert result["unmatched_ru"] == [1], result["unmatched_ru"]

    return "OK: unmatchable sentence is skipped, not force-matched"


def still_force_matches_when_skipping_is_disabled():
    # Same input as above but with an extreme skip penalty: the skip branch is
    # effectively off, so the DP falls back to the old garbage match.
    model = StubModel({
        "Cat": [1, 0, 0, 0],
        "Extra": [0, 1, 0, 0],
        "Cat Extra": [0.5, 0, 1, 0],
        "Cat translation": [1, 0, 0, 0],
        "Cat translation D": [0, 0, 0, 1],
        "D": [0, 0, 0, 1],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        skip_penalty=-100.0,
        max_total_span=6,
    )

    result = aligner.align_lists(["Cat", "Extra"], ["Cat translation", "D"])

    assert len(result["matches"]) == 2, f"expected a force-matched pair, got {result['matches']}"
    assert result["matches"][1]["score"] < 0.55, "second match should be sub-threshold garbage"
    assert result["unmatched_en"] == [], result["unmatched_en"]
    assert result["unmatched_ru"] == [], result["unmatched_ru"]

    return "OK: with skips disabled the old garbage match returns (branch is the fix)"


def span_cap_rejects_oversized_edges():
    # EN = [One], RU = [Part1, Part2]. "One" matches the pooled pair (1:2) but
    # neither part individually. With max_total_span=2 the 1:2 edge is illegal
    # so everything is skipped; with max_total_span=3 the 1:2 match wins.
    vectors = {
        "One": [1, 0.8, 0.8],
        "Part1": [0, 1, 0],
        "Part2": [0, 0, 1],
        "Part1 Part2": [0, 1, 1],
    }

    capped = BilingualAligner(
        model=StubModel(vectors),
        max_window=2,
        similarity_threshold=0.55,
        skip_penalty=-0.5,
        max_total_span=2,
    ).align_lists(["One"], ["Part1", "Part2"])

    assert capped["matches"] == [], f"1:2 should be rejected by the cap, got {capped['matches']}"
    assert capped["unmatched_en"] == [0], capped["unmatched_en"]
    assert capped["unmatched_ru"] == [0, 1], capped["unmatched_ru"]

    uncapped = BilingualAligner(
        model=StubModel(vectors),
        max_window=2,
        similarity_threshold=0.55,
        skip_penalty=-0.5,
        max_total_span=3,
    ).align_lists(["One"], ["Part1", "Part2"])

    assert uncapped["matches"] == [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 2, "score": 0.7492687106132507}
    ], f"1:2 should match under the higher cap, got {uncapped['matches']}"
    assert uncapped["unmatched_en"] == [], uncapped["unmatched_en"]
    assert uncapped["unmatched_ru"] == [], uncapped["unmatched_ru"]

    return "OK: span cap rejects 1:2 at max_total_span=2, allows it at 3"


def embedding_cache_reuses_vectors_across_calls():
    model = StubModel({
        "One": [1, 0, 0],
        "Two": [0, 1, 0],
        "One Two": [1, 1, 0],
    })

    aligner = BilingualAligner(model=model, max_window=2)

    en = ["One", "Two"]
    ru = ["One", "Two"]

    first = aligner.align_lists(en, ru)
    first_calls = model.encode_calls
    second = aligner.align_lists(en, ru)

    assert first["matches"] == second["matches"]
    assert model.encode_calls == first_calls, (
        f"second align should hit the cache, encode_calls {first_calls} -> {model.encode_calls}"
    )

    return f"OK: second align reused cached embeddings (0 new encode calls after {first_calls})"


def main() -> int:
    checks = [
        skips_an_unmatchable_sentence_instead_of_force_matching,
        still_force_matches_when_skipping_is_disabled,
        span_cap_rejects_oversized_edges,
        embedding_cache_reuses_vectors_across_calls,
    ]

    failures = []

    for check in checks:
        try:
            print(check())
        except AssertionError as exc:
            failures.append(f"{check.__name__}: {exc}")
            print(f"FAIL: {check.__name__}: {exc}")

    if failures:
        print(f"\n{len(failures)} failure(s)")
        return 1

    print(f"\nOK: {len(checks)} checks passed")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
