from pathlib import Path

import numpy as np
from sentence_transformers import SentenceTransformer


class TextSignature:
    def __init__(self, model_path: str | Path | None = None):
        if model_path is None:
            model_path = Path(__file__).parent / "bge_m3_local"
        self.model = SentenceTransformer(str(model_path))

    def generate(self, text: str) -> list[float]:
        chunks = self._chunk_text(text)
        embeddings = self.model.encode(chunks, normalize_embeddings=True)

        if len(embeddings) > 1:
            vector = np.mean(embeddings, axis=0)
            norm = np.linalg.norm(vector)
            if norm > 1e-15:
                vector = vector / norm
        else:
            vector = embeddings[0]

        return vector.tolist()

    def generate_from_file(self, file_path: str | Path) -> list[float]:
        path = Path(file_path)
        text = path.read_text(encoding="utf-8")
        return self.generate(text)

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
    def _chunk_text(text: str, chunk_size: int = 1500) -> list[str]:
        if len(text) <= chunk_size:
            return [text]

        chunks: list[str] = []
        sentences = text.replace("\n", " ").split(". ")

        current = ""
        for sentence in sentences:
            if len(current) + len(sentence) + 2 > chunk_size and current:
                chunks.append(current.strip())
                current = sentence
            else:
                current = f"{current}. {sentence}" if current else sentence

        if current.strip():
            chunks.append(current.strip())

        return chunks
