import numpy as np
import torch
from sentence_transformers import SentenceTransformer, util


class BilingualAligner:
    def __init__(self, model_name, chunk_size=100, max_window=3, similarity_threshold=0.4):
        self.model = SentenceTransformer(model_name)
        self.chunk_size = chunk_size
        self.max_window = max_window
        self.similarity_threshold = similarity_threshold

    def process(self, en_path, ru_path, output_path):
        en_all = self._read_sentences(en_path)
        ru_all = self._read_sentences(ru_path)

        print(f"EN sentences: {len(en_all)}")
        print(f"RU sentences: {len(ru_all)}")

        en_offset = 0
        ru_offset = 0
        all_matches = []
        chunk_num = 0

        while en_offset < len(en_all):
            chunk_num += 1
            en_chunk = en_all[en_offset : en_offset + self.chunk_size]
            ru_chunk = ru_all[ru_offset : ru_offset + self.chunk_size]

            print(
                f"Chunk {chunk_num}: EN[{en_offset}:{en_offset + len(en_chunk)}] "
                f"RU[{ru_offset}:{ru_offset + len(ru_chunk)}]"
            )

            if len(en_chunk) == 0:
                break

            if len(ru_chunk) == 0:
                break

            matches, en_advanced, ru_advanced = self._align_chunk(en_chunk, ru_chunk)

            for m in matches:
                m["en_start"] += en_offset
                m["en_end"] += en_offset
                m["ru_start"] += ru_offset
                m["ru_end"] += ru_offset

            all_matches.extend(matches)

            if en_advanced == 0:
                print("No progress in chunk, breaking to avoid infinite loop")
                break

            en_offset += en_advanced
            ru_offset += ru_advanced

        unmatched_ru = []
        if ru_offset < len(ru_all):
            unmatched_ru = [(i, ru_all[i]) for i in range(ru_offset, len(ru_all))]

        unmatched_en = []
        if en_offset < len(en_all):
            unmatched_en = [(i, en_all[i]) for i in range(en_offset, len(en_all))]

        self._write_results(all_matches, unmatched_ru, unmatched_en, output_path)

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
        window_mapping = {}
        for start in range(len(sentences)):
            for step in range(1, self.max_window + 1):
                if start + step <= len(sentences):
                    combined_text = " ".join(sentences[start : start + step])
                    window_texts.append(combined_text)
                    window_mapping[(start, step)] = len(window_texts) - 1
        embs = self.model.encode(
            window_texts, batch_size=64, show_progress_bar=False, convert_to_tensor=True
        )
        return {key: embs[idx] for key, idx in window_mapping.items()}

    def _align_chunk(self, en_sentences, ru_sentences):
        n = len(en_sentences)
        m = len(ru_sentences)

        if n == 0 or m == 0:
            return [], n, m

        en_windows = self._generate_window_embeddings(en_sentences)
        ru_windows = self._generate_window_embeddings(ru_sentences)

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
                            emb_en = en_windows[(i, en_step)]
                            emb_ru = ru_windows[(j, ru_step)]

                            score = util.cos_sim(emb_en, emb_ru).item()

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

            en_matched = en_sentences[prev_i:curr_i]
            ru_matched = ru_sentences[prev_j:curr_j]

            emb_en = en_windows[(prev_i, en_step)]
            emb_ru = ru_windows[(prev_j, ru_step)]
            link_score = util.cos_sim(emb_en, emb_ru).item()

            alignment.append(
                {
                    "en_start": prev_i,
                    "en_end": curr_i,
                    "ru_start": prev_j,
                    "ru_end": curr_j,
                    "en_text": " ".join(en_matched),
                    "ru_text": " ".join(ru_matched),
                    "score": link_score,
                    "en_step": en_step,
                    "ru_step": ru_step,
                }
            )
            curr_i, curr_j = prev_i, prev_j

        alignment.reverse()

        en_advanced = n
        ru_advanced = m
        if alignment:
            last = alignment[-1]
            en_advanced = last["en_end"]
            ru_advanced = last["ru_end"]

        return alignment, en_advanced, ru_advanced

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


