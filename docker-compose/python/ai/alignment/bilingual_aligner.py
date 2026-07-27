import logging
from pathlib import Path

import numpy as np
from sentence_transformers import SentenceTransformer, util

logger = logging.getLogger(__name__)


class BilingualAligner:
    def __init__(self, model=None, chunk_size=100, max_window=3, similarity_threshold=0.4):
        # model accepts a ready SentenceTransformer (to share one loaded model
        # between classes), a model path, or None to load the default local model.
        if model is None:
            model = Path(__file__).parent.parent / "bge_m3_local"
        if isinstance(model, SentenceTransformer):
            self.model = model
        else:
            self.model = SentenceTransformer(str(model))
        self.chunk_size = chunk_size
        self.max_window = max_window
        self.similarity_threshold = similarity_threshold

    def align_lists(self, en_sentences: list[str], ru_sentences: list[str]) -> dict:
        """Align two in-memory sentence lists and return structured matches.

        Returns matches as index spans into the submitted lists plus the
        indices not covered by any match (only possible when one side is
        empty, since the DP forces full coverage of both lists).
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
        embs = self.model.encode(
            window_texts, batch_size=64, show_progress_bar=False, convert_to_tensor=True
        )
        index = {key: pos for pos, key in enumerate(window_keys)}
        return index, embs

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

                for en_step in range(1, self.max_window + 1):
                    for ru_step in range(1, self.max_window + 1):
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

        while curr_i > 0 and curr_j > 0:
            if parent[curr_i][curr_j] is None:
                prev_i, prev_j, en_step, ru_step = curr_i - 1, curr_j - 1, 1, 1
            else:
                prev_i, prev_j, en_step, ru_step = parent[curr_i][curr_j]

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
