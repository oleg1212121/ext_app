"""Batch cosine similarity (ported from the old embedding service main.py).

The Laravel duplicate-detection path sends one query signature against a
buffer of candidate signatures and expects one similarity per candidate.
"""

import numpy as np


def batch_cosine_similarity(
    query: list[float], candidates: list[list[float]]
) -> list[float]:
    if not candidates:
        return []

    q = np.array(query, dtype=np.float64)
    c = np.array(candidates, dtype=np.float64)

    if c.ndim == 1:
        c = c.reshape(1, -1)
    if q.shape[0] != c.shape[1]:
        raise ValueError("query and candidate dimension mismatch")

    # Cosine against each row of c (rows need not be normalized)
    norms = np.linalg.norm(c, axis=1)
    norm_q = float(np.linalg.norm(q))

    if norm_q < 1e-15 or np.any(norms < 1e-15):
        similarities: list[float] = []
        for i in range(c.shape[0]):
            na = float(norms[i])
            nb = norm_q
            if na < 1e-15 or nb < 1e-15:
                similarities.append(0.0)
            else:
                similarities.append(float(np.dot(c[i], q) / (na * nb)))
    else:
        similarities = (c @ q / (norms * norm_q)).tolist()

    return similarities
