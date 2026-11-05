# ADR 0082: Ask-AI word enrichment merges inline with per-row provenance, locks on success, and keeps translations link-only

Date: 2026-10-19
Status: Accepted

Implements popup-ai-enrich spec decision 4 (ticket 04; ticket 05's
adopt-on-ask reuses the same seam for the missing-word path). Extends the
ADR [0078](0078-goldendict-plain-words-link-only-translations.md) /
[0079](0079-form-of-relays-demote-to-forms-stage-link-prune.md) link-only
line to a new writer — the AI — and records the three trade-offs the spec
grilled: merge-inline vs own-block, lock-on-success vs attempt caps, and
per-row `source` vs a visual marker.

## Context

The word popup's dictionary content comes from imports (kaikki, GoldenDict,
frequency lists) and the provider fetch — and for genuinely sparse words
(no definitions, no transcriptions, one or zero translations) it stays
thin forever: no import will grow a word the dictionaries barely cover.
The user's own AI key is the only source that can enrich such a word on
demand. The spec's ask-AI icon raises three design questions:

1. **Where does the AI content live?** A separate "AI answers" block would
   quarantine the content away from the dictionary tables every other
   consumer (the Anki card builder, the completeness score, future
   importers' dedup) already reads — and would need its own ordering,
   cap and rendering rules. Merging into the existing tables reuses all
   of them.
2. **How often can a word be asked?** Enrichment is not idempotent-cheap
   like the Context explanation (a per-sentence answer never stored): it
   writes rows, so an unbounded ask-again loop re-spends tokens to write
   duplicates the dedup would only discard.
3. **What do translations become?** The AI answers with native-language
   candidate words — exactly the shape ADR 0078 banned from creating
   target rows on every writer.

## Decision

1. **Merge-inline, per-row provenance.** The parsed reply writes straight
   into `definitions` / `transcriptions` / `examples` / `etymologies` —
   the same rows every consumer reads — and each NEW row carries
   `source = 'ai'` (new nullable `source` columns; null = imported). The
   popup renders no visual marker: provenance is DB-only, queryable, and
   costs no popup space. Exact-text dedup runs per table before insert
   (examples get it from their unique `(example, word_id)` constraint,
   the rest from a pre-check), so a re-ask never duplicates; reply
   parsing is lenient (`App\Support\EnrichmentReply` — fences and prose
   stripped, shapes coerced to string lists) with counts capped (12
   translations / 8 definitions / 4 each of the rest) and lengths
   truncated to the column limits (translations, being lookup
   candidates, are dropped past 256 instead of truncated — a cut word is
   no word).
2. **Lock on success, ledger-carried.** One `word_ai_fetches` row per
   word (unique) records provider, model, status, attempts, error,
   `asked_at`, `succeeded_at`. A `succeeded` row is the forever lock: the
   endpoint rejects further asks (409) and the toolbar's icon greys with
   an "already asked" tooltip (the payload's `ai_asked`). Failed attempts
   — no model chosen (recorded nowhere: the row materializes only when an
   ask is attempted), no usable key, provider errors, unparseable replies
   — stay retryable at any count, each retry bumping `attempts`. Unlike
   the translation fetch's 6-attempt/24h machine cap, the ask is a
   deliberate user action on a throttled (20/min) endpoint: the cost
   guard is the lock, not an attempt ceiling. A reply that parses but
   lands nothing new finalizes as **succeeded** (2026-10-19 review
   revision of the original "failed" ruling: the ask itself succeeded and
   the content is already present — leaving such a word retryable let an
   already-rich word be re-asked endlessly, each pass spending tokens,
   and the original "retryable without re-spending on locked words"
   benefit cut the other way). Pending rows are a grace, not a lock: one
   pending longer than 15 minutes
   (`ReadingAiController::PENDING_STALE_AFTER_MINUTES` — provider cURL
   timeouts cap at 120s) is a crashed worker's orphan, and the retry
   takes it over instead of stranding the word "in progress" forever
   (same review pass; spec decision 4 locks by success only).
3. **Translations stay link-only (ADR 0078 holds for AI).** Translation
   candidates resolve against the native language's EXISTING words
   through the shared `TranslationTargets` resolver (wanted classes per
   the same rules — `phrase` for multi-word, the source word's class
   mapped for single words) and link as canonical `word_translations`
   pairs; the unmatched stage into the word's `translations` JSON through
   the shared `StagedTranslations` merge for the later link pass. The AI
   never creates a word row — ZERO, the invariant the whole 0078/0079
   line exists for.
4. **The response is the word-show payload.** `GET /words/{word}` and
   `POST /ai/word-enrich` share one assembler (`App\Classes\WordPopupPayload`),
   so the popup swaps the enrich response in live (`setData`) and the two
   shapes can never drift; the word is touched on success, which makes
   ticket 02's stored completeness stale and the swap-in payload (built
   through `WordFamily::resolve`) recalculates it in the same request.
   The prompt template lives admin-editable in `prompt_templates`
   (`word.enrichment`) with a code fallback, like the explanation prompt.
   AI transcriptions reuse the language's seeded transcription types
   (IPA first) — the taxonomy is never minted from an AI answer; a
   language with no types gets no AI transcriptions.

## Consequences

- Enriched content participates everywhere automatically: the
  completeness score counts it, the Anki Body builder exports it, family
  ordering ranks it — no per-consumer AI special cases.
- The `source` columns are the audit trail for import quality questions
  ("did an AI write this definition?") without a join; importers ignore
  the column and their rows stay null.
- A succeeded lock is permanent by design: if the AI answered poorly, the
  user's recourse is editing content in the admin panel, not re-asking
  (the dedup would mostly discard a re-ask anyway — only a better answer
  with different text would add anything).
- Ticket 05 (adopt-on-ask) runs the same seam after creating the word
  (`l_word` per ADR 0081, unknown class, lemma form): the missing-word
  card's ask creates first, enriches second, and swaps to the dictionary
  view with the same payload contract.
- The explain endpoint's testing rule extends here: `AIModelResolver` is
  mocked at the container — provider calls are raw cURL, invisible to
  `Http::fake` (`tests/Feature/AiWordEnrichEndpointTest.php`,
  `tests/Unit/EnrichmentReplyTest.php`).

## Amended 2026-10-20 (popup-ai-enrich ticket 08)

The prompt is **monolingual**. The original `word.enrichment` fallback
ordered definitions and etymology notes in the user's native language
("Reply in :native … short :native definitions") and dutifully produced
Russian definitions for English words — but the dictionary is
monolingual-Wiktionary-style: an entry defines its word in the word's own
language, enforced on imports by the `DefinitionLanguage` majority-script
guard. The fallback now orders definitions, etymology notes and examples
in `:language` (the word's own language), transcriptions in IPA, and
keeps only the translations in `:native`; decision 4's "admin-editable in
`prompt_templates`" sentence is now concrete — the template is seeded
`firstOrCreate` (ticket 08), so the row is editable without a code
deploy.

Decision 2's succeeded lock gains a guard rider: AI definitions pass the
import's `DefinitionLanguage::keeps()` at the merge, so a disobedient
model's foreign-script definitions drop instead of being stored — and an
all-dropped reply still finalizes **succeeded** (only unparseable replies
stay 502; a reply whose every definition dropped is not a failed ask).
Etymologies remain deliberately unguarded, the import's parity.

## Amended 2026-10-20 (re-ask: the succeeded lock lifted)

The forever lock is **superseded** — "the user's recourse is editing
content in the admin panel, not re-asking" proved wrong in practice: the
whole point of an on-demand, own-key enrichment is that a bad answer can
be retried. Decision 2's succeeded row is no longer a lock:

- `ledgerBlock()` drops the succeeded branch — the endpoint's only
  remaining 409 is the fresh-pending "in progress" gate. A succeeded word
  asked again runs the full flow on the SAME ledger row (attempts++,
  back to pending, finalize succeeded again).
- The toolbar's icon no longer greys: a payload with `ai_asked` true
  shows the **re-ask variant** — the same sparkles inside the
  chasing-arrows refresh ring — with an "Ask AI again (already enriched)"
  tooltip saying this is not the first ask. `ai_asked` is judged on
  `succeeded_at` (not the current status), so a FAILED re-ask keeps the
  first success's timestamp and the icon stays the re-ask variant.
- A re-ask **replaces** the previous AI rows: `applyParsed` deletes the
  word's `source = 'ai'` rows (definitions / transcriptions / examples /
  etymologies) inside the merge transaction before the fresh reply
  merges. The original exact-text-dedup argument for additive merging
  ("a re-ask would only add differently-worded variants") is exactly why
  replace wins now — the variants would pile up. Imported rows (null
  source) are never touched; AI-linked translations have no per-row AI
  attribution to select on and stay.
- The cost guard moves from the lock to the existing route throttle
  (20/min) — the ask remains a deliberate user action, and the replace
  semantics mean a re-ask can no longer grow the dictionary on every
  pass.

The one-ask ledger row, the pending grace window, failed-stays-retryable,
the dedup-vs-imported, link-only translations, and the shared payload
assembler all keep their original rulings.

## Amended 2026-10-20 (word-level source badge in the popup header)

Decision 1's "the popup renders no visual marker" is **superseded at word
level only** (popup-provenance ticket 04): the popup header renders a
small muted plain-text badge next to the headword, stating where the WORD
came from — the importer name verbatim (the closed `words.source`
vocabulary, ADR 0084) plus " · ai" when the payload's `ai_asked` reports
a succeeded ask, "ai" alone when the word was AI-enriched but no importer
ever stamped its row, nothing at all when neither is known (pre-provenance
rows stay honest). An adopt-on-ask word carries `source = 'ai'` (ADR 0084)
AND `ai_asked` true from that same ask — the badge dedups the duplicate and
renders plain "ai", never "ai · ai". The one shared assembler (decision 4) gained the
top-level `source` beside `ai_asked`, so both endpoints stay
shape-identical. Per-row content stays unmarked: the per-row `source`
columns (null = imported / 'ai') are unchanged, and an imported vs an
AI-written definition still reads identically in the popup — the badge
speaks about the word's row, never about which content rows the AI wrote.

## Amended 2026-11-05 (word-popup-overhaul ticket 06: admin-only, queued, fire-and-forget)

The ask-AI flow splits in two: the HTTP request does everything cheap,
and a queued job does everything that spends the provider's time. The
merge semantics above are untouched — same prompt, same lenient parse,
same replace-on-re-ask transactional merge, same link-only translations,
same ledger row; only who may ask, where the AI call runs, and what the
endpoint answers changed.

- **Admin-only.** The sparkles icon renders for **approved admins only**
  — hidden, not disabled, for everyone else (plain and re-ask variants,
  dictionary and missing cards, read off the shared `auth.user`; the
  profile Popups-tab preview passes the same gate) — and `POST
  /ai/word-enrich` answers a non-admin **403** in the reading-surfaces
  JSON shape ("Only approved admins can ask the AI to enrich a word.")
  before any gate, mint or ledger row: the ask spends the asker's own
  BYO-key tokens, so readers fund nothing they cannot trigger. A lapsed
  admin (unapproved) bounces earlier still, at the route's `approved`
  middleware redirect; the controller's `is_approved` half is
  defense-in-depth.
- **The dispatch half.** The click validates, runs the admin gate, binds
  the word (both ledger gates — the fresh-pending "in progress" 409; a
  truly simultaneous double-ask whose ledger insert bounces the unique
  word constraint gets the same 409, never a 500), resolves model + key
  **cheaply — no HTTP** (the existing 400 "Choose an AI model in your
  profile settings." / invalid-model guidance answers here and dispatches
  nothing; a keyless user resolves no model at all, the resolver
  filtering unkeyed providers out, so the same 400 answers — the
  distinct 403 "You have not added an API key for this provider." now
  exists only inside the job), adopts/mints the word synchronously on the
  missing path (the model gate still precedes the mint), records the
  pending ledger row (attempts++, `asked_at` refreshed — the stale-window
  takeover unchanged), dispatches
  **`App\Jobs\FetchWordAiEnrichment($wordId, $userId)`** and answers the
  minimal **`{queued: true}`**. Provider and parse failures no longer
  answer HTTP statuses at all (the old 429/502 mappings are gone): the
  endpoint answers 200 `{queued: true}` or 409 — or the dispatch-time
  guidance 400s / the admin 403. The optional `surface` still rides the
  request through validation, but with no response payload the
  `is_form`/surface-normalized resolution belongs to the next `GET
  /words/{id}?surface=` (the 409 refetch and every next open).
- **The queued half.** The job carries scalar ids only — the word's and
  the ASKING user's — and resolves model AND BYO key **inside the worker
  against that user** through `AIModelResolver`'s explicit-user seam
  (optional `?User` parameters on `resolveExplanationModel` /
  `resolveAnswerModel` / `getGroupedModelChoices` / `ask`, defaulting to
  `Auth::user()`; the grouped-choices memo keyed per user id so the
  session path and the job path can never cross) — never the
  authenticated session, which a worker does not have, so the ask spends
  the asker's own key whatever worker picks it up. It then runs the
  prompt ask → the lenient parse → the transactional merge → the
  succeeded finalize → the word touch. Known failures (no-model,
  invalid model, provider exceptions including the keyless 403,
  unparseable replies, a word or asker vanished between dispatch and
  run) finalize the ledger failed and return **silently** — nothing
  surfaces client-side, the icon simply stays retryable, and the ledger
  row is the only failure record; an unexpected `Throwable` finalizes
  failed then rethrows (the `failed_jobs` record, still no retry).
- **`$tries = 1`, `$timeout = 300`, deliberately NOT `ShouldBeUnique`.**
  No auto-retry: a failed run finalizes the ledger failed once and the
  user re-asks via the still-retryable icon — queue-level retries would
  double-spend tokens against the asker's own key. The timeout sits
  above the provider layer's 120s cURL cap, and a run the timeout kills
  finalizes nothing: decision 2's 15-minute stale-pending window — whose
  failure mode now reads "crashed (or timeout-killed) worker" — is
  exactly the net for that orphan. Uniqueness is refused on purpose: the
  per-word unique ledger constraint plus the dispatch-time fresh-pending
  409 are the double-ask race net, and a uniqueness lock would fight the
  stale-pending takeover.
- **Decision 4's "the response is the word-show payload" is superseded.**
  No payload comes back to swap live: the popup keeps its content, floats
  a transient "AI enrichment started" notice, and the enriched content
  (`ai_asked` included — the re-ask variant) appears the next time the
  popup opens, through the unchanged 409-refetch / next-GET path. The
  shared `WordPopupPayload` assembler still builds every payload the
  popup ever swaps, so the endpoint shapes cannot drift — there is just
  one left.
- **The UX rides ticket 04's info popup**: dispatch-time errors land
  verbatim in the floating layer (sticky, with Retry + the go-to-settings
  link), the queued success floats the auto-dismissing started notice
  (the `word.ask_ai_started` ui-string, en/ru/be), and a failed job says
  nothing anywhere.

Consequence: the AI call's duration — up to the provider's own two-minute
ceiling — no longer occupies an HTTP worker; a queue worker does.
