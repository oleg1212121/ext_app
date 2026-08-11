import logging
from collections import OrderedDict
from pathlib import Path

import numpy as np
import torch
from sentence_transformers import SentenceTransformer, util

from ai import config

logger = logging.getLogger(__name__)


class EmbeddingCache:
    """Process-level LRU cache of window embeddings.

    The dominant per-chunk cost is embedding encode, and the same window texts
    recur heavily: across chunk seams (~2 sentences re-aligned per boundary)
    and across entities that share source material. Caching by
    (model id, joined window text) means each unique window is embedded once
    per process lifetime instead of once per chunk.
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

    def align_lists(self, en_sentences: list[str], ru_sentences: list[str]) -> dict:
        """Align two in-memory sentence lists and return structured matches.

        Returns matches as index spans into the submitted lists plus the
        indices not covered by any match. Indices not covered are exactly the
        sentences the DP chose to skip (no counterpart on the other side) —
        with the skip branch the DP no longer force-aligns every sentence, so
        a sentence with no genuine translation lands here instead of in a
        low-similarity meaning match.
        """
        matches = self._align_chunk(en_sentences, ru_sentences)

        matched_en: set[int] = set()
        matched_ru: set[int] = set()
        for match in matches:
            matched_en.update(range(match["en_start"], match["en_end"]))
            matched_ru.update(range(match["ru_start"], match["ru_end"]))

        return {
            "matches": [
                {
                    "en_start": m["en_start"],
                    "en_end": m["en_end"],
                    "ru_start": m["ru_start"],
                    "ru_end": m["ru_end"],
                    "score": m["score"],
                }
                for m in matches
            ],
            "unmatched_en": [i for i in range(len(en_sentences)) if i not in matched_en],
            "unmatched_ru": [i for i in range(len(ru_sentences)) if i not in matched_ru],
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

            matches = self._align_chunk(en_chunk, ru_chunk)

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

    def _generate_window_embeddings(self, sentences):
        window_texts = []
        window_keys = []
        for start in range(len(sentences)):
            for step in range(1, self.max_window + 1):
                if start + step <= len(sentences):
                    window_texts.append(" ".join(sentences[start : start + step]))
                    window_keys.append((start, step))

        model_id = id(self.model)
        embs = [None] * len(window_texts)
        missing = []

        for pos, text in enumerate(window_texts):
            cached = _EMBEDDING_CACHE.get((model_id, text))
            if cached is not None:
                embs[pos] = cached
            else:
                missing.append(pos)

        if missing:
            batch = self.model.encode(
                [window_texts[pos] for pos in missing],
                batch_size=64,
                show_progress_bar=False,
                convert_to_tensor=True,
            )
            for k, pos in enumerate(missing):
                vector = batch[k]
                _EMBEDDING_CACHE.put((model_id, window_texts[pos]), vector)
                embs[pos] = vector

        index = {key: pos for pos, key in enumerate(window_keys)}
        return index, torch.stack(embs)

    def _align_chunk(self, en_sentences, ru_sentences):
        n = len(en_sentences)
        m = len(ru_sentences)

        if n == 0 or m == 0:
            return []

        en_index, en_embs = self._generate_window_embeddings(en_sentences)
        ru_index, ru_embs = self._generate_window_embeddings(ru_sentences)

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
                # the unmatched pool instead of a garbage <0.6 match.
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

                # Match edges (both sides consumed), capped by total span.
                for en_step in range(1, self.max_window + 1):
                    for ru_step in range(1, self.max_window + 1):
                        if en_step + ru_step > self.max_total_span:
                            continue

                        next_i, next_j = i + en_step, j + ru_step

                        if next_i <= n and next_j <= m:
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
