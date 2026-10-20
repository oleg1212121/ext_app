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
