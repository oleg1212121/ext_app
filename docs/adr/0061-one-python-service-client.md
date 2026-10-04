# ADR 0061: One client for the python service

Date: 2026-10-04
Status: Accepted

## Context

Laravel talked to the python service at four scattered call sites —
`SentenceSplitter` (`/split`), `SentenceAlignmentService` (`/align`),
`SentenceEnrichmentService` (`/enrich`), `TextSignatureService` (`/embed`) —
and each carried its own copy of the same transport idiom: base-URL config
(`services.python.url`), the retry policy (`[500, 1_500, 3_000]` ms,
connection-error-only, `throw: false`), and the
`RuntimeException("Python … service error: {status} - {body}")` envelope.
The duplication was measured during the 2026-10-03 architecture review, and
the copies had already drifted:

- `TextSignatureService` failed **silently** (`null`) on non-2xx while the
  other three threw — intentional (a signature failure must not block
  entity finalization), but the policy lived in a different shape at each
  site.
- `SentenceAlignmentService` was the only caller with a dedicated timeout
  (`align_timeout`, 600 s).
- `SentenceSplitter` read config inline per call; the other three resolved
  it once in a `create()` factory.
- A dead fallback (`align_timeout` inline default 300 vs `services.php`'s
  600) and an orphaned config key (`has_similar_batch_size`, zero callers)
  had accumulated.

The retry policy itself was verified byte-identical across all four sites,
so this is a transport-only refactor: request-payload assembly
(landmarks, `max_window`, enricher manifests) is alignment/enrichment
semantics and stays with the domain services.

## Decision

- **`App\Classes\PythonClient`** is the one seam between Laravel and the
  python service. It owns the base URL, per-endpoint timeouts, the retry
  policy (`RETRY_DELAYS_MS` — now defined exactly once in the codebase) and
  the error envelope, and exposes typed endpoint methods that own response
  unwrapping: `split()`, `align()`, `enrich()`, `embed()`. The grilling
  session chose typed methods over a generic `post()`: each method's
  json-key/cast block moves into the client, so a caller change to a
  response shape is one edit in one place, and the request-payload
  assembly stays with the domain services (it is not transport).
- **One exception, one throw rule.** Non-2xx responses throw
  `App\Exceptions\PythonClientException` (extends `RuntimeException`,
  alongside `AiProviderException`) with the preserved message shape
  `Python {endpoint} service error: {status} - {body}` — `split`,
  `alignment`, `enrichment`, `embed` labels keep existing test assertions
  meaningful. Per-caller error policy is still allowed but now explicit:
  `TextSignatureService` catches the exception and returns `null`, keeping
  embed best-effort. Connection-level failures are never converted — they
  are retried by the client and rethrown as `ConnectionException`.
- **Construction.** `PythonClient::create()` reads
  `services.python.url/timeout/align_timeout` (defaults
  `http://ext_python:8000` / 30 / 600; the dead 300 fallback is gone). The
  four domain services take it via constructor injection and keep their
  `create()` factories, so the ~10 production `create()` call sites are
  unchanged. `AppServiceProvider` binds `PythonClient` to `create()` for
  container-resolved callers (`SentenceSplitter` in
  `SplitEntityFileSentences`).
- **Scope: the python service only.** `WordTranslationProvider` repeats
  the same retry idiom for external translation APIs (Yandex/Google) but
  has different concerns (headers, API keys, per-provider config); folding
  it into an internal-service client would mix them. Converging later, if
  ever, can build on this seam.

## Consequences

- A change to the transport policy (retry delays, timeouts, error format)
  is one edit in `PythonClient`; a change to a python response shape is one
  edit in the client's unwrapping block. Callers shrink accordingly
  (`SentenceSplitter` loses its HTTP code and inline config reads;
  `SentenceEnrichmentService`'s `callEnrich` disappears entirely).
- `services.python.has_similar_batch_size` is removed (orphaned). No
  python-side change: endpoints, payloads and responses are untouched.
- Tests: `tests/Unit/PythonClientTest.php` covers retry, the endpoint-
  labelled error envelope, per-endpoint unwrapping, embed's null-on-missing-
  vector, and the config-factory URL. The per-service tests keep their
  retry/error assertions — they now exercise them through the seam.
  `Http::fake()` intercepts through the client unchanged.
- Rider cleanups: the dead `align_timeout: 300` inline fallback and the
  orphaned config key are gone; `SentenceSplitter` construction sites
  (1 production job, 13 test sites via a `makeSplitter()` helper) pass the
  client explicitly.
