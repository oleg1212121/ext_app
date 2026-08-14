import logging
from collections import OrderedDict
from pathlib import Path

import numpy as np
import torch
from sentence_transformers import SentenceTransformer, util

from ai import config

logger = logging.getLogger(__name__)


def _normalize_text(text: str) -> str:
    """Normalize a sentence's text before embedding.

    casefold + keep only alphanumerics and whitespace (unicode-aware) +
    collapse whitespace. LaBSE/MiniLM score raw punctuation/case-heavy pairs
    far below their normalized form (The whistler vs «СВИСТУН»: ~0.32 raw,
    ~0.685 normalized), so embedding the normalized text fixes those false
    negatives without touching the stored sentences — the API/PHP side keeps
    and persists the raw text.
    """
    out = []
    prev_space = False
    for ch in text.casefold():
        if ch.isalnum():
            out.append(ch)
            prev_space = False
        elif ch.isspace():
            if not prev_space:
                out.append(" ")
            prev_space = True
    return "".join(out).strip()


def _normalize_sentences(sentences: list[str]) -> list[str]:
    """Normalize every sentence once, at alignment entry.

    Returns a same-length list of normalized forms (casefold + alnum/space
    only + collapsed whitespace). Index positions are preserved, so a
    normalized list addresses exactly the same sentence space as the raw one —
    every downstream index (matches, unmatched, chunk offsets) applies to both
    identically. Normalization is idempotent, so pre-normalizing a caller's
    input up front changes nothing.
    """
    return [_normalize_text(s) for s in sentences]


def _window_text(sentences: list[str], start: int, step: int) -> str:
    """Joined text of the sentence window [start:start+step].

    Callers pass the pre-normalized sentence list (see `_align_pair`), so
    joining the pre-normalized sentences *is* the normalized window text.
    Every embedded window — and therefore every embedding-cache key — uses
    this, so DP and greedy embed and reuse identical normalized texts.
    """
    return " ".join(sentences[start : start + step])


def _validate_pins(pins: list[dict], n: int, m: int) -> None:
    """Reject landmark pins that cannot be honored as hard commits.

    Each pin is `{en_start, en_end, ru_start, ru_end}` — indices into the
    submitted sentence lists. Raises ValueError when a pin is zero-length,
    out of the lists' bounds, or crosses/overlaps another pin (pins must be
    pairwise disjoint in both axes, so the sub-pool boundary union stays
    sorted and non-crossing). The API layer translates the ValueError into a
    422.
    """
    if not pins:
        return
    for pin in pins:
        en_start, en_end = pin["en_start"], pin["en_end"]
        ru_start, ru_end = pin["ru_start"], pin["ru_end"]
        if en_end <= en_start or ru_end <= ru_start:
            raise ValueError(f"landmark pin span must be non-empty: {pin}")
        if en_start < 0 or ru_start < 0 or en_end > n or ru_end > m:
            raise ValueError(
                f"landmark pin out of range for {n} EN x {m} RU sentences: {pin}"
            )
    ordered = sorted(pins, key=lambda p: (p["en_start"], p["ru_start"]))
    for prev, curr in zip(ordered, ordered[1:]):
        if curr["en_start"] < prev["en_end"] or curr["ru_start"] < prev["ru_end"]:
            raise ValueError(f"landmark pins cross or overlap: {prev} vs {curr}")


def _cell_in_pin(cell: tuple[int, int], pins: list[dict]) -> bool:
    """True when the 1:1 cell (i, j) sits inside any pin's rectangle
    `range(en_start, en_end) x range(ru_start, ru_end)`."""
    i, j = cell
    for pin in pins:
        if pin["en_start"] <= i < pin["en_end"] and pin["ru_start"] <= j < pin["ru_end"]:
            return True
    return False


class EmbeddingCache:
    """Process-level LRU cache of window embeddings.

    The dominant per-chunk cost is embedding encode, and the same texts recur
    heavily: across chunk seams (~2 sentences re-aligned per boundary) and
    across entities that share source material. Keys are (model id, normalized
    text): in `aggregate` mode the per-sentence vectors (key = normalized
    single text) are cached and every multi-sentence window is combined from
    them; in `joined` mode the joined window texts are cached. Either way each
    unique text is embedded once per process lifetime instead of once per
    chunk.
    """

    def __init__(self, max_entries: int = 10_000):
        self._entries: "OrderedDict[tuple[int, str], torch.Tensor]" = OrderedDict()
        self._max_entries = max_entries

    def get(self, key: tuple[int, str]) -> torch.Tensor | None:
        if key in self._entries:
            self._entries.move_to_end(key)
            return self._entries[key]
        return None

    def put(self, key: tuple[int, str], value: torch.Tensor) -> None:
        self._entries[key] = value
        self._entries.move_to_end(key)
        if len(self._entries) > self._max_entries:
            self._entries.popitem(last=False)


_EMBEDDING_CACHE = EmbeddingCache()


