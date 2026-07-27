# Directory Update Log

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
