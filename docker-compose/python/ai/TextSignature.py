from pathlib import Path

import numpy as np
from sentence_transformers import SentenceTransformer

from SentenceSplitter import SentenceSplitter


class TextSignature:
    def __init__(self, model: SentenceTransformer | str | Path | None = None):
        # Accepts a ready SentenceTransformer (to share one loaded model between
        # classes), a model path, or None to load the default local model.
        if model is None:
            model = Path(__file__).parent / "bge_m3_local"
        if isinstance(model, SentenceTransformer):
            self.model = model
        else:
            self.model = SentenceTransformer(str(model))

    def generate(self, text: str, language: str = "en") -> list[float]:
        chunks = self._chunk_text(text, language)
        if not chunks:
            return [0.0] * self.model.get_embedding_dimension()

        embeddings = self.model.encode(chunks, normalize_embeddings=True)

        if len(embeddings) > 1:
            # Weight chunks by length so short chunks don't dominate the mean
            weights = np.array([max(len(chunk), 1) for chunk in chunks], dtype=np.float64)
            vector = np.average(embeddings, axis=0, weights=weights)
            norm = np.linalg.norm(vector)
            if norm > 1e-15:
                vector = vector / norm
        else:
            vector = embeddings[0]

        return vector.tolist()

    def generate_from_file(self, file_path: str | Path, language: str = "en") -> list[float]:
        path = Path(file_path)
        text = path.read_text(encoding="utf-8")
        return self.generate(text, language)

    @staticmethod
    def compare(sig1: list[float], sig2: list[float]) -> float:
        a = np.asarray(sig1, dtype=np.float64)
        b = np.asarray(sig2, dtype=np.float64)

        norm_a = np.linalg.norm(a)
        norm_b = np.linalg.norm(b)

        if norm_a < 1e-15 or norm_b < 1e-15:
            return 0.0

        return float(np.dot(a, b) / (norm_a * norm_b))

    @staticmethod
    def _chunk_text(text: str, language: str = "en", chunk_size: int = 1500) -> list[str]:
        # Proper sentence segmentation (handles abbreviations, ellipsis, etc.)
        sentences = SentenceSplitter(language=language).split_text(text)

        chunks: list[str] = []
        current = ""
        for sentence in sentences:
            if current and len(current) + len(sentence) + 1 > chunk_size:
                chunks.append(current)
                current = sentence
            else:
                current = f"{current} {sentence}" if current else sentence

        if current:
            chunks.append(current)

        return chunks