class BilingualAligner:
    def __init__(
        self,
        model=None,
        chunk_size=100,
        max_window=3,
        similarity_threshold=0.4,
        max_total_span=None,
        skip_penalty=None,
        algorithm=None,
        anchor_threshold=None,
        primary_window=None,
        merge_margin=None,
        high_confidence=None,
        band_width=None,
        window_embed=None,
    ):
        # model accepts a ready SentenceTransformer (to share one loaded model
        # between classes), a model path, or None to load the default local model.
        if model is None:
            model = Path(__file__).parent.parent / "bge_m3_local"
        if isinstance(model, (str, Path)):
            self.model = SentenceTransformer(str(model))
        else:
            # Ready model object (real SentenceTransformer or a duck-typed stand-in).
            self.model = model
        self.chunk_size = chunk_size
        self.max_window = max_window
        self.similarity_threshold = similarity_threshold
        # "greedy" (anchor-first, embeds each sentence once + rare expansions) or
        # "dp" (full window DP, embeds (n + m) * max_window windows).
        self.algorithm = (algorithm or config.align_algorithm()).strip().lower()
        if self.algorithm not in ("greedy", "dp"):
            self.algorithm = "greedy"
        # Confident 1:1 bar used by the greedy mode to lock anchors inside each
        # sub-pool (the prepass uses high_confidence instead).
        self.anchor_threshold = float(
            anchor_threshold if anchor_threshold is not None else config.align_anchor_threshold()
        )
        # Greedy gap search ladder: steps 1..primary are compared as one set
        # first (a 1:1 no longer auto-commits on threshold — it must beat the
        # other window combos); if nothing clears the bar the search widens one
        # step per side up to max_window. Never exceeds the growth ceiling.
        self.primary_window = max(
            1,
            min(
                primary_window if primary_window is not None else config.align_primary_window(),
                self.max_window,
            ),
        )
        # Greedy orphan-merge post-pass: a match followed by orphans on exactly
        # one side is extended over that orphan run when the pooled window beats
        # the match's score by at least this margin.
        self.merge_margin = float(
            merge_margin if merge_margin is not None else config.align_merge_margin()
        )
        # Match edges consuming en_step + ru_step sentences above this cap are
        # rejected: 1:5 / 5:1 spans are almost never genuine translations, and
        # capping the total span also shrinks the DP's edge set.
        self.max_total_span = max(
            max_total_span if max_total_span is not None else config.align_max_total_span(),
            2,
        )
        # Per-sentence cost of consuming a sentence without a match. The DP
        # prefers this over a below-threshold force-match (penalty -2.0) so
        # sentences with no counterpart land in the unmatched pool instead of
        # producing the <0.6 meaning-match garbage.
        self.skip_penalty = min(
            skip_penalty if skip_penalty is not None else config.align_skip_penalty(),
            0.0,
        )
        # Plan 02 knob, used by plan 03: 1:1 prepass anchor bar — mutually-best
        # cells at/above this cosine are locked as committed matches that split
        # the chunk into sub-pools aligned in isolation.
        self.high_confidence = float(
            high_confidence if high_confidence is not None else config.align_high_confidence()
        )
        # Plan 02 knobs: resolved here, stored, but unused until plans 04-06.
        # Diagonal band half-width (plan 04) around the expected length-ratio
        # diagonal; None -> derived per chunk as max(2, max_window).
        self.band_width = band_width if band_width is not None else config.align_band_width()
        # Window embedding mode (plan 05): "aggregate" (per-sentence vectors,
        # length-weighted + L2-normalized) or "joined" (join-then-embed).
        self.window_embed = (window_embed or config.align_window_embed()).strip().lower()
        if self.window_embed not in ("aggregate", "joined"):
            self.window_embed = "aggregate"

    def _align_pair(
        self,
        en_raw: list[str],
        ru_raw: list[str],
        landmarks: list[dict] | None = None,
    ) -> dict:
        """Normalize once, validate + apply landmark pins, run the
        high-confidence prepass, then align each sub-pool with the chosen
        algorithm.

        The single normalization point for alignment: en_raw/ru_raw are
        normalized exactly once here, and every internal stage — windows, the
        anchor matrix, gap ladder, orphan-merge, embedding-cache keys — operates
        on those identical normalized forms. Landmark pins (plan 06) are
        validated against the list lengths, the chunk's single-sentence
        similarity matrix is computed once, high-confidence (>= high_confidence)
        mutually-best 1:1 cells are locked as prepass anchors (skipping cells
        inside any pin), and both algorithms align each resulting sub-pool in
        isolation. Pins and prepass anchors form the sub-pool boundaries
        (see `_align_with_anchors`), so machine output never crosses or
        overlaps a pin. Returns the full `{matches, unmatched_en, unmatched_ru}`
        dict; match entries carry the internal diagnostic fields
        (en_text/ru_text/en_step/ru_step) alongside the public spans.
        """
        en_norm = _normalize_sentences(en_raw)
        ru_norm = _normalize_sentences(ru_raw)

        n = len(en_norm)
        m = len(ru_norm)

        pins = landmarks or []
        _validate_pins(pins, n, m)

        if n == 0 or m == 0:
            matches = []
        elif self.algorithm == "dp":
            # Only the chunk's single-sentence vectors feed the prepass matrix;
            # each sub-pool's DP embeds its own in-band windows (plan 04), so
            # the full chunk's multi-sentence windows are never embedded
            # up-front (the old (n + m) * max_window precompute is gone).
            _, en_embs = self._generate_sentence_embeddings(en_norm)
            _, ru_embs = self._generate_sentence_embeddings(ru_norm)
            singles_sim = util.cos_sim(en_embs, ru_embs).cpu().numpy()
            anchors = self._prepass_anchors(singles_sim, n, m, pins)
            matches = self._align_with_anchors(en_norm, ru_norm, singles_sim, anchors, pins)
        else:
            # Greedy embeds the chunk's singles once for the prepass; the pool
            # gap alignment reuses the cached vectors.
            _, en_embs = self._generate_sentence_embeddings(en_norm)
            _, ru_embs = self._generate_sentence_embeddings(ru_norm)
            sim = util.cos_sim(en_embs, ru_embs).cpu().numpy()
            anchors = self._prepass_anchors(sim, n, m, pins)
            matches = self._align_with_anchors(en_norm, ru_norm, sim, anchors, pins)

        matched_en: set[int] = set()
        matched_ru: set[int] = set()
        for match in matches:
            matched_en.update(range(match["en_start"], match["en_end"]))
            matched_ru.update(range(match["ru_start"], match["ru_end"]))

        return {
            "matches": matches,
            "unmatched_en": [i for i in range(len(en_raw)) if i not in matched_en],
            "unmatched_ru": [i for i in range(len(ru_raw)) if i not in matched_ru],
        }

    def align_lists(
        self,
        en_sentences: list[str],
        ru_sentences: list[str],
        landmarks: list[dict] | None = None,
    ) -> dict:
        """Align two in-memory sentence lists and return structured matches.

        Returns matches as index spans into the submitted lists plus the
        indices not covered by any match. Indices not covered are exactly the
        sentences the chosen algorithm skipped (no counterpart on the other
        side) — with the skip branch the aligner no longer force-aligns every
        sentence, so a sentence with no genuine translation lands here instead
        of in a low-similarity meaning match.

        `landmarks` is a list of hard pins — human-made committed matches
        `{en_start, en_end, ru_start, ru_end}` (indices into the submitted
        lists). Each is emitted verbatim with score 1.0 and splits the chunk
        into sub-pools that the machine never crosses or overlaps. Invalid
        pins (zero-length, out of range, crossing/overlapping) raise
        ValueError.
        """
        result = self._align_pair(en_sentences, ru_sentences, landmarks)
        return {
            "matches": [
                {
                    "en_start": m["en_start"],
                    "en_end": m["en_end"],
                    "ru_start": m["ru_start"],
                    "ru_end": m["ru_end"],
                    "score": m["score"],
                }
                for m in result["matches"]
            ],
            "unmatched_en": result["unmatched_en"],
            "unmatched_ru": result["unmatched_ru"],
        }

    def process(self, en_path, ru_path, output_path):
        en_all = self._read_sentences(en_path)
        ru_all = self._read_sentences(ru_path)

        logger.info("EN sentences: %d", len(en_all))
        logger.info("RU sentences: %d", len(ru_all))

        en_offset = 0
        ru_offset = 0
        all_matches = []
        chunk_num = 0

        while en_offset < len(en_all) and ru_offset < len(ru_all):
            chunk_num += 1
            en_chunk = en_all[en_offset : en_offset + self.chunk_size]
            ru_chunk = ru_all[ru_offset : ru_offset + self.chunk_size]

            logger.info(
                "Chunk %d: EN[%d:%d] RU[%d:%d]",
                chunk_num,
                en_offset,
                en_offset + len(en_chunk),
                ru_offset,
                ru_offset + len(ru_chunk),
            )

            matches = self._align_pair(en_chunk, ru_chunk)["matches"]

            if not matches:
                logger.warning("No alignment produced in chunk, breaking to avoid infinite loop")
                break

            is_last_chunk = (
                en_offset + len(en_chunk) >= len(en_all)
                and ru_offset + len(ru_chunk) >= len(ru_all)
            )
            committed = matches if is_last_chunk else self._trim_to_last_anchor(matches)

            if not committed:
                # No confident anchor in this chunk: commit everything anyway
                # to guarantee forward progress.
                committed = matches

            last = committed[-1]
            new_en_offset = en_offset + last["en_end"]
            new_ru_offset = ru_offset + last["ru_end"]

            for match in committed:
                match["en_start"] += en_offset
                match["en_end"] += en_offset
                match["ru_start"] += ru_offset
                match["ru_end"] += ru_offset

            all_matches.extend(committed)

            if new_en_offset == en_offset and new_ru_offset == ru_offset:
                logger.warning("No progress in chunk, breaking to avoid infinite loop")
                break

            en_offset = new_en_offset
            ru_offset = new_ru_offset

        unmatched_ru = []
        if ru_offset < len(ru_all):
            unmatched_ru = [(i, ru_all[i]) for i in range(ru_offset, len(ru_all))]

        unmatched_en = []
        if en_offset < len(en_all):
            unmatched_en = [(i, en_all[i]) for i in range(en_offset, len(en_all))]

        self._write_results(all_matches, unmatched_ru, unmatched_en, output_path)

    def _trim_to_last_anchor(self, matches):
        # The DP forces full coverage of both chunks, so matches near the chunk
        # boundary may be force-aligned even though their true counterpart lives
        # in the next chunk. Commit only up to and including the last confident
        # (high-score) anchor; the uncertain tail is re-aligned with fresh
        # context in the next iteration.
        for idx in range(len(matches) - 1, -1, -1):
            if matches[idx]["score"] >= self.similarity_threshold:
                return matches[: idx + 1]
        return []

    def _read_sentences(self, filepath):
        sentences = []
        with open(filepath, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if line:
                    sentences.append(line)
        return sentences

    def _sentence_vectors(self, sentences):
        """Per-sentence vectors, each single embedded once through the shared cache.

        The cache key is (model id, normalized single text) — the same key the
        prepass's step-1 windows use — so in `aggregate` mode the window
        scoring reuses exactly the prepass's single-sentence embeddings
        instead of re-encoding joined window texts. Cache misses are encoded
        in one batch; every later call is pure cache lookup.
        """
        model_id = id(self.model)
        vecs: list[torch.Tensor] = [None] * len(sentences)
        missing = []

        for pos, sentence in enumerate(sentences):
            key = (model_id, _normalize_text(sentence))
            cached = _EMBEDDING_CACHE.get(key)
            if cached is not None:
                vecs[pos] = cached
            else:
                missing.append(pos)

        if missing:
            batch = self.model.encode(
                [_normalize_text(sentences[pos]) for pos in missing],
                batch_size=64,
                show_progress_bar=False,
                convert_to_tensor=True,
            )
            for k, pos in enumerate(missing):
                key = (model_id, _normalize_text(sentences[pos]))
                vector = batch[k]
                _EMBEDDING_CACHE.put(key, vector)
                vecs[pos] = vector

        return vecs

    def _aggregate_window(self, vecs, start, step, lens):
        """Length-weighted, L2-normalized combination of a window's sentence vectors.

        Weights are the window's sentence character counts
        (`lens[start:start+step]`), normalized to sum to 1 — so a long sentence
        dominates the average. The weighted sum is re-normalized to unit length
        so its cosines stay comparable to the prepass singles matrix. A step-1
        window reduces to its (already normalized) single vector.
        """
        weights = lens[start : start + step]
        total = sum(weights)
        if total <= 0:
            weights = [1.0] * step
            total = float(step)
        v = sum((w / total) * vecs[start + k] for k, w in enumerate(weights))
        norm = v.norm()
        if norm > 0:
            v = v / norm
        return v

    def _window_vector(self, sentences, start, step):
        """Vector of the sentence window [start:start+step], per `window_embed`.

        `aggregate` (default): length-weighted mean of the window's per-sentence
        vectors — every single is embedded once through the shared cache, so the
        prepass singles and the window scoring share embeddings and no joined
        window text is ever embedded. `joined`: the existing join-then-embed of
        the normalized window text (cached by joined text, current behavior).
        """
        if self.window_embed == "joined":
            text = _window_text(sentences, start, step)
            key = (id(self.model), text)
            cached = _EMBEDDING_CACHE.get(key)
            if cached is not None:
                return cached
            batch = self.model.encode(
                [text],
                batch_size=64,
                show_progress_bar=False,
                convert_to_tensor=True,
            )
            vector = batch[0]
            _EMBEDDING_CACHE.put(key, vector)
            return vector

        vecs = self._sentence_vectors(sentences)
        return self._aggregate_window(vecs, start, step, [len(s) for s in sentences])

    def _generate_window_embeddings(self, sentences, starts=None):
        """Embed the windows of `sentences` whose start position is in `starts`.

        With `starts` unset, every start position is embedded (the pre-banding
        behavior). The per-pool DP passes the in-band start positions (plan 04),
        so windows whose start cell sits outside the diagonal band are never
        embedded at all — the plan's perf win. Every window's vector is computed
        by `_window_vector`, so cache hits are reused exactly as before.
        """
        if starts is None:
            starts = range(len(sentences))

        windows = [
            (start, step)
            for start in starts
            for step in range(1, self.max_window + 1)
            if start + step <= len(sentences)
        ]
        return self._embed_windows(sentences, windows)

    def _embed_windows(self, sentences, windows):
        """Lazily embed only the requested (start, step) windows.

        Unlike _generate_window_embeddings (which embeds every window of a
        list up-front for the DP), this encodes just the windows needed by the
        greedy mode at a single cursor position. Each window goes through
        `_window_vector`, so the shared cache is the single source of reused
        embeddings: in `aggregate` mode the per-sentence vectors are cached and
        only the singles ever reach the model; in `joined` mode the joined
        window texts are cached. Cache misses hit the model, everything else is
        pure reuse.
        """
        if not windows:
            return {}, torch.empty((0, 0))

        index = {key: pos for pos, key in enumerate(windows)}
        embs = [self._window_vector(sentences, start, step) for start, step in windows]
        return index, torch.stack(embs)

    def _generate_sentence_embeddings(self, sentences):
        """Embed each single sentence once (the greedy mode's only bulk cost).

        In `aggregate` mode these cached per-sentence vectors are also the
        building blocks of every multi-sentence window, so the prepass singles
        and the window scoring share one set of embeddings.
        """
        return self._embed_windows(sentences, [(i, 1) for i in range(len(sentences))])

    def _find_anchors(self, sim, n, m, threshold, k=None, band=None):
        """Non-crossing, mutually-best 1:1 cells above `threshold`.

        A cell is an anchor when it clears the bar, sits inside the diagonal
        band (when `k`/`band` are given), and is the best cell in its
        max_window-wide row and column slice. Anchors are kept in scan order
        with strict monotonicity, so the greedy cursor between them never needs
        to backtrack across a locked pair. The greedy mode calls it with
        anchor_threshold and the per-pool band inside each sub-pool; the
        prepass calls it with high_confidence and no band, so prepass anchors
        can exist anywhere.
        """
        anchors = []
        last_i = -1
        last_j = -1

        for i in range(n):
            for j in range(m):
                if i <= last_i or j <= last_j:
                    continue

                if k is not None and not self._band_allowed(i, j, k, band):
                    continue

                if float(sim[i, j]) < threshold:
                    continue

                row_slice = sim[i, j : min(j + self.max_window, m)]
                col_slice = sim[i : min(i + self.max_window, n), j]

                if len(row_slice) == 0 or len(col_slice) == 0:
                    continue

                if float(row_slice.max()) > float(sim[i, j]) + 1e-6:
                    continue
                if float(col_slice.max()) > float(sim[i, j]) + 1e-6:
                    continue

                anchors.append((i, j))
                last_i = i
                last_j = j

        return anchors

    def _prepass_anchors(self, sim, n, m, pins=None):
        """High-confidence 1:1 prepass anchors (plan 03).

        Mutually-best cells at or above self.high_confidence lock committed
        matches and split the chunk into sub-pools aligned in isolation (see
        _align_with_anchors). Unbanded by design: prepass anchors can exist
        anywhere on the full singles matrix, only per-pool match edges are
        restricted to the diagonal band. Cells inside any landmark pin
        (plan 06) are skipped — a pin owns its rectangle, so the prepass never
        proposes an anchor inside it.
        """
        anchors = self._find_anchors(sim, n, m, self.high_confidence)
        if not pins:
            return anchors
        return [a for a in anchors if not _cell_in_pin(a, pins)]

    def _resolve_band(self):
        """Diagonal band half-width around the expected length-ratio diagonal.

        `band_width` is a live knob; when unset the band derives per sub-pool
        as max(2, max_window). A wider band admits more match edges, a narrower
        one confines matches to the diagonal.
        """
        return self.band_width if self.band_width is not None else max(2, self.max_window)

    @staticmethod
    def _band_allowed(i, j, k, band):
        """True when cell (i, j) sits inside the diagonal band around the
        expected length-ratio line i = j * k (k = len(sub_en) / len(sub_ru),
        band = half-width). Match edges are gated by their start cell.
        """
        return abs(j * k - i) <= band

    def _in_band_starts(self, n, m, k, band):
        """EN and RU window start positions appearing in at least one in-band
        cell of the n x m sub-pool grid.

        Only these starts' windows are embedded by the per-pool DP, so windows
        whose start cell is off the diagonal never reach the model.
        """
        en_starts: set[int] = set()
        ru_starts: set[int] = set()
        for i in range(n):
            for j in range(m):
                if self._band_allowed(i, j, k, band):
                    en_starts.add(i)
                    ru_starts.add(j)
        return en_starts, ru_starts

    def _align_pool(self, en_sentences, ru_sentences, i0, i1, j0, j1):
        """Align the sub-pool EN[i0:i1] x RU[j0:j1] in isolation.

        The chosen algorithm runs on the slice only (the greedy mode's internal
        anchor_threshold logic still applies inside the pool). Returned matches
        use chunk-global indices.
        """
        if i0 >= i1 or j0 >= j1:
            return []

        if self.algorithm == "greedy":
            pool = self._align_chunk_greedy(en_sentences[i0:i1], ru_sentences[j0:j1])
        else:
            pool = self._align_chunk(en_sentences[i0:i1], ru_sentences[j0:j1])

        for match in pool:
            match["en_start"] += i0
            match["en_end"] += i0
            match["ru_start"] += j0
            match["ru_end"] += j0

        return pool

    def _align_with_anchors(self, en_sentences, ru_sentences, sim, anchors, pins=None):
        """Split the chunk at the union of landmark pins and prepass anchors
        and align each sub-pool in isolation, emitting the boundaries as
        committed matches.

        Landmark pins (plan 06) are hard human-made commits: each is emitted
        verbatim with score 1.0, and machine output never consumes sentences
        on both sides of one. The union of pins + prepass anchors is sorted
        and non-crossing by construction: pins delimit the top-level gaps, and
        only the prepass anchors that lie entirely inside one gap split it
        further (an anchor touching or crossing a pin is dropped — its cell
        was already skipped by the prepass). Pools sit strictly between
        boundaries, so machine matches can never overlap a pin. Output is
        concatenated in strict document order: pool matches, then the
        boundary, then the next pool.
        """
        n = len(en_sentences)
        m = len(ru_sentences)
        pins = pins or []

        def _align_box(i0, i1, j0, j1, box_anchors):
            """Align EN[i0:i1] x RU[j0:j1] in isolation, split by the box's
            prepass anchors (chunk-global cells)."""
            matches = []
            cursor_i, cursor_j = i0, j0
            for ai, aj in box_anchors:
                matches.extend(
                    self._align_pool(en_sentences, ru_sentences, cursor_i, ai, cursor_j, aj)
                )
                matches.append(
                    self._match(en_sentences, ru_sentences, ai, aj, 1, 1, float(sim[ai, aj]))
                )
                cursor_i = ai + 1
                cursor_j = aj + 1
            matches.extend(
                self._align_pool(en_sentences, ru_sentences, cursor_i, i1, cursor_j, j1)
            )
            return matches

        matches = []
        i0 = 0
        j0 = 0

        for pin in pins:
            in_gap = [
                (ai, aj)
                for ai, aj in anchors
                if i0 <= ai < pin["en_start"] and j0 <= aj < pin["ru_start"]
            ]
            matches.extend(_align_box(i0, pin["en_start"], j0, pin["ru_start"], in_gap))
            matches.append(
                self._match(
                    en_sentences,
                    ru_sentences,
                    pin["en_start"],
                    pin["ru_start"],
                    pin["en_end"] - pin["en_start"],
                    pin["ru_end"] - pin["ru_start"],
                    1.0,
                )
            )
            i0 = pin["en_end"]
            j0 = pin["ru_end"]

        in_tail = [(ai, aj) for ai, aj in anchors if i0 <= ai < n and j0 <= aj < m]
        matches.extend(_align_box(i0, n, j0, m, in_tail))

        return matches

    def _align_chunk_greedy(self, en_sentences, ru_sentences):
        """Anchor-first greedy alignment.

        Embeds each sentence exactly once per side, locks confident 1:1
        anchors from the resulting n x m matrix, and greedily aligns the gaps
        between anchors — comparing window combos on a ladder (steps 1..primary
        first, then widening one step per side up to max_window) restricted to
        the diagonal band and skipping the side whose best nearby partner is
        weaker when no window clears the bar. Embedding cost is roughly
        (n + m) singles plus a few window expansions per messy cursor instead of
        (n + m) * max_window for the DP. A final orphan-merge post-pass folds
        single-sided orphan runs into the preceding match when the pooled
        window beats it (see _merge_orphans). Called per sub-pool by the
        prepass anchor split; its internal anchor_threshold anchors still apply
        inside the slice, banded like every other per-pool match edge.
        """
        n = len(en_sentences)
        m = len(ru_sentences)

        if n == 0 or m == 0:
            return []

        k = n / m
        band = self._resolve_band()

        _, en_embs = self._generate_sentence_embeddings(en_sentences)
        _, ru_embs = self._generate_sentence_embeddings(ru_sentences)

        sim = util.cos_sim(en_embs, ru_embs).cpu().numpy()

        matches = []
        cursor_i = 0
        cursor_j = 0

        for ai, aj in self._find_anchors(sim, n, m, self.anchor_threshold, k, band):
            matches.extend(
                self._align_gap(
                    en_sentences, ru_sentences, sim, cursor_i, ai, cursor_j, aj, k, band
                )
            )
            matches.append(self._match(en_sentences, ru_sentences, ai, aj, 1, 1, float(sim[ai, aj])))
            cursor_i = ai + 1
            cursor_j = aj + 1

        matches.extend(
            self._align_gap(en_sentences, ru_sentences, sim, cursor_i, n, cursor_j, m, k, band)
        )

        return self._merge_orphans(matches, en_sentences, ru_sentences)

    def _align_gap(self, en_sentences, ru_sentences, sim, i0, i1, j0, j1, k, band):
        """Greedily align the sub-slices EN[i0:i1] x RU[j0:j1] (a gap between
        anchors). Never crosses the gap bounds, so anchor pairs stay intact.
        Window combos and skip decisions are confined to the diagonal band.
        """
        matches = []
        i = i0
        j = j0

        while i < i1 and j < j1:
            best = self._best_window_pair(en_sentences, ru_sentences, i, i1, j, j1, k, band)

            if best is not None:
                en_step, ru_step, score = best
                matches.append(self._match(en_sentences, ru_sentences, i, j, en_step, ru_step, score))
                i += en_step
                j += ru_step
                continue

            if self._should_skip_en(sim, i, i1, j, j1, k, band):
                i += 1
            else:
                j += 1

        return matches

    def _best_window_pair(self, en_sentences, ru_sentences, i, i1, j, j1, k, band):
        """Best multi-sentence window pair starting at the cursor, or None.

        Ladder search confined to the diagonal band: only (en_step, ru_step)
        combos whose window centers sit within `band` of the expected
        length-ratio line — abs((j + ru_step/2) * k - (i + en_step/2)) <= band
        — are candidates, and steps that cannot pair into any in-band combo are
        never embedded. Otherwise unchanged: steps 1..primary are evaluated as
        one set and the highest-scoring combo that clears similarity_threshold
        wins — so a 1:1 is committed only when it genuinely beats the other
        window combos up to primary, not the moment it crosses the bar. If
        nothing in the primary set clears the bar, widen one step per side up
        to max_window. Every candidate must satisfy
        en_step + ru_step <= max_total_span. Windows are embedded lazily
        (cache-missing texts only) and scored in one small batch.
        """

        def _center_in_band(en_step, ru_step):
            return abs((j + ru_step / 2.0) * k - (i + en_step / 2.0)) <= band

        full_en_steps = [s for s in range(1, self.max_window + 1) if i + s <= i1]
        full_ru_steps = [s for s in range(1, self.max_window + 1) if j + s <= j1]

        if not full_en_steps or not full_ru_steps:
            return None

        # Keep only steps that can pair into at least one in-band window combo,
        # so off-diagonal windows are never embedded.
        en_steps = [s for s in full_en_steps if any(_center_in_band(s, r) for r in full_ru_steps)]
        ru_steps = [s for s in full_ru_steps if any(_center_in_band(e, s) for e in full_en_steps)]

        if not en_steps or not ru_steps:
            return None

        _, en_embs = self._embed_windows(en_sentences, [(i, s) for s in en_steps])
        _, ru_embs = self._embed_windows(ru_sentences, [(j, s) for s in ru_steps])

        sim_block = util.cos_sim(en_embs, ru_embs).cpu().numpy()

        def _best(step_limit):
            best = None
            best_score = -float("inf")

            for a, en_step in enumerate(en_steps):
                if en_step > step_limit:
                    continue
                for b, ru_step in enumerate(ru_steps):
                    if ru_step > step_limit:
                        continue
                    if en_step + ru_step > self.max_total_span:
                        continue
                    if not _center_in_band(en_step, ru_step):
                        continue
                    score = float(sim_block[a, b])
                    if score > best_score:
                        best_score = score
                        best = (en_step, ru_step, score)

            if best is None or best_score < self.similarity_threshold:
                return None

            return best

        best = _best(self.primary_window)
        if best is not None:
            return best

        for step_limit in range(self.primary_window + 1, self.max_window + 1):
            best = _best(step_limit)
            if best is not None:
                return best

        return None

    def _should_skip_en(self, sim, i, i1, j, j1, k, band):
        """Decide which side to consume when no window clears the bar.

        Advance the side whose best 1:1 partner within max_window of lookahead
        is weaker — that sentence has no counterpart nearby, so it is the
        unmatched one. The lookahead slices are clamped to the diagonal band
        (out-of-band cells are not plausible partners); when the cursor sits
        outside the band on both axes, skip toward the expected diagonal so the
        walk re-enters the band.
        """
        j_candidates = [
            jj
            for jj in range(j, min(j + self.max_window, j1))
            if self._band_allowed(i, jj, k, band)
        ]
        i_candidates = [
            ii
            for ii in range(i, min(i + self.max_window, i1))
            if self._band_allowed(ii, j, k, band)
        ]

        best_en = float(sim[i, j_candidates].max()) if j_candidates else -float("inf")
        best_ru = float(sim[i_candidates, j].max()) if i_candidates else -float("inf")

        if best_en == -float("inf") and best_ru == -float("inf"):
            return j * k > i

        return best_en < best_ru

    def _merge_orphans(self, matches, en_sentences, ru_sentences):
        """Fold single-sided orphan runs into the preceding match (greedy only).

        The anchor-first pass can lock a 1:1 that is really the head of a
        multi-sentence window: the pooled window scores higher than the anchor
        but is never evaluated because the anchor pre-commits and the cursor
        jumps past it, leaving the tail as orphans (exactly what fusions
        produce — a partial 1:1 anchor + adjacent orphan). For each match whose
        following gap has orphans on exactly one side (the other side already
        consumed by the match and its successors), try extending the match's
        window over the orphan run — every extension length up to the bound
        (max_window and max_total_span) is embedded and scored, and the best
        pooled window is kept only if it beats the match's score by
        merge_margin. Windows embed lazily through the shared cache, so this
        costs at most a few new window texts per fired merge.
        """
        if not matches:
            return matches

        n = len(en_sentences)
        m = len(ru_sentences)
        merged = []

        for idx, match in enumerate(matches):
            next_match = matches[idx + 1] if idx + 1 < len(matches) else None
            gap_en = (next_match["en_start"] if next_match else n) - match["en_end"]
            gap_ru = (next_match["ru_start"] if next_match else m) - match["ru_end"]

            if gap_en > 0 and gap_ru == 0:
                side = "en"
            elif gap_ru > 0 and gap_en == 0:
                side = "ru"
            else:
                merged.append(match)
                continue

            en_step = match["en_end"] - match["en_start"]
            ru_step = match["ru_end"] - match["ru_start"]

            # Extending one side of the window is bounded by the orphan run
            # itself and by the window growth ceilings.
            max_ext = min(
                gap_en if side == "en" else gap_ru,
                self.max_window - en_step,
                self.max_total_span - en_step - ru_step,
            )

            if max_ext <= 0:
                merged.append(match)
                continue

            en_windows, ru_windows = [], []
            if side == "en":
                en_windows = [
                    (match["en_start"], en_step + ext) for ext in range(1, max_ext + 1)
                ]
                ru_windows = [(match["ru_start"], ru_step)]
            else:
                en_windows = [(match["en_start"], en_step)]
                ru_windows = [
                    (match["ru_start"], ru_step + ext) for ext in range(1, max_ext + 1)
                ]

            _, en_embs = self._embed_windows(en_sentences, en_windows)
            _, ru_embs = self._embed_windows(ru_sentences, ru_windows)
            sim_block = util.cos_sim(en_embs, ru_embs).cpu().numpy()

            best_ext, best_score = 0, -float("inf")
            for ext in range(1, max_ext + 1):
                row = ext - 1 if side == "en" else 0
                col = 0 if side == "en" else ext - 1
                score = float(sim_block[row, col])
                if score > best_score:
                    best_ext, best_score = ext, score

            if best_score > match["score"] + self.merge_margin:
                if side == "en":
                    merged.append(
                        self._match(
                            en_sentences,
                            ru_sentences,
                            match["en_start"],
                            match["ru_start"],
                            en_step + best_ext,
                            ru_step,
                            best_score,
                        )
                    )
                else:
                    merged.append(
                        self._match(
                            en_sentences,
                            ru_sentences,
                            match["en_start"],
                            match["ru_start"],
                            en_step,
                            ru_step + best_ext,
                            best_score,
                        )
                    )
            else:
                merged.append(match)

        return merged

    def _match(self, en_sentences, ru_sentences, i, j, en_step, ru_step, score):
        # en_text/ru_text are diagnostic only (align_lists drops them; the PHP
        # side persists the raw stored sentences) — show the normalized form
        # that was actually embedded and scored.
        return {
            "en_start": i,
            "en_end": i + en_step,
            "ru_start": j,
            "ru_end": j + ru_step,
            "en_text": _window_text(en_sentences, i, en_step),
            "ru_text": _window_text(ru_sentences, j, ru_step),
            "score": score,
            "en_step": en_step,
            "ru_step": ru_step,
        }

    def _align_chunk(self, en_sentences, ru_sentences):
        n = len(en_sentences)
        m = len(ru_sentences)

        if n == 0 or m == 0:
            return []

        # Plan 04: only windows whose start position appears in at least one
        # in-band cell are embedded — windows starting off the diagonal never
        # reach the model, and off-band match edges are skipped below.
        k = n / m
        band = self._resolve_band()
        en_starts, ru_starts = self._in_band_starts(n, m, k, band)

        en_index, en_embs = self._generate_window_embeddings(en_sentences, en_starts)
        ru_index, ru_embs = self._generate_window_embeddings(ru_sentences, ru_starts)

        # One big similarity matrix instead of per-pair cos_sim calls in the DP loop
        sim = util.cos_sim(en_embs, ru_embs).cpu().numpy()

        dp = np.full((n + 1, m + 1), -float("inf"))
        dp[0][0] = 0.0
        parent = [[None] * (m + 1) for _ in range(n + 1)]

        for i in range(n + 1):
            for j in range(m + 1):
                if dp[i][j] == -float("inf"):
                    continue

                # Skip edges: consume sentences on one side without emitting a
                # meaning match. Preferred over force-matching a sub-threshold
                # window, so sentences whose true counterpart is absent land in
                # the unmatched pool instead of a garbage <0.6 match. Unbounded
                # by the band by design: skipping is always allowed, only
                # pairing is confined to the diagonal.
                for en_step in range(1, self.max_window + 1):
                    next_i = i + en_step
                    if next_i <= n:
                        gain = self.skip_penalty * en_step
                        if dp[i][j] + gain > dp[next_i][j]:
                            dp[next_i][j] = dp[i][j] + gain
                            parent[next_i][j] = (i, j, en_step, 0)

                for ru_step in range(1, self.max_window + 1):
                    next_j = j + ru_step
                    if next_j <= m:
                        gain = self.skip_penalty * ru_step
                        if dp[i][j] + gain > dp[i][next_j]:
                            dp[i][next_j] = dp[i][j] + gain
                            parent[i][next_j] = (i, j, 0, ru_step)

                # Match edges (both sides consumed), capped by total span and
                # gated by the diagonal band on the start cell: off-band
                # pairings are never considered, so a divergent region cannot
                # lure a match far off the expected length-ratio line.
                for en_step in range(1, self.max_window + 1):
                    for ru_step in range(1, self.max_window + 1):
                        if en_step + ru_step > self.max_total_span:
                            continue

                        next_i, next_j = i + en_step, j + ru_step

                        if next_i <= n and next_j <= m and self._band_allowed(i, j, k, band):
                            score = float(sim[en_index[(i, en_step)], ru_index[(j, ru_step)]])

                            if score < self.similarity_threshold:
                                current_gain = -2.0
                            else:
                                current_gain = score ** 2

                            if dp[i][j] + current_gain > dp[next_i][next_j]:
                                dp[next_i][next_j] = dp[i][j] + current_gain
                                parent[next_i][next_j] = (i, j, en_step, ru_step)

        alignment = []
        curr_i, curr_j = n, m

        if dp[n][m] == -float("inf"):
            curr_j = int(np.argmax(dp[n]))

        while curr_i > 0 or curr_j > 0:
            if parent[curr_i][curr_j] is None:
                if curr_i <= 0:
                    prev_i, prev_j, en_step, ru_step = curr_i, curr_j - 1, 0, 1
                elif curr_j <= 0:
                    prev_i, prev_j, en_step, ru_step = curr_i - 1, curr_j, 1, 0
                else:
                    prev_i, prev_j, en_step, ru_step = curr_i - 1, curr_j - 1, 1, 1
            else:
                prev_i, prev_j, en_step, ru_step = parent[curr_i][curr_j]

            if en_step == 0:
                # skip_ru: consume ru_step RU sentences without a match
                pass
            elif ru_step == 0:
                # skip_en: consume en_step EN sentences without a match
                pass
            else:
                alignment.append(
                    {
                        "en_start": prev_i,
                        "en_end": curr_i,
                        "ru_start": prev_j,
                        "ru_end": curr_j,
                        "en_text": " ".join(en_sentences[prev_i:curr_i]),
                        "ru_text": " ".join(ru_sentences[prev_j:curr_j]),
                        "score": float(sim[en_index[(prev_i, en_step)], ru_index[(prev_j, ru_step)]]),
                        "en_step": en_step,
                        "ru_step": ru_step,
                    }
                )

            curr_i, curr_j = prev_i, prev_j

        alignment.reverse()

        return alignment

    def _write_results(self, matches, unmatched_ru, unmatched_en, output_path):
        with open(output_path, "w", encoding="utf-8") as f:
            for idx, match in enumerate(matches, 1):
                flag = ""
                if match["score"] < self.similarity_threshold:
                    flag = " [LOW]"

                f.write(
                    f"--- Match #{idx} "
                    f"(score: {match['score']:.4f}, "
                    f"windows: {match['en_step']}x{match['ru_step']})"
                    f"{flag} ---\n"
                )
                f.write(
                    f"EN [{match['en_start'] + 1}-{match['en_end']}]: "
                    f"\"{match['en_text']}\"\n"
                )
                f.write(
                    f"RU [{match['ru_start'] + 1}-{match['ru_end']}]: "
                    f"\"{match['ru_text']}\"\n"
                )
                f.write("\n")

            if unmatched_ru:
                f.write("--- Unmatched RU sentences ---\n")
                for idx, sent in unmatched_ru:
                    f.write(f"RU [{idx + 1}]: \"{sent}\"\n")
                f.write("\n")

            if unmatched_en:
                f.write("--- Unmatched EN sentences ---\n")
                for idx, sent in unmatched_en:
                    f.write(f"EN [{idx + 1}]: \"{sent}\"\n")
                f.write("\n")
