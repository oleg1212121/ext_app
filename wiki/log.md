# Directory Update Log

## 2026-08-03

* **Python service: two-model split + no-rebuild iteration**. Split the single
  BGE-M3 model into two: BGE-M3 (1024-dim) stays for signatures (`/embed`,
  `/embed/batch`, `/cosine/batch`); a new smaller
  `paraphrase-multilingual-MiniLM-L12-v2` (384-dim) powers `/align` for ~2.5–3×
  faster CPU alignment. Coral score distribution differs from BGE-M3 — the
  `ALIGN_DEFAULT_THRESHOLD` likely needs ~0.55 (tunable live). Refactored
  `ext_python` so the image only carries Python+pip packages; source code
  (`ai/`, bind-mounted) and model weights (named Docker volume `ai_models`
  at `/app/models`) are external — rebuilding is only needed for new
  `requirements.txt` packages. Models load lazily (empty FastAPI lifespan +
  `ai/models_cache.py`) so `uvicorn --reload` restarts in ~1–2s after a `.py`
  edit; the first request lazy-loads the model it needs. `docker-compose/python/
  env/.env` is bind-mounted at `/app/env/.env` (directory mount, not single-file)
  and live per-request accessors in `ai/config.py` re-read it on each call, so
  threshold/window/model-path edits apply without container recreate or restart.
  Added `docker-compose/python/scripts/download_model.py` for adding/swapping
  models into the volume. Removed `docker-compose/python/ai/bge_m3_local/` from
  the source tree (now in the named volume). Updated `docker-compose.yml`
  (python service: `command:` `--reload`, `env_file:`, new volumes, top-level
  `ai_models` volume), `Dockerfile` (no `COPY ai/`, no hardcoded `MODEL_PATH`),
  `ai/main.py` (empty lifespan), `ai/config.py` (live accessors), new
  `ai/models_cache.py`, and all `ai/api/*.py` handlers. Updated
  `domains/sentence-alignment.md`.
  * Also updated `architecture/docker-services.md` (python service description,
    new mounts, `ai_models` volume).

## 2026-07-28

* **Entity sentence editing**: Added *Sentences* Filament relation managers to
  `EnEntityResource` and `RuEntityResource`, reachable from each entity list
  via a new Sentences row action. Sentences can be created, edited, deleted,
  and reordered with sparse order gaps maintained by `SparseOrderService`.
  `SentenceType` defaults to `sentence`. Deleting a sentence removes its per-side
  meaning matches and cleans up empty `EnRuMeaningMatch` rows, updating the
  parent match's `linked_count`. Added `EntitySentencesRelationManagerTest`.
  Updated `database/entities-alignment.md`, `domains/sentence-alignment.md`,
  `reference/models.md` (via `wiki:sync`).

## 2026-07-27

* **Python service**: Replaced the `ext_embedding` container with `ext_python`
  (same host port 8001): a restructured FastAPI package under
  `docker-compose/python/ai/` (`api/`, `splitting/`, `signatures/`,
  `similarity/`, `alignment/`) serving BGE-M3 (1024-dim, bind-mounted from
  `ai/bge_m3_local`, `HF_HUB_OFFLINE=1`). Sentence splitting and DP alignment
  moved from PHP into python (`/split`, `/align`); Laravel orchestrates chunks
  and owns all DB writes. Config renamed `services.embedding.*` →
  `services.python.*` (new `align_timeout`, dropped dead batch keys); old
  384-dim e5-small signatures must be regenerated. Updated
  `architecture/docker-services.md`, `architecture/overview.md`,
  `domains/sentence-alignment.md`, `playbooks/run-alignment.md`,
  `database/entities-alignment.md`, `AGENTS.md`.
* **Test safety**: Hardened the test environment against wiping the real
  `ext_app` database: `TestCase::createApplication()` guard aborts the suite
  if the resolved DB is not `ext_app_test`, `phpunit.xml` env entries gained
  `force="true"`, and a committed `.env.testing` pins the test DB. Updated
  the Running Tests playbook.

## 2026-07-26

* **Initialization**: Created the OKF v0.2 bundle: architecture, feature
  domains, database, playbooks, conventions sections, plus auto-generated
  `reference/` concepts via `php artisan wiki:sync`.
* **Tooling**: Added `php artisan wiki:sync` and `php artisan wiki:validate`
  (OKF conformance, broken links, staleness) and a Pest test enforcing
  conformance in the test suite.
* **Integration**: Root `AGENTS.md` gained a "Knowledge base" section routing
  agents here; `wiki/` is bind-mounted into the app container at `/var/wiki`.
