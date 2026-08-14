"""Regression tests for the bilingual aligner (stub model).

Guards the aligner-precision work (Aug 2026): the DP force-aligned every
sentence, so a sentence with no counterpart was matched to an unrelated window
(a <0.6 garbage meaning match). The skip branch lets the DP consume a sentence
without emitting a match; the span cap rejects 1:5 / 5:1 edges; the embedding
cache stops re-encoding identical window texts across chunks/entities. Later
plans add the high-confidence prepass anchors + sub-pools (plan 03), diagonal
banding (plan 04), aggregate window embedding (plan 05), and hard landmark
pins (plan 06) — hard human-made commits emitted verbatim with score 1.0 that
split sub-pools and are never crossed or overlapped by machine output.

Uses a stub model with hand-picked vectors so no weights are needed. Run from
anywhere (adds the package root to sys.path):

    docker exec ext_python python /app/ai/alignment/test_aligner.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

import numpy as np
import torch
from sentence_transformers import util

from ai.alignment import bilingual_aligner
from ai.alignment.bilingual_aligner import (
    BilingualAligner,
    _normalize_sentences,
    _normalize_text,
)


def N(text: str) -> str:
    """Normalize a stub vector key exactly as the aligner does for window texts."""
    return _normalize_text(text)


def normalized(vector):
    return list(np.asarray(vector, dtype=float) / np.linalg.norm(vector))


def pooled_windows(en, ru, singles, dim):
    """All pooled-mean window vectors (steps 1..len) for both sides.

    Provides the joined-mode vectors: `joined` embedders look up joined window
    texts, so every text a banded run might join must be present in the stub.
    Extra entries are harmless; only the texts the aligner actually embeds are
    looked up. The helper's uniform mean also matches `aggregate` for the
    equal-length sentences these tests use, so the scores they assert stay
    valid under the default mode.
    """
    vectors = {}
    for pair in (en, ru):
        for start in range(len(pair)):
            for step in range(1, len(pair) + 1):
                if start + step <= len(pair):
                    words = pair[start : start + step]
                    pooled = [
                        sum(singles[N(w)][d] for w in words) / len(words)
                        for d in range(dim)
                    ]
                    vectors[N(" ".join(words))] = normalized(pooled)
    return vectors


class StubModel:
    """Deterministic stand-in for SentenceTransformer.

    Maps each joined-window text to a fixed (already normalized) vector, and
    counts encode() calls + texts so embedding behavior can be asserted.
    """

    def __init__(self, vectors: dict[str, list[float]]):
        self.vectors = {
            text: torch.tensor(normalized(vector), dtype=torch.float32)
            for text, vector in vectors.items()
        }
        self.encode_calls = 0
        self.encode_total = 0

    def encode(self, texts, **kwargs):
        self.encode_calls += 1
        self.encode_total += len(texts)
        return torch.stack([self.vectors[text] for text in texts])


def reset_cache():
    bilingual_aligner._EMBEDDING_CACHE._entries.clear()


def skips_an_unmatchable_sentence_instead_of_force_matching():
    # EN = [Cat, Extra], RU = [Cat translation, D]. "Extra" has no counterpart.
    # Old DP: 1:1 Cat + 1:1 Extra (garbage, score 0). New DP: 1:1 Cat + skip.
    model = StubModel({
        N("Cat"): [1, 0, 0, 0],
        N("Extra"): [0, 1, 0, 0],
        N("Cat Extra"): [0.5, 0, 1, 0],
        N("Cat translation"): [1, 0, 0, 0],
        N("Cat translation D"): [0, 0, 0, 1],
        N("D"): [0, 0, 0, 1],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        skip_penalty=-0.5,
        max_total_span=6,
        algorithm="dp",
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
        N("Cat"): [1, 0, 0, 0],
        N("Extra"): [0, 1, 0, 0],
        N("Cat Extra"): [0.5, 0, 1, 0],
        N("Cat translation"): [1, 0, 0, 0],
        N("Cat translation D"): [0, 0, 0, 1],
        N("D"): [0, 0, 0, 1],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        skip_penalty=-100.0,
        max_total_span=6,
        algorithm="dp",
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
        N("One"): [1, 0.8, 0.8],
        N("Part1"): [0, 1, 0],
        N("Part2"): [0, 0, 1],
        N("Part1 Part2"): [0, 1, 1],
    }

    capped = BilingualAligner(
        model=StubModel(vectors),
        max_window=2,
        similarity_threshold=0.55,
        skip_penalty=-0.5,
        max_total_span=2,
        algorithm="dp",
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
        algorithm="dp",
    ).align_lists(["One"], ["Part1", "Part2"])

    assert_same_matches(uncapped["matches"], [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 2, "score": 0.7492687106132507},
    ]), f"1:2 should match under the higher cap, got {uncapped['matches']}"
    assert uncapped["unmatched_en"] == [], uncapped["unmatched_en"]
    assert uncapped["unmatched_ru"] == [], uncapped["unmatched_ru"]

    return "OK: span cap rejects 1:2 at max_total_span=2, allows it at 3"


def embedding_cache_reuses_vectors_across_calls():
    model = StubModel({
        N("One"): [1, 0, 0],
        N("Two"): [0, 1, 0],
        N("One Two"): [1, 1, 0],
    })

    aligner = BilingualAligner(model=model, max_window=2, algorithm="dp")

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


def assert_same_matches(actual, expected):
    assert len(actual) == len(expected), f"{actual} != {expected}"
    for got, want in zip(actual, expected):
        for key in ("en_start", "en_end", "ru_start", "ru_end"):
            assert got[key] == want[key], f"{key}: {got} != {want}"
        assert abs(got["score"] - want["score"]) < 1e-4, f"score: {got} != {want}"


def greedy_matches_dp_on_clean_list_with_far_fewer_encodes():
    reset_cache()
    # Identity-ish sentence matrix: every diagonal pair is a confident 1:1.
    # Pooled window vectors are still present so the DP would have something
    # to embed if a pool needed them — here no pool does.
    en = ["Alpha1", "Alpha2", "Alpha3"]
    ru = ["Beta1", "Beta2", "Beta3"]

    singles = {
        "Alpha1": [1, 0, 0, 1, 0, 0],
        "Beta1": [1, 0, 0, 1, 0, 0],
        "Alpha2": [0, 1, 0, 0, 1, 0],
        "Beta2": [0, 1, 0, 0, 1, 0],
        "Alpha3": [0, 0, 1, 0, 0, 1],
        "Beta3": [0, 0, 1, 0, 0, 1],
    }

    vectors = {}
    for start in range(len(en)):
        for step in range(1, 4):
            if start + step <= len(en):
                for pair in (en, ru):
                    words = pair[start : start + step]
                    pooled = [
                        sum(singles[w][dim] for w in words) / len(words)
                        for dim in range(6)
                    ]
                    vectors[N(" ".join(words))] = normalized(pooled)

    greedy_model = StubModel(vectors)
    greedy = BilingualAligner(
        model=greedy_model,
        max_window=3,
        similarity_threshold=0.55,
        algorithm="greedy",
    ).align_lists(en, ru)

    dp_model = StubModel(vectors)
    dp = BilingualAligner(
        model=dp_model,
        max_window=3,
        similarity_threshold=0.55,
        algorithm="dp",
    ).align_lists(en, ru)

    expected = [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 1.0},
        {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 2, "score": 1.0},
        {"en_start": 2, "en_end": 3, "ru_start": 2, "ru_end": 3, "score": 1.0},
    ]

    assert_same_matches(greedy["matches"], expected)
    assert_same_matches(dp["matches"], expected)

    # Greedy locks the three diagonal anchors, so the gap ladder never runs:
    # exactly the 6 singles are encoded. The DP's prepass now also embeds only
    # the 6 singles (plan 04 dropped the up-front (n + m) * max_window window
    # precompute); the high-confidence prepass splits the chunk into 1x1 pools
    # whose in-band windows are the cached singles, so no multi-window vector
    # is ever built.
    assert greedy_model.encode_total == 6, (
        f"greedy should embed only the 6 singles, encoded {greedy_model.encode_total}"
    )
    assert dp_model.encode_total == 6, (
        f"dp should embed only the 6 singles too, encoded {dp_model.encode_total}"
    )

    return f"OK: greedy == dp on a clean list, encoding {greedy_model.encode_total} texts vs {dp_model.encode_total}"


def greedy_resolves_a_1_to_2_match_via_lazy_window_expansion():
    reset_cache()
    # "Gamma" only matches the pooled "Delta1 Delta2" (1:2), not either part.
    # The span cap must allow en_step + ru_step = 3 for it to be legal.
    model = StubModel({
        N("Gamma"): [1, 0.8, 0.8],
        N("Delta1"): [0, 1, 0],
        N("Delta2"): [0, 0, 1],
        N("Delta1 Delta2"): [0, 1, 1],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        max_total_span=3,
        algorithm="greedy",
    )

    result = aligner.align_lists(["Gamma"], ["Delta1", "Delta2"])

    assert_same_matches(result["matches"], [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 2, "score": 0.7492687106132507},
    ]), f"1:2 should match under the span cap, got {result['matches']}"
    assert result["unmatched_en"] == [], result["unmatched_en"]
    assert result["unmatched_ru"] == [], result["unmatched_ru"]
    # Only the 3 singles are embedded: the pooled window is aggregated from the
    # cached per-sentence vectors, never encoded as a joined text.
    assert model.encode_total == 3, f"expected 3 encoded singles, got {model.encode_total}"

    return f"OK: greedy 1:2 matched via lazy expansion ({model.encode_total} texts encoded)"


def greedy_skips_an_unmatchable_sentence():
    reset_cache()
    # "Extra" has no counterpart; greedy must skip it instead of force-matching.
    model = StubModel({
        N("Epsilon Cat"): [1, 0, 0, 0],
        N("Epsilon Extra"): [0, 1, 0, 0],
        N("Cat translation"): [1, 0, 0, 0],
        N("D"): [0, 0, 0, 1],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        max_total_span=6,
        algorithm="greedy",
    )

    result = aligner.align_lists(["Epsilon Cat", "Epsilon Extra"], ["Cat translation", "D"])

    assert result["matches"] == [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 1.0}
    ], f"unexpected greedy matches: {result['matches']}"
    assert result["unmatched_en"] == [1], result["unmatched_en"]
    assert result["unmatched_ru"] == [1], result["unmatched_ru"]
    # No expansions were needed: exactly the 4 singles.
    assert model.encode_total == 4, f"expected 4 encoded texts, got {model.encode_total}"

    return "OK: greedy skips an unmatchable sentence, not force-matching it"


def greedy_anchors_then_resolves_a_gap_after_a_locked_pair():
    reset_cache()
    # "Zeta Start" <-> "Eta Start" is a confident anchor; the gap that follows
    # ("Zeta One" vs "Eta P1" + "Eta P2") resolves as a 1:2 expansion.
    model = StubModel({
        N("Zeta Start"): [1, 0, 0, 0, 0, 0],
        N("Eta Start"): [1, 0, 0, 0, 0, 0],
        N("Zeta One"): [0, 1, 0, 0.8, 0.8, 0],
        N("Eta P1"): [0, 0, 0, 1, 0, 0],
        N("Eta P2"): [0, 0, 0, 0, 1, 0],
        N("Eta P1 Eta P2"): [0, 0, 0, 1, 1, 0],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        max_total_span=3,
        algorithm="greedy",
    )

    result = aligner.align_lists(
        ["Zeta Start", "Zeta One"],
        ["Eta Start", "Eta P1", "Eta P2"],
    )

    assert_same_matches(result["matches"], [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 1.0},
        {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 3, "score": 0.7492687106132507},
    ]), f"unexpected greedy matches: {result['matches']}"
    assert result["unmatched_en"] == [], result["unmatched_en"]
    assert result["unmatched_ru"] == [], result["unmatched_ru"]

    return "OK: greedy locked the anchor then resolved the gap with a 1:2 window"


def greedy_merges_an_orphan_into_a_beating_pooled_window():
    reset_cache()
    # Anchor-first greedy locks the 1:1 (Alpha One <-> Beta One at ~0.701 —
    # mutually-best in the window and above ALIGN_ANCHOR_THRESHOLD) and
    # pre-commits, so the cursor jumps past the trailing gap and Alpha Two is
    # left unmatched even though the pooled 2:1 window scores ~0.743, higher
    # than the 1:1. The orphan-merge post-pass must extend the match over that
    # orphan and keep it, because the pooled score beats the match's by more
    # than merge_margin.
    model = StubModel({
        N("Alpha One"): [1, 0, 0],
        N("Alpha Two"): [0, 1, 0],
        N("Beta One"): [0.701, 0.35, 0.6214],
        N("Alpha One Alpha Two"): [1, 1, 0],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        anchor_threshold=0.6,
        merge_margin=0.02,
        max_total_span=3,
        algorithm="greedy",
    )

    result = aligner.align_lists(["Alpha One", "Alpha Two"], ["Beta One"])

    assert_same_matches(result["matches"], [
        {"en_start": 0, "en_end": 2, "ru_start": 0, "ru_end": 1, "score": 0.7431547505135213},
    ])
    assert result["unmatched_en"] == [], result["unmatched_en"]
    assert result["unmatched_ru"] == [], result["unmatched_ru"]
    # 3 singles (greedy); the pooled 2:1 window is aggregated from the cached
    # singles, so no joined window text is ever encoded.
    assert model.encode_total == 3, f"expected 3 encoded texts, got {model.encode_total}"

    # The margin guard: when the pooled window does NOT beat the 1:1 by
    # merge_margin, the orphan stays unmatched and the anchor is untouched.
    guarded_model = StubModel({
        N("Alpha One"): [1, 0, 0],
        N("Alpha Two"): [0, 1, 0],
        N("Beta One"): [0.8, 0.2, 0.56],
        N("Alpha One Alpha Two"): [1, 1, 0],
    })

    guarded = BilingualAligner(
        model=guarded_model,
        max_window=2,
        similarity_threshold=0.55,
        anchor_threshold=0.6,
        merge_margin=0.02,
        max_total_span=3,
        algorithm="greedy",
    ).align_lists(["Alpha One", "Alpha Two"], ["Beta One"])

    assert_same_matches(guarded["matches"], [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 0.8025723539051279},
    ])
    assert guarded["unmatched_en"] == [1], guarded["unmatched_en"]
    assert guarded["unmatched_ru"] == [], guarded["unmatched_ru"]

    return "OK: single-sided orphan merged into the match when the pooled window clears the margin"


def greedy_ladder_widens_past_primary_for_a_4_window_match():
    reset_cache()
    # No combo up to primary_window=3 clears the 0.65 bar (best is the 1:3 at
    # ~0.612); only the 1:4 pooled window reaches ~0.707, so the ladder must
    # widen one step per side past primary to resolve it.
    model = StubModel({
        N("Theta One"): [1, 0.5, 0.5, 0.5, 0.5],
        N("Iota1"): [0, 1, 0, 0, 0],
        N("Iota2"): [0, 0, 1, 0, 0],
        N("Iota3"): [0, 0, 0, 1, 0],
        N("Iota4"): [0, 0, 0, 0, 1],
        N("Iota1 Iota2"): [0, 0.5, 0.5, 0, 0],
        N("Iota1 Iota2 Iota3"): [0, 1 / 3, 1 / 3, 1 / 3, 0],
        N("Iota1 Iota2 Iota3 Iota4"): [0, 0.25, 0.25, 0.25, 0.25],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=4,
        primary_window=3,
        similarity_threshold=0.65,
        max_total_span=8,
        algorithm="greedy",
    )

    result = aligner.align_lists(["Theta One"], ["Iota1", "Iota2", "Iota3", "Iota4"])

    assert len(result["matches"]) == 1, result["matches"]
    assert result["matches"][0]["en_start"] == 0
    assert result["matches"][0]["en_end"] == 1
    assert result["matches"][0]["ru_start"] == 0
    assert result["matches"][0]["ru_end"] == 4
    assert abs(result["matches"][0]["score"] - 0.70710678) < 1e-4, result["matches"]
    assert result["unmatched_en"] == [], result["unmatched_en"]
    assert result["unmatched_ru"] == [], result["unmatched_ru"]
    # Only the 5 singles are embedded: the 1:2/1:3/1:4 windows are aggregated
    # from the cached per-sentence vectors, never encoded as joined texts.
    assert model.encode_total == 5, f"expected 5 encoded texts, got {model.encode_total}"

    return f"OK: ladder widened past primary to a 1:4 match ({model.encode_total} texts encoded)"


def normalized_windows_resolve_the_whistler_pair():
    reset_cache()
    # The whistler / «СВИСТУН» regression: LaBSE scores the raw pair ~0.32
    # (below ALIGN_DEFAULT_THRESHOLD 0.55) but the casefolded/alnum-only form
    # ~0.685. Embedding normalized windows is the fix — the two sentences must
    # align 1:1 here, which fails if the raw texts are embedded instead.
    assert _normalize_text("The whistler") == "the whistler"
    assert _normalize_text("«СВИСТУН»") == "свистун"
    assert _normalize_text("  A\tB   c ") == "a b c"

    model = StubModel({
        N("The whistler"): [1, 0.8],
        N("«СВИСТУН»"): [1, 0.8],
    })

    result = BilingualAligner(
        model=model,
        similarity_threshold=0.55,
        algorithm="greedy",
    ).align_lists(["The whistler"], ["«СВИСТУН»"])

    assert result["matches"] == [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 1.0}
    ], f"normalized windows should match 1:1, got {result['matches']}"
    assert result["unmatched_en"] == [], result["unmatched_en"]
    assert result["unmatched_ru"] == [], result["unmatched_ru"]

    return "OK: normalized windows resolve the whistler pair as a confident 1:1"


def normalize_sentences_strips_punctuation_case_and_collapses_whitespace():
    reset_cache()
    # Up-front normalization contract: each sentence is casefolded, reduced to
    # alnum + whitespace, and whitespace collapsed — while list length and
    # index positions are preserved.
    assert _normalize_sentences(["The whistler", "«СВИСТУН»"]) == [
        "the whistler",
        "свистун",
    ]
    assert _normalize_sentences(["  A\tB   c ", "Hello, World!", "!!!"]) == [
        "a b c",
        "hello world",
        "",
    ]

    # The known whistler pair scores >= 0.68 in its normalized form. The stub
    # keys the raw forms to near-orthogonal vectors and the normalized forms to
    # nearly-identical ones, so the >= 0.68 match only appears when the aligner
    # embeds the up-front normalized text (raw embeddings would score ~0).
    model = StubModel({
        "The whistler": [1, 0, 0],
        "«СВИСТУН»": [0, 1, 0],
        N("The whistler"): [1, 0.8],
        N("«СВИСТУН»"): [1, 0.8],
    })

    result = BilingualAligner(
        model=model,
        similarity_threshold=0.55,
        algorithm="greedy",
    ).align_lists(["The whistler"], ["«СВИСТУН»"])

    assert len(result["matches"]) == 1, result["matches"]
    assert result["matches"][0]["score"] >= 0.68, result["matches"]
    assert result["unmatched_en"] == [], result["unmatched_en"]
    assert result["unmatched_ru"] == [], result["unmatched_ru"]

    return "OK: _normalize_sentences strips/casefolds/collapses; whistler pair scores >= 0.68 normalized"


def align_lists_raw_and_pre_normalized_input_are_identical():
    reset_cache()
    # Normalization is idempotent, so submitting raw text and its normalized
    # form must produce identical alignment results (same scores, same
    # unmatched) — callers may pre-normalize (or not) without changing output.
    raw_en = ["Alpha One", "Alpha Two!"]
    raw_ru = ["Beta One", "BETA TWO"]
    norm_en = _normalize_sentences(raw_en)
    norm_ru = _normalize_sentences(raw_ru)

    model = StubModel({
        N("Alpha One"): [1, 0, 0],
        N("Alpha Two"): [0, 1, 0],
        N("Beta One"): [1, 0, 0],
        N("Beta Two"): [0, 1, 0],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        algorithm="greedy",
    )

    from_raw = aligner.align_lists(raw_en, raw_ru)
    from_norm = aligner.align_lists(norm_en, norm_ru)

    assert_same_matches(from_raw["matches"], from_norm["matches"])
    assert from_raw["unmatched_en"] == from_norm["unmatched_en"], (
        from_raw["unmatched_en"],
        from_norm["unmatched_en"],
    )
    assert from_raw["unmatched_ru"] == from_norm["unmatched_ru"], (
        from_raw["unmatched_ru"],
        from_norm["unmatched_ru"],
    )
    # Raw and pre-normalized inputs hash to the same cache keys: the second
    # align adds zero encodes (only the 4 singles were ever embedded).
    assert model.encode_total == 4, f"expected 4 encoded texts, got {model.encode_total}"

    return "OK: raw vs pre-normalized input aligns identically (0 extra encodes)"


def prepass_anchors_lock_high_confidence_pairs_for_greedy_and_dp():
    reset_cache()
    # A1/A2/B1 are confident 1:1s at 1.0 (>= ALIGN_HIGH_CONFIDENCE, so the
    # prepass locks them as anchors); M is a 1:1 at ~0.85 (below the bar, so it
    # is resolved inside its pool). Both algorithms must emit the identical
    # anchor set with the cell scores.
    en = ["A1", "A2", "M", "B1"]
    ru = ["R_A1", "R_A2", "R_M", "R_B1"]

    vectors = {
        N("A1"): [1, 0, 0, 0, 0, 0],
        N("R_A1"): [1, 0, 0, 0, 0, 0],
        N("A2"): [0, 1, 0, 0, 0, 0],
        N("R_A2"): [0, 1, 0, 0, 0, 0],
        N("M"): [0, 0, 1, 0, 0, 0],
        N("R_M"): [0, 0, 0.85, 0.5268, 0, 0],
        N("B1"): [0, 0, 0, 1, 0, 0],
        N("R_B1"): [0, 0, 0, 1, 0, 0],
        # Multi-sentence windows the DP embeds (never used by the pool DPs here,
        # but the stub requires every embedded text to be known).
        N("A1 A2"): [0.5, 0.5, 0, 0, 0, 0],
        N("A2 M"): [0, 0.5, 0.5, 0, 0, 0],
        N("M B1"): [0, 0, 0.5, 0.5, 0, 0],
        N("R_A1 R_A2"): [0.5, 0.5, 0, 0, 0, 0],
        N("R_A2 R_M"): [0, 0.5, 0.425, 0.2634, 0, 0],
        N("R_M R_B1"): [0, 0, 0.425, 0.5, 0, 0],
    }

    greedy = BilingualAligner(
        model=StubModel(vectors),
        max_window=2,
        similarity_threshold=0.55,
        algorithm="greedy",
    ).align_lists(en, ru)
    dp = BilingualAligner(
        model=StubModel(vectors),
        max_window=2,
        similarity_threshold=0.55,
        algorithm="dp",
    ).align_lists(en, ru)

    expected = [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 1.0},
        {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 2, "score": 1.0},
        {"en_start": 2, "en_end": 3, "ru_start": 2, "ru_end": 3, "score": 0.85},
        {"en_start": 3, "en_end": 4, "ru_start": 3, "ru_end": 4, "score": 1.0},
    ]
    assert_same_matches(greedy["matches"], expected)
    assert_same_matches(dp["matches"], expected)
    assert greedy["matches"] == dp["matches"], "anchor set must be identical"
    assert greedy["unmatched_en"] == [] and greedy["unmatched_ru"] == []
    assert dp["unmatched_en"] == [] and dp["unmatched_ru"] == []

    return "OK: >= high_confidence pairs locked as identical anchors by greedy and dp"


def prepass_anchors_split_pools_so_no_match_crosses_in_document_order():
    reset_cache()
    # "Two" <-> "Two'" is a 1:1 at 1.0 -> prepass anchor at (1, 1). The pool
    # before it ("One" <-> "A", 0.8) and the pool after it ("Three" <-> "C",
    # 0.85) must stay confined to their slices: no match may consume sentences
    # on both sides of the anchor, and the anchor match keeps document order.
    en = ["One", "Two", "Three"]
    ru = ["A", "Two'", "C"]

    vectors = {
        N("One"): [1, 0, 0, 0, 0],
        N("A"): [0.8, 0.6, 0, 0, 0],
        N("Two"): [0, 0, 1, 0, 0],
        N("Two'"): [0, 0, 1, 0, 0],
        N("Three"): [0, 0, 0, 0.85, 0.5268],
        N("C"): [0, 0, 0, 1, 0],
        # Multi-sentence windows the DP embeds.
        N("One Two"): [0.5, 0, 0.5, 0, 0],
        N("Two Three"): [0, 0, 0.5, 0.425, 0.2634],
        N("A Two'"): [0.4, 0.3, 0.5, 0, 0],
        N("Two' C"): [0, 0, 0.5, 0.5, 0],
    }

    anchors = [(1, 1)]

    for algorithm in ("greedy", "dp"):
        aligner = BilingualAligner(
            model=StubModel(vectors),
            max_window=2,
            similarity_threshold=0.55,
            algorithm=algorithm,
        )
        result = aligner.align_lists(en, ru)

        assert_same_matches(result["matches"], [
            {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 0.8},
            {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 2, "score": 1.0},
            {"en_start": 2, "en_end": 3, "ru_start": 2, "ru_end": 3, "score": 0.85},
        ])
        assert result["unmatched_en"] == [], result["unmatched_en"]
        assert result["unmatched_ru"] == [], result["unmatched_ru"]

        # Anchors appear as matches in document order, each exactly at its cell.
        anchor_matches = [
            (m["en_start"], m["ru_start"])
            for m in result["matches"]
            if m["en_end"] - m["en_start"] == 1
            and m["ru_end"] - m["ru_start"] == 1
            and abs(m["score"] - 1.0) < 1e-4
        ]
        assert anchor_matches == anchors, f"{algorithm}: {anchor_matches}"

        # No non-anchor match contains the anchor cell (a crossing match would).
        for m in result["matches"]:
            contains_anchor = (
                m["en_start"] <= 1 < m["en_end"] and m["ru_start"] <= 1 < m["ru_end"]
            )
            is_anchor = m["en_start"] == 1 and m["ru_start"] == 1
            assert contains_anchor == is_anchor, f"{algorithm}: {m} crosses an anchor"

    return "OK: pools stay between anchors, anchor order == document order (greedy and dp)"


def high_confidence_knob_controls_the_number_of_prepass_anchors():
    reset_cache()
    # A1/A2 are 1:1 at 1.0; B1/B2 at ~0.85. Lowering high_confidence must lock
    # more of them as prepass anchors; raising it locks fewer.
    en = ["A1", "A2", "B1", "B2"]
    ru = ["R_A1", "R_A2", "R_B1", "R_B2"]

    model = StubModel({
        N("A1"): [1, 0, 0, 0, 0, 0],
        N("R_A1"): [1, 0, 0, 0, 0, 0],
        N("A2"): [0, 1, 0, 0, 0, 0],
        N("R_A2"): [0, 1, 0, 0, 0, 0],
        N("B1"): [0, 0, 1, 0, 0, 0],
        N("R_B1"): [0, 0, 0.85, 0.5268, 0, 0],
        N("B2"): [0, 0, 0, 1, 0, 0],
        N("R_B2"): [0, 0, 0, 0.85, 0.5268, 0],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        algorithm="greedy",
    )
    norm_en = _normalize_sentences(en)
    norm_ru = _normalize_sentences(ru)
    _, en_embs = aligner._generate_sentence_embeddings(norm_en)
    _, ru_embs = aligner._generate_sentence_embeddings(norm_ru)
    sim = util.cos_sim(en_embs, ru_embs).cpu().numpy()

    aligner.high_confidence = 1.01
    none = aligner._prepass_anchors(sim, len(en), len(ru))
    aligner.high_confidence = 0.9
    two = aligner._prepass_anchors(sim, len(en), len(ru))
    aligner.high_confidence = 0.8
    four = aligner._prepass_anchors(sim, len(en), len(ru))

    assert none == [], f"above 1.0 nothing should anchor, got {none}"
    assert two == [(0, 0), (1, 1)], f"0.9 locks the 1.0 pairs, got {two}"
    assert four == [(0, 0), (1, 1), (2, 2), (3, 3)], f"0.8 locks the 0.85 pairs too, got {four}"

    return "OK: lowering high_confidence produces more anchors, raising produces fewer"


def band_rejects_an_out_of_band_pair():
    reset_cache()
    # 4 EN vs 1 RU: k = 4, so only starts i with |0*4 - i| <= band are in-band.
    # E4 <-> R1 scores 0.6 (> threshold) but sits at (3, 0), three cells off
    # the expected diagonal: with band_width=1 the pair is rejected, with a
    # wide band the same pair matches. Both algorithms must agree.
    en = ["E1", "E2", "E3", "E4"]
    ru = ["R1"]

    singles = {
        N("E1"): [1, 0, 0, 0, 0, 0],
        N("E2"): [0, 1, 0, 0, 0, 0],
        N("E3"): [0, 0, 1, 0, 0, 0],
        N("E4"): [0, 0, 0, 0.6, 0.8, 0],
        N("R1"): [0, 0, 0, 1, 0, 0],
    }
    vectors = dict(singles)
    vectors.update(pooled_windows(en, ru, singles, 6))

    for algorithm in ("dp", "greedy"):
        tight = BilingualAligner(
            model=StubModel(dict(vectors)),
            max_window=4,
            similarity_threshold=0.55,
            skip_penalty=-0.2,
            algorithm=algorithm,
            band_width=1,
        ).align_lists(en, ru)

        assert tight["matches"] == [], f"{algorithm}: {tight['matches']}"
        assert tight["unmatched_en"] == [0, 1, 2, 3], tight["unmatched_en"]
        assert tight["unmatched_ru"] == [0], tight["unmatched_ru"]

        wide = BilingualAligner(
            model=StubModel(dict(vectors)),
            max_window=4,
            similarity_threshold=0.55,
            skip_penalty=-0.2,
            algorithm=algorithm,
            band_width=10,
        ).align_lists(en, ru)

        assert_same_matches(
            wide["matches"],
            [{"en_start": 3, "en_end": 4, "ru_start": 0, "ru_end": 1, "score": 0.6}],
        ), f"{algorithm}: {wide['matches']}"
        assert wide["unmatched_en"] == [0, 1, 2], wide["unmatched_en"]
        assert wide["unmatched_ru"] == [], wide["unmatched_ru"]

    return "OK: out-of-band pair rejected by a tight band, accepted by a wide one"


def band_allows_an_in_band_pair():
    reset_cache()
    # 2 EN vs 1 RU: k = 2, so starts i with |0*2 - i| <= band are in-band.
    # E2 <-> R1 scores 1.0 at (1, 0), exactly on the band edge (band_width=1);
    # E1 is orthogonal to everything and stays unmatched. The 1:2 pooled
    # window scores 0.707 (< threshold 0.8), so only the clean 1:1 clears.
    en = ["E1", "E2"]
    ru = ["R1"]

    singles = {
        N("E1"): [0, 1, 0, 0],
        N("E2"): [1, 0, 0, 0],
        N("R1"): [1, 0, 0, 0],
    }
    vectors = dict(singles)
    vectors.update(pooled_windows(en, ru, singles, 4))

    for algorithm in ("dp", "greedy"):
        result = BilingualAligner(
            model=StubModel(dict(vectors)),
            max_window=2,
            similarity_threshold=0.8,
            skip_penalty=-0.5,
            algorithm=algorithm,
            band_width=1,
        ).align_lists(en, ru)

        assert_same_matches(
            result["matches"],
            [{"en_start": 1, "en_end": 2, "ru_start": 0, "ru_end": 1, "score": 1.0}],
        ), f"{algorithm}: {result['matches']}"
        assert result["unmatched_en"] == [0], result["unmatched_en"]
        assert result["unmatched_ru"] == [], result["unmatched_ru"]

    return "OK: in-band pair accepted at the band edge"


def band_recovery_across_a_divergent_region():
    reset_cache()
    # Two confident 1:1s at the chunk corners (E1<->R1, E4<->R4) with a fully
    # orthogonal middle. The walk must not be lured into a wrong pooled-window
    # match (threshold 0.8 keeps pooled windows of orthogonal sentences — cos
    # ~0.35-0.5 — below the bar) and must still recover to the far diagonal
    # pair, exactly as an unbounded run would. Default band (max(2, max_window))
    # applies, so this guards the derived-band default, not an explicit knob.
    en = ["E1", "E2", "E3", "E4"]
    ru = ["R1", "R2", "R3", "R4"]

    singles = {
        N("E1"): [1, 0, 0, 0, 0, 0],
        N("R1"): [1, 0, 0, 0, 0, 0],
        N("E2"): [0, 1, 0, 0, 0, 0],
        N("R2"): [0, 0, 1, 0, 0, 0],
        N("E3"): [0, 0, 0, 1, 0, 0],
        N("R3"): [0, 0, 0, 0, 1, 0],
        N("E4"): [0, 0, 0, 0, 0, 1],
        N("R4"): [0, 0, 0, 0, 0, 1],
    }
    vectors = dict(singles)
    vectors.update(pooled_windows(en, ru, singles, 6))

    results = {}
    for algorithm in ("dp", "greedy"):
        results[algorithm] = BilingualAligner(
            model=StubModel(dict(vectors)),
            max_window=2,
            similarity_threshold=0.8,
            skip_penalty=-0.2,
            algorithm=algorithm,
        ).align_lists(en, ru)

    expected = [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 1.0},
        {"en_start": 3, "en_end": 4, "ru_start": 3, "ru_end": 4, "score": 1.0},
    ]
    assert_same_matches(results["dp"]["matches"], expected), results["dp"]["matches"]
    assert_same_matches(results["greedy"]["matches"], expected), results["greedy"]["matches"]
    assert results["dp"]["unmatched_en"] == [1, 2], results["dp"]["unmatched_en"]
    assert results["dp"]["unmatched_ru"] == [1, 2], results["dp"]["unmatched_ru"]
    assert results["greedy"]["unmatched_en"] == [1, 2], results["greedy"]["unmatched_en"]
    assert results["greedy"]["unmatched_ru"] == [1, 2], results["greedy"]["unmatched_ru"]

    return "OK: banded walk recovers to the far diagonal pair across a divergent middle"


def band_width_knob_controls_match_density():
    reset_cache()
    # 6x6 with two genuine pairs: E1<->R1 at (0, 0) = 1.0 and E4<->R2 at
    # (3, 1) = 0.9. high_confidence=0.99 locks only the (0,0) pair as a prepass
    # anchor, leaving E4<->R2 to the pool — where it sits at pool offset
    # (2, 0), exactly the band_width=2 edge. A 1-wide band drops it, a 2-wide
    # band admits it. threshold 0.8 keeps the pooled windows (max cos 0.636
    # against the genuine partners) out of the picture entirely.
    en = ["E1", "E2", "E3", "E4", "E5", "E6"]
    ru = ["R1", "R2", "R3", "R4", "R5", "R6"]

    singles = {
        N("E1"): [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        N("R1"): [1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        N("E2"): [0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        N("E3"): [0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0],
        N("E4"): [0, 0, 0, 0.9, 0.436, 0, 0, 0, 0, 0, 0, 0],
        N("E5"): [0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0],
        N("E6"): [0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0],
        N("R2"): [0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0],
        N("R3"): [0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0],
        N("R4"): [0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0],
        N("R5"): [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0],
        N("R6"): [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1],
    }
    vectors = dict(singles)
    vectors.update(pooled_windows(en, ru, singles, 12))

    both = [{"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 1.0}]
    both.append({"en_start": 3, "en_end": 4, "ru_start": 1, "ru_end": 2, "score": 0.9})

    for algorithm in ("dp", "greedy"):
        tight = BilingualAligner(
            model=StubModel(dict(vectors)),
            max_window=3,
            similarity_threshold=0.8,
            skip_penalty=-0.1,
            algorithm=algorithm,
            high_confidence=0.99,
            band_width=1,
        ).align_lists(en, ru)
        assert_same_matches(tight["matches"], [both[0]]), f"{algorithm}: {tight['matches']}"

        for band, expect in ((2, both), (10, both)):
            wide = BilingualAligner(
                model=StubModel(dict(vectors)),
                max_window=3,
                similarity_threshold=0.8,
                skip_penalty=-0.1,
                algorithm=algorithm,
                high_confidence=0.99,
                band_width=band,
            ).align_lists(en, ru)
            assert_same_matches(wide["matches"], expect), (
                f"{algorithm} band={band}: {wide['matches']}"
            )

    return "OK: band_width knob controls match density (1 vs 2 matches), dp == greedy"


def banding_reduces_joined_window_embeddings_but_aggregate_only_embeds_singles():
    reset_cache()
    # 6 EN vs 1 RU, every sentence orthogonal (R1 in its own dim), threshold
    # 0.99 so nothing ever matches. Two window_embed modes behave differently:
    #  - `joined`: window texts are join-then-embedded, so banding still
    #    suppresses the out-of-band window joins. With band_width=1 the DP only
    #    embeds windows starting in-band (starts {0, 1}: 11 texts); with a huge
    #    band it embeds every window (16 texts). The 7 prepass singles are
    #    cached across both, so the delta is exactly the out-of-band windows.
    #  - `aggregate` (default): only the 7 singles are ever embedded — every
    #    window is combined from the cached per-sentence vectors, so the band
    #    has nothing left to suppress (7 encodes either way).
    en = ["E1", "E2", "E3", "E4", "E5", "E6"]
    ru = ["R1"]

    singles = {
        N("E1"): [1, 0, 0, 0, 0, 0, 0],
        N("E2"): [0, 1, 0, 0, 0, 0, 0],
        N("E3"): [0, 0, 1, 0, 0, 0, 0],
        N("E4"): [0, 0, 0, 1, 0, 0, 0],
        N("E5"): [0, 0, 0, 0, 1, 0, 0],
        N("E6"): [0, 0, 0, 0, 0, 1, 0],
        N("R1"): [0, 0, 0, 0, 0, 0, 1],
    }
    vectors = dict(singles)
    vectors.update(pooled_windows(en, ru, singles, 7))

    def run(band_width, window_embed):
        model = StubModel(dict(vectors))
        result = BilingualAligner(
            model=model,
            max_window=3,
            similarity_threshold=0.99,
            skip_penalty=-0.1,
            algorithm="dp",
            band_width=band_width,
            window_embed=window_embed,
        ).align_lists(en, ru)
        return result, model

    models = []
    for window_embed, tight_total, wide_total in (
        ("aggregate", 7, 7),
        ("joined", 11, 16),
    ):
        tight_result, tight_model = run(1, window_embed)
        wide_result, wide_model = run(100, window_embed)
        # Keep every model alive: the embedding cache is keyed by id(model), so
        # a freed model's recycled id would leak its cached vectors into the
        # next run and break the encode counts.
        models.extend([tight_model, wide_model])

        assert tight_result["matches"] == [] and wide_result["matches"] == []
        assert tight_result["unmatched_en"] == [0, 1, 2, 3, 4, 5]
        assert tight_result["unmatched_ru"] == [0]

        assert tight_model.encode_total == tight_total, (
            f"{window_embed} tight band should embed {tight_total} texts, "
            f"encoded {tight_model.encode_total}"
        )
        assert wide_model.encode_total == wide_total, (
            f"{window_embed} huge band should embed {wide_total} texts, "
            f"encoded {wide_model.encode_total}"
        )
        if window_embed == "joined":
            assert wide_model.encode_total - tight_model.encode_total == 5

    return "OK: aggregate embeds only the 7 singles (band-independent); joined keeps banding (11 vs 16)"


def aggregate_ranks_the_correct_fusion_window_highest():
    reset_cache()
    # A 1:2 fusion: "Theta" translates the pooled pair only (both 1:1s score
    # 0.5, below threshold; the pooled 1:2 clears 0.55 at ~0.707). `aggregate`
    # combines the per-sentence vectors into the window vector, so the window
    # ladder must rank the correct multi-sentence window highest and commit
    # the 1:2 — not a sub-threshold 1:1.
    model = StubModel({
        N("Theta"): [0.5, 0.5, 0.70710678],
        N("Eta1"): [1, 0, 0],
        N("Eta2"): [0, 1, 0],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        max_total_span=3,
        window_embed="aggregate",
    )

    result = aligner.align_lists(["Theta"], ["Eta1", "Eta2"])

    assert_same_matches(result["matches"], [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 2, "score": 0.70710678},
    ]), f"aggregate should rank the fused 1:2 highest, got {result['matches']}"
    assert result["unmatched_en"] == [], result["unmatched_en"]
    assert result["unmatched_ru"] == [], result["unmatched_ru"]

    return "OK: aggregate ranks the correct multi-sentence window highest (1:2 fusion)"


def aggregate_weights_follow_sentence_lengths():
    reset_cache()
    # Length-weighting contract of `_aggregate_window`: weights are the window
    # sentences' character counts, so a long sentence dominates the average.
    # [1,0,0] (1 char) + [0,1,0] (4 chars) -> weights [1,4]: the weighted mean
    # [0.2,0.8,0] normalizes to ~[0.2425,0.9701,0].
    aligner = BilingualAligner(model=StubModel({}), window_embed="aggregate")
    vecs = [torch.tensor([1.0, 0.0, 0.0]), torch.tensor([0.0, 1.0, 0.0])]
    v = aligner._aggregate_window(vecs, 0, 2, [1, 4])
    expected = np.array([0.2, 0.8, 0.0])
    expected = expected / np.linalg.norm(expected)
    assert torch.allclose(v, torch.tensor(expected, dtype=torch.float32), atol=1e-6), v
    assert abs(float(v.norm()) - 1.0) < 1e-6, "aggregated window must stay L2-normalized"

    # End-to-end: a long-sentence window scores near the long sentence's vector
    # (cos ~0.935, well above the 1:1 of the short sentence at ~0.30 and above
    # what a uniform mean would give ~0.85). No pre-commit anchors here: the
    # 1:1s sit below high_confidence/anchor_threshold, so the ladder alone
    # decides — and it picks the 2:1 window dominated by the long sentence.
    model = StubModel({
        N("A"): [1, 0, 0],
        N("LongLong"): [0, 1, 0],
        N("TransBoth"): [0.3, 0.9, 0.3],
    })
    result = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        max_total_span=3,
        high_confidence=0.99,
        anchor_threshold=0.95,
        window_embed="aggregate",
    ).align_lists(["A", "LongLong"], ["TransBoth"])

    assert len(result["matches"]) == 1, result["matches"]
    match = result["matches"][0]
    assert (match["en_start"], match["en_end"]) == (0, 2), match
    assert (match["ru_start"], match["ru_end"]) == (0, 1), match
    assert abs(match["score"] - 0.934902) < 1e-4, f"score {match['score']}"
    assert match["score"] > 0.9, "long sentence should dominate the pooled average"

    return "OK: aggregate weights follow sentence lengths (a long sentence dominates the average)"


def aggregate_caches_per_sentence_vectors():
    reset_cache()
    # In `aggregate` mode only the single sentences are ever embedded; every
    # multi-sentence window is combined from the cached per-sentence vectors,
    # so the prepass and the window scoring share one set of embeddings. The
    # counting stub proves each unique single reaches the model exactly once —
    # the 1:2 window the ladder picks adds zero encodes.
    model = StubModel({
        N("Alph"): [1, 0, 0],
        N("Beta"): [0, 1, 0],
        N("R_AB"): [0.5, 0.5, 0.70710678],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        max_total_span=3,
        window_embed="aggregate",
    )

    result = aligner.align_lists(["Alph", "Beta"], ["R_AB"])

    assert_same_matches(result["matches"], [
        {"en_start": 0, "en_end": 2, "ru_start": 0, "ru_end": 1, "score": 0.70710678},
    ]), f"expected the pooled 2:1, got {result['matches']}"
    assert result["unmatched_en"] == [], result["unmatched_en"]
    assert result["unmatched_ru"] == [], result["unmatched_ru"]
    # 3 singles in 2 encode calls (en batch + ru single); the 2:1 window was
    # aggregated from cache, not re-encoded.
    assert model.encode_total == 3, f"expected 3 encoded singles, got {model.encode_total}"
    assert model.encode_calls == 2, f"expected 2 encode calls, got {model.encode_calls}"

    return "OK: aggregate embeds each single once; windows aggregate from the cache"


def joined_mode_embeds_joined_window_texts():
    reset_cache()
    # `joined` keeps today's join-then-embed: the pooled window is encoded from
    # its joined text (cache key = joined text), so it is a 4th encoded text
    # rather than a combination of the cached singles.
    model = StubModel({
        N("Alpha"): [1, 0, 0],
        N("Beta"): [0, 1, 0],
        N("Alpha Beta"): [0.70710678, 0.70710678, 0],
        N("R_AB"): [0.70710678, 0.70710678, 0],
    })

    aligner = BilingualAligner(
        model=model,
        max_window=2,
        similarity_threshold=0.55,
        max_total_span=3,
        window_embed="joined",
    )

    result = aligner.align_lists(["Alpha", "Beta"], ["R_AB"])

    assert_same_matches(result["matches"], [
        {"en_start": 0, "en_end": 2, "ru_start": 0, "ru_end": 1, "score": 1.0},
    ]), f"expected the pooled 2:1, got {result['matches']}"
    assert result["unmatched_en"] == [], result["unmatched_en"]
    assert result["unmatched_ru"] == [], result["unmatched_ru"]
    # 3 singles + the joined 2:1 window text.
    assert model.encode_total == 4, f"expected 4 encoded texts, got {model.encode_total}"

    return "OK: joined mode embeds the joined window text (4 texts, no single sharing)"


def pins_are_emitted_verbatim_with_score_one():
    reset_cache()
    # "Two" <-> "Two'" is pinned (its 1:1 cell also clears high_confidence, so
    # without the pin the prepass would lock it as an anchor). Both algorithms
    # must emit the pin verbatim with score 1.0 and keep the pools before/after
    # exactly as unpinned: One <-> A (0.8) then Three <-> C (0.85), in document
    # order.
    en = ["One", "Two", "Three"]
    ru = ["A", "Two'", "C"]

    vectors = {
        N("One"): [1, 0, 0, 0, 0],
        N("A"): [0.8, 0.6, 0, 0, 0],
        N("Two"): [0, 0, 1, 0, 0],
        N("Two'"): [0, 0, 1, 0, 0],
        N("Three"): [0, 0, 0, 0.85, 0.5268],
        N("C"): [0, 0, 0, 1, 0],
        N("One Two"): [0.5, 0, 0.5, 0, 0],
        N("Two Three"): [0, 0, 0.5, 0.425, 0.2634],
        N("A Two'"): [0.4, 0.3, 0.5, 0, 0],
        N("Two' C"): [0, 0, 0.5, 0.5, 0],
    }
    pin = {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 2}

    expected = [
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 0.8},
        {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 2, "score": 1.0},
        {"en_start": 2, "en_end": 3, "ru_start": 2, "ru_end": 3, "score": 0.85},
    ]

    for algorithm in ("greedy", "dp"):
        result = BilingualAligner(
            model=StubModel(dict(vectors)),
            max_window=2,
            similarity_threshold=0.55,
            algorithm=algorithm,
        ).align_lists(en, ru, landmarks=[pin])

        assert_same_matches(result["matches"], expected), (
            f"{algorithm}: {result['matches']}"
        )
        assert result["unmatched_en"] == [], result["unmatched_en"]
        assert result["unmatched_ru"] == [], result["unmatched_ru"]

        pin_matches = [
            m
            for m in result["matches"]
            if (m["en_start"], m["en_end"], m["ru_start"], m["ru_end"]) == (1, 2, 1, 2)
        ]
        assert len(pin_matches) == 1, f"{algorithm}: {pin_matches}"
        assert pin_matches[0]["score"] == 1.0, f"{algorithm}: {pin_matches[0]}"

    return "OK: pinned pair emitted verbatim with score 1.0 (greedy and dp)"


def no_machine_match_overlaps_a_pin():
    reset_cache()
    # "Two" strongly matches both P1 and P2 (0.95 each, >= high_confidence) —
    # without the pin the prepass would lock the cell (1, 1) and the machine
    # would match inside the pin's rectangle. With the 1:2 pin en[1:2] x
    # ru[1:3], machine output is confined to the pools before/after it and
    # never touches the pin; the prepass skips every cell inside the pin.
    en = ["One", "Two", "Three"]
    ru = ["A", "P1", "P2", "C"]
    vectors = {
        N("One"): [1, 0, 0, 0, 0],
        N("A"): [0.8, 0.6, 0, 0, 0],
        N("Two"): [0, 0, 1, 0, 0],
        N("P1"): [0, 0, 0.95, 0.3118, 0],
        N("P2"): [0, 0, 0.95, 0, 0.3118],
        N("Three"): [0, 0, 0, 0.85, 0.5268],
        N("C"): [0, 0, 0, 1, 0],
    }
    pin = {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 3}

    for algorithm in ("greedy", "dp"):
        aligner = BilingualAligner(
            model=StubModel(dict(vectors)),
            max_window=2,
            similarity_threshold=0.55,
            algorithm=algorithm,
        )
        norm_en = _normalize_sentences(en)
        norm_ru = _normalize_sentences(ru)
        _, en_embs = aligner._generate_sentence_embeddings(norm_en)
        _, ru_embs = aligner._generate_sentence_embeddings(norm_ru)
        sim = util.cos_sim(en_embs, ru_embs).cpu().numpy()

        def _in_pin(i, j):
            return pin["en_start"] <= i < pin["en_end"] and pin["ru_start"] <= j < pin["ru_end"]

        without = aligner._prepass_anchors(sim, len(en), len(ru))
        assert any(
            _in_pin(i, j) for i, j in without
        ), f"{algorithm}: prepass should anchor inside the pin rectangle: {without}"
        with_pin = aligner._prepass_anchors(sim, len(en), len(ru), [pin])
        assert all(
            not _in_pin(i, j) for i, j in with_pin
        ), f"{algorithm}: prepass anchors inside a pin: {with_pin}"

        result = aligner.align_lists(en, ru, landmarks=[pin])
        assert_same_matches(result["matches"], [
            {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1, "score": 0.8},
            {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 3, "score": 1.0},
            {"en_start": 2, "en_end": 3, "ru_start": 3, "ru_end": 4, "score": 0.85},
        ]), f"{algorithm}: {result['matches']}"
        assert result["unmatched_en"] == [], result["unmatched_en"]
        assert result["unmatched_ru"] == [], result["unmatched_ru"]

        for m in result["matches"]:
            overlap = (
                m["en_start"] < pin["en_end"]
                and pin["en_start"] < m["en_end"]
                and m["ru_start"] < pin["ru_end"]
                and pin["ru_start"] < m["ru_end"]
            )
            is_pin = (m["en_start"], m["en_end"], m["ru_start"], m["ru_end"]) == (1, 2, 1, 3)
            assert overlap == is_pin, f"{algorithm}: {m} overlaps the pin"

    return "OK: no machine match overlaps a pin; prepass skips pinned cells"


def pinned_indices_are_excluded_from_unmatched():
    reset_cache()
    # "One" has no counterpart (cos 0.3, below threshold) so it lands in
    # unmatched; "Two" <-> "Two'" is pinned. The pinned indices (1, 1) must be
    # excluded from both unmatched lists.
    def make_model():
        return StubModel({
            N("One"): [1, 0, 0],
            N("A"): [0.3, 0.95, 0],
            N("Two"): [0, 1, 0],
            N("Two'"): [0, 1, 0],
        })

    pin = {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 2}

    for algorithm in ("greedy", "dp"):
        result = BilingualAligner(
            model=make_model(),
            max_window=2,
            similarity_threshold=0.55,
            algorithm=algorithm,
        ).align_lists(["One", "Two"], ["A", "Two'"], landmarks=[pin])

        assert_same_matches(result["matches"], [
            {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 2, "score": 1.0},
        ]), f"{algorithm}: {result['matches']}"
        assert result["unmatched_en"] == [0], f"{algorithm}: {result['unmatched_en']}"
        assert result["unmatched_ru"] == [0], f"{algorithm}: {result['unmatched_ru']}"

    return "OK: pinned indices excluded from unmatched_en / unmatched_ru"


def invalid_pins_raise_value_error():
    reset_cache()
    # The API layer translates the aligner's ValueError into a 422; here we
    # assert the ValueError itself for every invalid pin shape.
    en = ["One", "Two"]
    ru = ["A", "Two'"]
    aligner = BilingualAligner(
        model=StubModel({
            N("One"): [1, 0],
            N("Two"): [0, 1],
            N("A"): [1, 0],
            N("Two'"): [0, 1],
        }),
        max_window=2,
        similarity_threshold=0.55,
    )

    def expect_value_error(pins):
        try:
            aligner.align_lists(en, ru, landmarks=pins)
        except ValueError:
            return
        raise AssertionError(f"expected ValueError for pins {pins}")

    # Out of range on either axis.
    expect_value_error([{"en_start": 0, "en_end": 3, "ru_start": 0, "ru_end": 1}])
    expect_value_error([{"en_start": -1, "en_end": 1, "ru_start": 0, "ru_end": 1}])
    expect_value_error([{"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 5}])
    # Zero-length spans on either axis.
    expect_value_error([{"en_start": 1, "en_end": 1, "ru_start": 0, "ru_end": 1}])
    expect_value_error([{"en_start": 0, "en_end": 1, "ru_start": 1, "ru_end": 1}])
    # Crossing pins: the later EN start has a smaller RU start.
    expect_value_error([
        {"en_start": 0, "en_end": 1, "ru_start": 1, "ru_end": 2},
        {"en_start": 1, "en_end": 2, "ru_start": 0, "ru_end": 1},
    ])
    # Overlapping pins (sharing sentences) are rejected too.
    expect_value_error([
        {"en_start": 0, "en_end": 2, "ru_start": 0, "ru_end": 1},
        {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 2},
    ])

    # Adjacent valid pins are accepted.
    result = aligner.align_lists(en, ru, landmarks=[
        {"en_start": 0, "en_end": 1, "ru_start": 0, "ru_end": 1},
        {"en_start": 1, "en_end": 2, "ru_start": 1, "ru_end": 2},
    ])
    assert len(result["matches"]) == 2, result["matches"]
    assert result["unmatched_en"] == [] and result["unmatched_ru"] == []

    return "OK: crossing / out-of-range / zero-length pins rejected; valid pins accepted"


def main() -> int:
    checks = [
        skips_an_unmatchable_sentence_instead_of_force_matching,
        still_force_matches_when_skipping_is_disabled,
        span_cap_rejects_oversized_edges,
        embedding_cache_reuses_vectors_across_calls,
        greedy_matches_dp_on_clean_list_with_far_fewer_encodes,
        greedy_resolves_a_1_to_2_match_via_lazy_window_expansion,
        greedy_skips_an_unmatchable_sentence,
        greedy_anchors_then_resolves_a_gap_after_a_locked_pair,
        greedy_merges_an_orphan_into_a_beating_pooled_window,
        greedy_ladder_widens_past_primary_for_a_4_window_match,
        normalized_windows_resolve_the_whistler_pair,
        normalize_sentences_strips_punctuation_case_and_collapses_whitespace,
        align_lists_raw_and_pre_normalized_input_are_identical,
        prepass_anchors_lock_high_confidence_pairs_for_greedy_and_dp,
        prepass_anchors_split_pools_so_no_match_crosses_in_document_order,
        high_confidence_knob_controls_the_number_of_prepass_anchors,
        band_rejects_an_out_of_band_pair,
        band_allows_an_in_band_pair,
        band_recovery_across_a_divergent_region,
        band_width_knob_controls_match_density,
        banding_reduces_joined_window_embeddings_but_aggregate_only_embeds_singles,
        aggregate_ranks_the_correct_fusion_window_highest,
        aggregate_weights_follow_sentence_lengths,
        aggregate_caches_per_sentence_vectors,
        joined_mode_embeds_joined_window_texts,
        pins_are_emitted_verbatim_with_score_one,
        no_machine_match_overlaps_a_pin,
        pinned_indices_are_excluded_from_unmatched,
        invalid_pins_raise_value_error,
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
