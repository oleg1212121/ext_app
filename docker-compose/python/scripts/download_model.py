"""Download a HuggingFace sentence-transformers model snapshot into the
named volume so it stays available across container recreates.

Usage (run inside the container, with HF_HUB_OFFLINE temporarily unset):

    docker exec -e HF_HUB_OFFLINE=0 ext_python \\
        python /app/scripts/download_model.py \\
        paraphrase-multilingual-MiniLM-L12-v2 \\
        /app/models/minilm

After downloading, activate the model by setting `ALIGN_MODEL_PATH=/app/models/minilm`
in `docker-compose/python/.env`. The next `/align` request lazy-loads it; no
container recreate or restart is needed.
"""

import sys
from pathlib import Path

from sentence_transformers import SentenceTransformer


def main() -> int:
    if len(sys.argv) != 3:
        sys.stderr.write(
            "usage: download_model.py <hf_repo_id> <local_target_dir>\n"
            "  e.g. download_model.py paraphrase-multilingual-MiniLM-L12-v2 /app/models/minilm\n"
        )
        return 2

    repo_id, target = sys.argv[1], sys.argv[2]
    target_path = Path(target)
    target_path.parent.mkdir(parents=True, exist_ok=True)

    print(f"Fetching {repo_id} -> {target}")
    model = SentenceTransformer(repo_id)
    model.save(str(target_path))
    print(f"Saved {repo_id} (dim={model.get_embedding_dimension()}) -> {target}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())