---
type: Feature
title: Interactive Words
description: Interactive words on the reader and bilinguals simulator — EVERY tokenizer word token (≥2 letters, hyphens/apostrophes intra-word) is Ctrl+clickable (popup-ai-enrich ticket 03): dictionary-linked tokens open word popups aggregating the word family (own headword group plus base-word groups via the forms table, class-scoped to claiming entries, form-of entries hidden behind a pointer line, ADR 0045 + 0047; entries stable-sort completeness-descending over that assembly order — stored words.completeness 0-7, recalculated lazily when stale), unmapped tokens open a missing-word card (raw surface title + honest not-in-the-dictionary note, nothing fetched, no lookup events, Ask-AI placeholder only until adopt-on-ask), per-user block visibility via Popup preferences, ADR 0046; typography follows the host page's font setting, ADR 0031), an action toolbar as the popup's first row (progress icons left; pushed right, in order — Anki send, the on-demand Explanation toggle, the Ask-AI icon (one ledger-locked BYO-key enrichment per word, merged inline under per-row source='ai' provenance, ADR 0082), the robot (models-used) icon rightmost; status lines beneath; disabled-not-hidden), a per-word Anki send that detects the word on open and refreshes an already-saved own note in place, an on-demand Explanation toggle mounting the AI Context explanation as the dictionary view's first row (no tab strip since popup-ai-enrich ticket 01 — the dictionary always renders), render-time segmentation, read/lookup familiarity events, and silent background auto-fetch of missing translations (Yandex→Google, link-only since 2026-10-18 — matches link existing target words, the rest stages for the link pass, never a word row; ledger-deduped with a permanent exclusions list).
tags: [reader, bilinguals, dictionary, words, ai, anki, react, inertia]
status: stable
stale_after: 2027-04-15
generated: { by: agent:zcode, at: 2026-10-19T21:05:00+03:00 }
verified: { by: agent:zcode, at: 2026-10-18T16:15:00+03:00 }
sources:
  - id: word-controller
    resource: laravel/app/Http/Controllers/WordController.php
    title: WordController (word details + familiarity + events)
  - id: popup-payload
    resource: laravel/app/Classes/WordPopupPayload.php
    title: WordPopupPayload (the one payload assembler both popup endpoints share)
  - id: enrich-controller
    resource: laravel/app/Http/Controllers/ReadingAiController.php
    title: ReadingAiController::enrichWord (ask-AI word enrichment) + explainWord
  - id: enrich-request
    resource: laravel/app/Http/Requests/AiWordEnrichRequest.php
    title: AiWordEnrichRequest ({word_id} validation)
  - id: enrich-service
    resource: laravel/app/Classes/WordAiEnrichmentService.php
    title: WordAiEnrichmentService (transactional merge: content rows + link-only translations)
  - id: enrich-reply
    resource: laravel/app/Support/EnrichmentReply.php
    title: EnrichmentReply (lenient strict-JSON parser — fences, coercion, caps, truncation)
  - id: word-ai-fetch
    resource: laravel/app/Models/WordAiFetch.php
    title: WordAiFetch (the ask-AI ledger — succeeded = the lock)
  - id: word-completeness
    resource: laravel/app/Classes/WordCompleteness.php
    title: WordCompleteness (the 0-7 completeness matrix + lazy staleness recalc)
  - id: recalc-command
    resource: laravel/app/Console/Commands/WordsRecalcCompletenessCommand.php
    title: words:recalc-completeness (chunked set-based backfill, --stale-only)
  - id: fetch-job
    resource: laravel/app/Jobs/FetchWordTranslations.php
    title: FetchWordTranslations (provider-chain translation job)
  - id: fetch-service
    resource: laravel/app/Classes/WordTranslationFetchService.php
    title: WordTranslationFetchService (dispatch gate + exclusions)
  - id: translation-targets
    resource: laravel/app/Support/TranslationTargets.php
    title: Shared link-only target-word resolver (matches candidates against existing rows)
  - id: staged-translations
    resource: laravel/app/Support/StagedTranslations.php
    title: Shared staged-translations JSON merge/prune helpers
  - id: resolver
    resource: laravel/app/Classes/WordTranslations/WordTranslationResolver.php
    title: WordTranslationResolver (Yandex → Google chain)
  - id: adoption
    resource: laravel/app/Classes/EntityWordAdoption.php
    title: EntityWordAdoption (entity tokens become dictionary words)
  - id: word-explain-endpoint
    resource: laravel/app/Http/Controllers/ReadingAiController.php
    title: ReadingAiController::explainWord (AI Context explanation)
  - id: word-explain-request
    resource: laravel/app/Http/Requests/AiWordExplainRequest.php
    title: AiWordExplainRequest (explain payload validation)
  - id: adr-preferences
    resource: docs/adr/0035-per-user-ai-model-preferences.md
    title: ADR 0035 — Per-user AI model preferences, resolved server-side
  - id: familiarity-service
    resource: laravel/app/Classes/WordFamiliarityService.php
    title: WordFamiliarityService (ledger-deduplicated deltas)
  - id: word-map
    resource: laravel/app/Classes/EntityWordMap.php
    title: EntityWordMap (per-entity word map)
  - id: word-text
    resource: laravel/resources/js/Components/WordText.jsx
    title: WordText (segmenting renderer)
  - id: word-popup
    resource: laravel/resources/js/Components/WordPopup.jsx
    title: WordPopup (lazy detail popup — toolbar + always-rendered dictionary, no tab strip)
  - id: familiarity-lib
    resource: laravel/resources/js/lib/wordFamiliarity.js
    title: Browser familiarity helpers (event POST, word ids, map patching)
  - id: tokenizer
    resource: laravel/resources/js/lib/wordTokenizer.mjs
    title: Browser tokenizer (port of App\Classes\WordTokenizer)
  - id: adr
    resource: docs/adr/0028-numeric-word-familiarity.md
    title: ADR 0028 (numeric word familiarity)
  - id: adr-popup-typography
    resource: docs/adr/0031-popup-typography-follows-page-font.md
    title: ADR 0031 (popup typography follows the host page's font setting)
  - id: popup-visibility
    resource: laravel/app/Support/PopupVisibility.php
    title: PopupVisibility (saved opt-outs resolved over all-visible defaults)
  - id: adr-popup-visibility
    resource: docs/adr/0046-per-user-popup-section-visibility.md
    title: ADR 0046 (per-user popup section visibility)
  - id: adr-family-class-scope
    resource: docs/adr/0047-class-scoped-word-family-base-groups.md
    title: ADR 0047 (class-scoped word-family base groups)
  - id: adr-anki
    resource: docs/adr/0077-browser-side-ankiconnect.md
    title: ADR 0077 (Anki export is browser-side; values-always-sent + hide-class template semantics; addendum v3 — re-sends update own notes in place)
  - id: adr-enrich
    resource: docs/adr/0082-ask-ai-enrichment-merge-inline-lock-on-success.md
    title: ADR 0082 (ask-AI enrichment merges inline with per-row provenance, locks on success, translations link-only)
  - id: anki-word-config
    resource: laravel/resources/js/lib/ankiWordConfig.js
    title: ankiWordConfig.js (the popup's per-word Anki sync config — deck/fields from the word's resolved language entry, note type follows it)
---

# What it does

Makes words in a text interactive on both reading surfaces: **every
tokenizer word token** (≥2 letters, hyphens/apostrophes intra-word —
`bomb-hit` is one token) opens a popup on **Ctrl+click**
(popup-ai-enrich ticket 03). Tokens linked to a dictionary **Word** open the
**Word popup** — one
section per part of speech recorded under the headword (language + `l_word`),
each with its definitions, transcriptions, translations (native language
first), examples and etymology, plus an **action toolbar as the popup's
first row** — "I know this word" and "Remove mark" icons on the left;
pushed right, in order: the always-visible Anki send icon, the on-demand
Explanation toggle, the Ask-AI icon (one ledger-locked BYO-key
enrichment per word, merged inline — below), and the robot (Models-used)
icon rightmost —
with the Anki status lines directly beneath (the two progress icons are the
only toolbar elements
the `progress_actions` visibility opt-out still hides; inapplicable
toolbar icons disable rather than disappear, and the Explanation toggle +
robot additionally require an explain payload and answer to the ADR 0046
`explanation` key). There is **no tab strip**: the dictionary content always
renders, and a hairline closes the header block (word / familiarity /
frequency / form-of). Unmapped tokens open the **missing-word card**
(below) instead. A plain click does
nothing (ADR 0030), and a plain
double-click natively selects the word so browser extensions (translators,
dictionaries) can act on it. Their text is
tinted by the reader's **Word familiarity** (0–19 → rose, 20–59 →
amber, 60–99 → verdigris, ≥ 100 → green). Revealing a sentence pair on the
simulator credits its words a read (+1); opening a word's popup costs a
lookup (−2), once per word per sentence pair on both surfaces. All
segmentation is derived at render time — **no word positions are stored
anywhere** (ADR 0027); exposure events are ledgered instead (ADR 0028).

# How it works

1. **Server ships a word map, not markup.** Row text travels as plain
   strings; the page payload carries a compact per-entity map
   `{l_word: {w: word id, s: familiarity 0-100|null}}` built by
   `EntityWordMap` (dictionary-linked `entity_words` left-joined to the
   user's `user_word` rows). Unlinked tokens are absent from the map → the
   missing-word card.
2. **The browser segments the text.** `WordText` runs
   `lib/wordTokenizer.mjs` — a JS port of the PHP `WordTokenizer` regex —
   over each row and renders EVERY word token as an interactive
   **`role="button"` span**, mapped or not (popup-ai-enrich ticket 03) —
   not real `<button>` elements: Chromium treats button labels as
   widget chrome, so a double-click on a real button never produces the
   native text selection that browser extensions need. The spans keep the
   button keyboard contract (focusable; Ctrl+Enter / Ctrl+Space open the
   popup). Keys carry the shared `WordLookupKey` pipeline — lowercase,
   combining marks and zero-width format characters stripped, ё→е folded —
   so a stressed or ё-spelled token looks up the plain/folded entry
   (ёлка → `елка`). Parity is enforced by
   `tests/Unit/TokenizerParityTest.php`
   (PHP vs node CLI on shared fixtures, ё/mark/zero-width samples
   included); a mismatch breaks coloring, not
   text.
3. **Popup details are lazy, per word family.** Ctrl-clicking a word fetches
   `GET /words/{word}?surface={l_word}`. `WordFamily::resolve` (ADR 0045)
   builds the entry list: **every** `words` row sharing the linked row's
   headword — the linked row first, siblings after in
   `EntityWordLinker::classPriority` order — followed by the headword groups
   of every **Base word** the `forms` table maps the surface token to (own
   headword excluded), ranked by `words.frequency` (lower = more common) and
   uncapped, as
   `entries: [{id, word, word_class, transcriptions, definitions, translations,
   examples, etymologies}]`. Each base group is **scoped to the word classes
   whose entries actually claim the surface as a form** (ADR 0047) — "me"
   pulls in only "I — Pronoun", not the character/numeral entries sharing the
   spelling, and "melted" no longer rides in "melt — Noun". Relay
   **Form-of entries** (all definitions
   boilerplate like "past participle of the verb melt") are hidden when a
   base group carries the real content, and stray relay glosses are filtered
   out of partially-relay entries ("saw/verb" mixes real senses with "simple
   past of see"). The assembled list then **stable-sorts
   completeness-descending** (popup-ai-enrich ticket 02): every entry's
   stored `words.completeness` (0–7, see
   [Dictionary](/database/dictionary.md)) is refreshed first — lazily: a
   word whose `completeness_at` is missing or older than the word row or
   any content child (definitions/transcriptions/examples/etymologies/
   forms, translation links included) is recomputed and re-stamped during
   the resolve (`WordCompleteness::recalcFamily`), fresh words are left
   untouched — and the richest entry then leads, with the assembly order
   (linked word first, class priority, base-group frequency) preserved as
   the tiebreak for equal scores (a sparse linked entry can sink below a
   rich base entry; the popup header still binds the linked word —
   `data.id`/`data.word` ride independently of position). The payload then
   carries `form_of: [base headwords]`,
   rendered as "«surface» — form of «…»" — as is `is_form`, true when the
   surface differs from the word's `l_word` (an inflected form resolved by
   the linker's forms pass) — and `frequency`, the bound headword's integer
   rank (null when unranked; the sentinel 1,100,000 included), shown as a
   "Frequency: #N" line. The popup title shows the clicked word only — each
   section carries the POS itself in its **"WORD — pos" label** (headword
   uppercase, POS lowercase, em dash): "MELT — verb" for a base group's own
   headword, "MELTED — adjective" for the popup-headword sections.
   The popover renders one section per entry, top to bottom:
   **translations are the primary line** (native language first, capped at 8
   with a "+N more…" expander; a section with none shows the calm
   "no translations yet — automatic translation may still be in progress"
   note, since the background fetch only lands on the next open), then the
   **main transcription** in brackets with a "+N more" toggle revealing the
   rest, then **three counted collapses** — "Definitions (N)", "Examples
   (N)", "Etymology (N)" — independent `<details>`, closed by default, state
   per section (empty definitions say "No definitions yet."; sections
   without examples/etymology hide those collapses). The definitions
   `<ol>` places its markers *inside* the list items
   (`list-style-position: inside`) so wide ("100.") numbers can never spill
   past the card edge.
   **Every element inside the card carries a stable `word-popup-*` class**
   (the portal/preview wrapper keeps `word-popup`): `word-popup-loading`,
   `-error`, `-actions` (the first-row toolbar strip), `-header`, `-title`,
   `-familiarity`, `-frequency`, `-form-of`, `-models-button`,
   `-explain-toggle` (with `aria-pressed` while shown), `-ask-ai`,
   `-dictionary`, `-explain-row` (the mounted pane's first-row wrapper),
   `-section`,
   `-section-label`, `-translations`, `-translations-more`,
   `-translations-placeholder`, `-transcriptions`, `-transcriptions-more`,
   `-no-definitions`, `-definitions` (with `-definitions-list` on its `ol`),
   `-examples` (with `-examples-list` on its `ul`), `-etymologies`,
   `-summary` on every collapse summary, `-progress-known`,
   `-progress-reset`, `-anki-send` (the Anki icon button — add or update
   glyph) with its `-anki-added` / `-anki-updated` / `-anki-duplicate` /
   `-anki-error` status lines,
   `-missing` (the missing-word card's not-in-dictionary note),
   `-explain` (the Explanation pane) with
   `-explain-guidance`, `-explain-loading`, `-explain-error` (its retry
   button `-explain-retry`), `-explain-answer` (empty-answer note
   `-explain-empty`, "Ask again" `-explain-again`) — CSS hooks keeping the
   card's structure addressable from outside styles without coupling them
   to utility-class churn (no test pins them; the profile preview reuses
   the component itself).
   **Every block below the headword is individually hideable per user**
   (ADR 0046): the `popupVisibility` Inertia prop shared to every page
   (`App\Support\PopupVisibility::for()` — the saved `ui_settings.popup`
   opt-outs resolved over all-visible defaults, edited on the profile's
   Popups tab) drops the familiarity/frequency/form-of lines, the family's
   base-word sections (`word_family`), each satellite, the progress
   actions (the `progress_actions` opt-out hides the toolbar's two
   progress icons entirely), or the Explanation toggle together with the
   robot and the Models-used popup (`explanation`); the popup
   body lives in the exported
   `PopupContent`, which the profile tab reuses for a live preview.
   It always fits the viewport: it opens below the word, flips above it when
   there is more room above, its height is capped to the larger side, the
   body scrolls internally. **Its typography follows the host page's
   font-size setting** (ADR 0031): the page derives
   `popupFontSizeFor(pageFont)` — 65% of the page font, floored at 14px,
   capped at 32px — and the popup scales with it (width 560px at the default
   17px, still viewport-clamped; inner text sizes em-relative). When the
   surrounding `WordText` has a `rowKey`
   (see below), the click also fires a **lookup event**.
4. **Progress actions.** `PATCH /words/{word}/progress`
   (`familiarity: 0-100`) and `DELETE /words/{word}/progress` implement "I
   know this word" / "Remove mark" (deletes the row). The popup
   reports the change up to the page, which recolors the word in every
   rendered row, and shows the current score ("Familiarity: 12/100").

# Missing-word card (unmapped tokens)

Since popup-ai-enrich ticket 03 every tokenizer word token is interactive,
mapped or not. A token with no word-map entry opens the popup in **missing
mode** (`Components/WordPopup.jsx`'s exported `MissingPopupContent`, rendered
when `wordId` is null): the **raw clicked surface** as the title — the
folded `l_word` key would show ё-folded, mark-stripped text — and one honest
note, `word.not_in_dictionary` ("This word isn't in the dictionary yet.").
**Nothing runs**: no `GET /words/` (the detail-fetch effect returns before
its fetch when `wordId` is null), no `/word-events` lookup (there is no word
id to ledger), and no familiarity / frequency / form-of lines. The toolbar
shows only what applies — the Ask-AI placeholder, disabled until
**adopt-on-ask** (ticket 05) goes live; know / remove / Anki / the
Explanation toggle / the robot are inapplicable there, and ask-AI is the
only path from missing to populated (create the word first, then enrich,
then transition to the dictionary view). Each column's language
(`{code, name}|null` — the reader's display-column descriptors carry it as
`language`, the simulator's too) is plumbed `WordText` → `WordPopup` as the
new `language` prop for exactly that adoption. The structure mirrors the
real card (same toolbar / header / dictionary blocks, hairlines and stable
`word-popup-*` hooks, `-title` on the surface, `-missing` on the note). The
old unclickable phrasal-hit span branch merged into the interactive path: an
unmapped phrasal-verb hit keeps its dotted underline + headword tooltip and
now also opens the missing card on Ctrl+click. Mapped-token behavior is
unchanged end to end.

# Auto-fetched translations

When a popup would render **without a single translation** — no entry of the
word family carries any `word_translations` link — `GET /words/{word}` also
queues a background fetch into the user's native language. The response is
unchanged (silent background): fetched translations appear the next time the
popup opens.

* **Provider chain.** `FetchWordTranslations` walks the
  `WordTranslationResolver` chain — **Yandex Cloud Dictionary Lookup** first
  (dictionary-grade candidates with parts of speech), **Google Translate v2**
  as the fallback (a single machine-translation candidate). Both implement
  the shared `WordTranslationProvider` interface
  (`app/Classes/WordTranslations/`), so they are interchangeable; either or
  both can be configured (`YANDEX_TRANSLATE_API_KEY` +
  `YANDEX_FOLDER_ID` / `GOOGLE_TRANSLATE_API_KEY`; with neither set, nothing
  changes anywhere). A failing provider is skipped — but if no provider
  produced a translation and one could not answer, the job fails and retries
  rather than excluding the word.
* **What it writes.** Nothing on the target side — link-only (ADR 0078,
  2026-10-18): candidates are normalized first (trimmed; combining stress
  marks stripped for ru/be/uk targets at the JOB layer, not left to the
  providers' own strip; candidates with sentence punctuation, over 256
  chars, or empty after the strip dropped), then resolved against the
  target language's EXISTING `words` rows through the shared
  `App\Support\TranslationTargets` resolver (a multi-word candidate prefers
  the target's `phrase` class, a single-word one the candidate pos class
  else the source word's class — preference only, an `l_word` match under
  any class links). Matches become `word_translations` pairs
  (`WordTranslation::link`, idempotent); unmatched candidates stage into
  the source word's `translations` JSON through the shared
  `App\Support\StagedTranslations` merge for the later link pass. The
  ledger row records the outcome: `linked_count`/`staged_count`.
* **Ledger and exclusions.** One `word_translation_fetches` row per
  (word, target language) is the dedupe ledger: `status='empty'` — every
  provider answered "no translation" (or nothing usable survived the
  filters) — is the permanent **exclusions list** (the pair is never
  looked up again), `succeeded` never re-fetches either (staged candidates
  wait for the link pass, not a re-query), and `failed` retries up to 6
  attempts behind a 24h cooldown
  (`WordTranslationFetchService::dispatchIfEligible`).
* **Entity-word adoption.** `EntityWordAdoption` — run by the 5-minute
  word-list refresh after its link pass (`RefreshEntityWords`), or by
  `words:adopt-from-entities {--entity=*} {--language=} {--to=}` — attaches
  linker-stamped unmatchable tokens to the existing same-`l_word` word row
  of the language when one exists (first non-unknown-class row in id order,
  else the lowest id — never an `unknown`-class twin), creates a `words` row
  only for a genuinely new token (class `unknown`; a plain display word —
  the combining stress marks leave the raw surface for ru/be/uk — that
  carries itself as its lemma form), points `entity_words.word_id` at it,
  and queues the same fetch for every entity word still lacking
  translations. The indexer stamps `entity_words.l_word` through the same
  `WordLookupKey` pipeline the dictionary rows carry (ё-fold included), so
  a ёлка token keys `елка` and hits the е-keyed row in the ordinary link
  pass — the adoption mint's `firstOrCreate` on the unique triple stays as
  race protection for stamps that predate the fold. Every entity token ends
  up clickable and eventually
  translated; junk tokens land in the exclusions after one lookup. See
  [Crossword](/domains/crossword.md) and [Dictionary](/database/dictionary.md).
* Tests: `tests/Feature/WordTranslationFetchTest.php` (trigger, link-only
  job — the no-creation invariant, defensive stress strip, staging,
  counts — fallback, exclusion, cooldown),
  `tests/Feature/AdoptEntityWordsTest.php`,
  `tests/Unit/WordTranslationProvidersTest.php` (parsing, caps, chain order).

# Context explanation (on-demand toolbar toggle)

When the surrounding `WordText` receives a `rowKey` and an `explain`
payload (`{enabled, modelKey, modelLabel, followsAnswer, answerLabel}` —
eligibility per column, carried enabled or not), the toolbar grows the
**Explanation toggle** (chat-bubble icon, first right-hand slot after the
Anki send): nothing loads until its first click — then the **Context
explanation** (an AI answer to "what does this word mean in this
sentence?") mounts as the FIRST row of the dictionary view, above all POS
sections, behind the same hairline, and requests immediately; a second
click hides the pane (the cache below survives, so re-showing never
refetches), and the icon carries its pressed state (`aria-pressed` +
verdigris tint) while shown. No tab strip exists — the dictionary content
always renders (popup-ai-enrich ticket 01). Eligibility follows the
same rule everywhere: a column is explainable when its language ≠ the user's
native language (the sibling `highlightable` / `explainable` maps both
surfaces ship — ADR 0060 amendment), so the
Reader has the toggle too — the simulator on both surfaces since the model
no longer needs page state (ADR 0035). Rightmost on the toolbar sits a
**robot-with-question-mark icon** (its click never touches the toggle)
opening the **Models used popup**
(`Components/ModelsUsedPopup.jsx`): the models currently serving the
surface, each label a link to `/profile?tab=ai` — on the simulator both the
AI questions (answer) model and the explanation model (with a muted
"follows answer model" hint while no separate explanation pick exists,
`AIModelResolver::explanationModelFollowsAnswer()`), on the reader just the
explanation model. Toggle and robot exist only with an explain payload and
answer to the ADR 0046 `explanation` key (`explanation: false` removes
both, exactly as it once dropped the whole tab strip). While the modal is
open it owns Escape and
outside-click closing — the word popup underneath stays put.

* **The request fires on toggle-on.** The first click that shows the pane
  POSTs `/ai/word-explain` immediately (sync JSON, no streaming) — pane
  mount is the fire: a mount-only effect fires once per activation, never
  on re-renders. A hit in the client memo renders the memoized state
  instantly and skips the request — of any kind: a pending entry keeps the
  loading line (no duplicate POST on re-show mid-flight), an errored
  one keeps the failure and its "Retry" button (no auto-refire without a
  click) — and the manual "Ask again" refetches. Keyless users (`enabled`
  false — the toggle still
  renders for them) see add-an-API-key guidance with a link to
  `/profile?tab=ai` and no request; when the user has no resolved
  explanation model, the pane shows choose-a-model guidance instead. The
  profile preview keeps its canned `word.explain_preview` answer with zero
  network.
* **Sentence identity travels as an id.** Every Reading row sentence object
  carries its entity sentence `id` (ADR 0060), `WordText` remembers which
  sentence a Ctrl+click landed in, and the payload is simply
  `{entity_sentence_id, word_id, surface}` — the retired positional
  `meaning_match_id + side + sentence_index` address (and the server-side
  sentence-list rebuild that mirrored the presenter) is gone. No `model`
  field — the server resolves the user's stored explanation model
  (ADR 0035).
* **Context assembly.** The endpoint takes the sentence before and the
  sentence after the clicked one by document order in the same entity
  (`entity_sentences.order` — neighbours may live in adjacent rows or off
  the current page), marks the surface form inside the clicked sentence
  with `**…**`, and prompts the model to name the sense that applies and
  give the closest native-language equivalent in 2–4 sentences. The reply
  language is the user's **Native language** setting, resolved server-side.
* **Model and access.** The model is the user's resolved **explanation
  model** (`AIModelResolver::resolveExplanationModel()` — unset follows the
  answer model; a null resolution answers with choose-a-model guidance);
  access is `EntityAccessService::canRead` on the clicked sentence's
  entity. Errors reuse the
  envelope `{data: {data: {error}, code}}`; the endpoint is throttled
  20/min like the other AI routes.
* **Client memo.** Pane states are cached per popup instance in a
  page-lifetime `Map` keyed by `sentenceId|surface|modelKey` — no
  server-side cache, so re-opening the same word in the same sentence is
  free within the page, switching the explanation model misses the cache,
  and "Ask again" refetches (overwriting the memo). Every state is
  memoized, not just answers: `loading` and `{status: 'error', message}`
  entries suppress the auto-fire the same way a `done` answer does, so
  re-showing the pane mid-request never double-POSTs and an error never
  silently refires — the pane owns its Retry. Cache writes notify
  subscribers, so a pane remounted while its request is still in flight
  adopts the outcome. Hiding the pane discards nothing — re-toggling the
  same word renders the memoized state, and a fresh word (a new `data`
  payload) flips the toggle back off while the cache entry waits.
* Tests: `tests/Feature/AiWordExplainEndpointTest.php` (context assembly,
  multi-sentence rows, entity-sentence rows, access, validation,
  provider-error mapping, throttle; `AIModelResolver` mocked at the
  container — provider calls are raw cURL and invisible to `Http::fake`).

# Ask-AI enrichment (one ledger-locked ask per word)

The toolbar's **Ask-AI icon** (sparkles, after the Explanation toggle) is
the sparse word's on-demand fill (popup-ai-enrich ticket 04, ADR 0082): a
Ctrl+click asks the user's **explanation model** (the same BYO-key seam as
the Context explanation — `AIModelResolver::resolveExplanationModel` +
`ask`, no client-sent model) to enrich the dictionary entry, and the reply
merges **straight into the dictionary tables** — no AI block, no popup
marker.

* **The contract.** `POST /ai/word-enrich` takes `{word_id}` (the popup's
  bound word; the missing-word adoption of ticket 05 extends the
  contract), auth+approved, CSRF, throttled 20/min like the other AI
  routes. The response is the **word-show payload itself** — both
  endpoints assemble it through the one `WordPopupPayload` presenter, so
  the popup swaps it in with a plain `setData` and the shapes can never
  drift — with `ai_asked: true` riding along once the ledger locked. The
  word is touched on success, so the stored completeness goes stale and
  the swap-in payload (built through `WordFamily::resolve`) recalculates
  and re-orders by it in the same request.
* **The ledger lock.** One `word_ai_fetches` row per word: provider,
  model, status pending/succeeded/failed, attempts, error, `asked_at`,
  `succeeded_at`. A **succeeded row is the forever lock** — the endpoint
  rejects further asks (409 "AI enrichment has already been completed for
  this word.") and the payload's `ai_asked` greys the icon ("AI already
  asked" tooltip, the click a no-op). **Failed attempts stay retryable**
  at any count (each retry bumps `attempts`; the row materializes only
  when an ask is actually attempted — a no-model request records
  nothing): no chosen model → 400 "Choose an AI model in your profile
  settings."; no usable key → the resolver's 403; provider errors map
  through with their own status; an unparseable reply (502 "The AI reply
  could not be parsed as strict JSON.") and a fully-duplicate one (502
  "The AI reply contained no new dictionary content.") finalize failed
  too. Errors land verbatim beneath the toolbar
  (`word-popup-ask-ai-error`); the ui-strings are `word.ask_ai` /
  `word.ask_ai_already` / `word.ask_ai_sending` / `word.ask_ai_failed`
  (en/ru/be).
* **The prompt.** `PromptTemplates::enrichment` — admin-editable
  `prompt_templates` key `word.enrichment` with a code fallback — demands
  **strict JSON**: translations / definitions / transcriptions / examples
  / etymologies as string arrays, translations and definitions in the
  user's native language, examples in the word's own language.
* **Lenient parsing.** `App\Support\EnrichmentReply` strips code fences
  and prose around the outermost `{...}`, coerces (a bare string becomes
  a one-element list, numbers stringify, booleans/nulls/nested
  arrays/empties drop, duplicates collapse), caps the counts (12
  translations / 8 definitions / 4 each of the rest) and truncates to the
  column limits (definitions 500, transcriptions 100, examples 500,
  etymologies 1000) — except translations, lookup candidates that must
  stay whole words, which are dropped past 256 chars.
* **The merge.** In one transaction (`WordAiEnrichmentService`): each
  section merges into its table exact-text deduped — **new rows carry
  `source = 'ai'`**, null stays imported, and the popup renders no visual
  marker (provenance is DB-only, ADR 0082). AI transcriptions reuse the
  word language's seeded transcription types (IPA first); a language with
  none simply gets no AI transcriptions. **Translations are link-only**
  (ADR 0078 holds for AI): candidates normalize (trim, stress marks
  stripped for the target language's rule, over-long and
  sentence-punctuated dropped), resolve against the native language's
  EXISTING words through the shared `TranslationTargets` resolver, link
  as `word_translations` pairs, and the unmatched stage into the word's
  `translations` JSON — **zero word rows are ever created**.
* **Client wiring.** The ask callback (the outer `WordPopup`) posts the
  bound `data.id` with the CSRF header, guards both completion paths with
  the mirrored data ref (a late response for a moved popup is dropped),
  resets the in-flight/error state on word change, and disables the icon
  while in flight. The profile's Popups-tab preview keeps the icon inert
  (no handler reaches it).
* Tests: `tests/Feature/AiWordEnrichEndpointTest.php` (end-to-end
  enrichment with the resolver mocked at the container — content rows +
  provenance, link + stage + zero word rows, ledger lock/retry/403/400,
  dedup, caps/truncation, template + fallback, validation, throttle),
  `tests/Unit/EnrichmentReplyTest.php` (parser: fences, coercion, caps,
  truncation, null), `tests/Feature/WordEndpointTest.php` (`ai_asked`
  true only for a succeeded row), and the vitest source pins in
  `resources/js/Components/WordPopup.toolbar.test.js`.

# Anki send from the popup toolbar (per-word send)

The popup toolbar's **Anki icon** (`word-popup-anki-send`, right end of
the first-row `word-popup-actions` strip) sends the clicked word to the
user's desktop Anki deck — everywhere the popup renders (reader,
simulator), and **independent of the popup-visibility toggles**
(ADR 0046): the toolbar's icons always render and disable (never hide)
when inapplicable, and the `progress_actions` opt-out hides only the two
progress icons — the Anki button references no visibility setting.
Everything browser-side
(ADR 0077 — the Anki-export flow's front door is
[Anki Export](/domains/anki-export.md)) —
the settings come from the shared `ankiSettings` Inertia prop, the word's
own target is resolved by `resources/js/lib/ankiWordConfig.js`'s
`ankiWordSyncConfig(settings, code)` (deck + fields from
`languages[code] ?? languages.default`, global url/port, the note type
following the RESOLVED entry — a code that fell through to `default`
keeps the unsuffixed base type, so a send never mints a "⋅ <code>" type
for a language the user never enabled), the send is
orchestrated by `resources/js/lib/ankiSync.js` and the note is built by
`resources/js/lib/ankiTemplate.js`'s `buildNoteFields()` from the payload
the popup already loaded (identical values to the Anki tab's sample card;
see [Profile](/domains/profile.md) for the Anki tab side):

* **Detect on open — the icon reflects the deck.** Opening a popup fires
  a silent duplicate check (the send path's own deck + Word + Language
  search) through the sync layer's memoized `findExistingNote`: it never
  runs the ensure (creates no decks or note types — Anki may not even be
  running), is memoized per page session (re-opening the same word does
  not re-query), and resolves null on any failure (Anki off, CORS,
  Anki-side error), which leaves the ordinary add form. When the word is
  found, the icon switches to its update glyph with the
  `word.anki_update` ("Update in Anki") tooltip/aria; otherwise it shows
  the add glyph + `word.anki_send`. Stale responses can never land: the
  detection carries its own `cancelled` guard and both completion paths
  pass through a `dataRef` mirror of the loaded word, so a late result
  for a popup that has moved on is dropped; a send result seeds the
  memo (and sets the state directly), keeping the icon correct without
  a re-query.
* **Note values.** All thirteen fields always ride along. The twelve flat
  fields keep the hide-class template semantics (a toggled-off scalar is
  hidden by the template, never omitted); the 13th **Body** field carries
  the popup's dictionary rendered per entry, and the body section toggles
  plus the `body` master are **send-time** — the send passes the word's
  resolved per-language fields (via `ankiWordSyncConfig`) and the value is
  shaped accordingly, so those toggles affect newly sent cards only
  (ADR 0077 addendum v2). The flat Translations field narrows to the user's
  **native language** (the
  code shared as `auth.user.native_language` — the same filter the Anki
  tab's preview uses), falling back to all translations when none match;
  `Tags` carries the linked word's tag names (`data.tags`, additive in the
  `GET /words/{word}` payload); `Familiarity` is the popup's current score.
  The values carry the popup's dictionary structure (per-entry labels,
  counted `<details>` summaries, the translations "+N more…" expander), and
  the label wording is the popup's own UI-language strings — passed to the
  builder from `sendToAnki` through the shared `uiLabels(t)` builder and
  frozen into the note at send time, so a card sent from an ru/be UI keeps
  that wording in Anki.
* **Auto-ensure.** The first send of a page session runs the memoized
  ensure (deck create-if-missing, the word's resolved note type — the
  per-language "ExtApp Vocabulary ⋅ <code>" or the unsuffixed base —
  plus the generated template; memoized per endpoint+deck+note type) —
  module state shared with the Anki tab, so a tab sync and a
  popup send in one session don't redo each other's work.
* **Duplicates become updates.** The send still runs find-before-add
  scoped to deck + Word + Language, so a second card can never be
  created: a hit on one of the app's own note types (the word's
  per-language type, the unsuffixed legacy base, or any name carrying
  the app prefix — a language disabled since the note was sent resolves
  its word to the `default` entry while its note keeps the
  "⋅ <code>" name) is **updated in place** — all thirteen
  values rebuilt, the tag set replaced, note id / deck / review
  scheduling untouched (ADR 0077 addendum v3) — and the status line
  reports "Updated in Anki." (`word.anki_updated`,
  `word-popup-anki-updated`). Only a foreign-model note matching the
  word still skips with "Already in Anki." (`word.anki_already`,
  kept for exactly that case) and is never modified.
* **Failure is never silent.** An unreachable AnkiConnect (fetch rejection,
  HTTP/non-JSON answers) shows the short "Can't reach Anki" hint with a
  link to Profile → Anki; an Anki-side error string is shown verbatim
  (`Anki error: …`); `AnkiNoteTypeMismatchError` aborts with the teaching
  copy — which model mismatched (the error carries the model name it
  inspected, interpolated into the popup's copy since the review fixes),
  expected vs found fields, plus the one-time fix: in Anki,
  delete the named note type (its notes are deleted with it), then
  sync again from the Anki tab and re-send the words; a pre-v2
  twelve-field install hits this by design, since AnkiConnect cannot add
  a field to an existing note type. The status lines live directly
  beneath the toolbar (`word-popup-anki-*` classes), and the icon's
  tooltip/aria swaps to "Saving…" (`word.anki_sending`) while the send is
  in flight, the button disabled.
* The profile's Popups-tab preview renders the toolbar's icons disabled
  (no handlers in preview mode), so the preview cannot drift from the
  real popup's first row.

# Familiarity events (ADR 0028)

* `POST /word-events` (`RecordWordEventsRequest`) takes up to 200 events
  `{row_key, kind: read|lookup, word_ids}`. The
  `WordFamiliarityService` inserts each fresh (user, word, row_key, kind)
  into the `user_word_event` ledger (unique — repeat requests are no-ops),
  applies +1 per fresh read / −2 per fresh lookup clamped to 0–100, and
  responds `{data: {familiarity: {wordId: value}}}` covering every
  referenced word so the client can recolor without a refetch.
* `row_key` scopes one sentence pair: `mm:{meaningMatchId}` (aligned rows)
  or `es:{entitySentenceId}` (unaligned reader rows). Reading rows carry
  their `key` on the row object itself (ADR 0060), so no parallel key list
  exists, and the request validates the referenced rows exist.
* **Reads** fire only on the bilinguals simulator (checking a row's EN
  checkbox, or the `all_en` header checkbox which batches the whole loaded
  page into one request), crediting the learning-language side's words.
  **Lookups** fire on both surfaces, on the first popup open of a word
  within a row. The browser fires events best-effort
  (`lib/wordFamiliarity.js`) — failures never block reading.
* Schema: `user_word.familiarity` (unsigned tinyint, default 0) +
  `user_word_event` ledger; see
  [schema-overview](/database/schema-overview.md).

# Highlighting rules

* Tinting applies only to sides whose entity language ≠ the user's native
  language (the sibling `highlightable` map both surfaces ship — ADR 0060
  amendment), so a native speaker's side stays clean.
* Each surface has a persisted toggle: `reader.highlight` and
  `simulator.highlight_words` in `user_settings.ui_settings`
  (`UpdateUiSettingsRequest`), default on. Tint classes (`.word-unknown`,
  `.word-progress`, `.word-progress-strong`) color the **text** (not the
  background) via `--word-*` tokens with day/night variants, all in
  `resources/css/app.css`. The hover affordance is an **underline** (no color
  change), so the tint stays legible; tier colors win on hover over tinted
  words (ADR 0030). Unmapped tokens never tint (plain `.word-token` —
  hover-underline and pointer only, no tier class), even with the highlight
  toggle on (popup-ai-enrich ticket 03).
* **Word-map state is pure `{a, b}`.** Eligibility rides its own prop/state,
  never inside `wordMaps`, so familiarity patches (`setWordMaps` spreads +
  `patchWordMap`) cannot clobber it — the pre-amendment simulator kept
  `highlightable` nested inside the maps and needed spread care to
  preserve it.

# Routes

| Route | Handler | Purpose |
|-------|---------|---------|
| `GET /words/{word}` | `WordController::show` | Word popup payload (assembled by the shared `WordPopupPayload`): the word family (own headword group + base-word groups scoped to claiming classes, ADR 0045 + 0047) stable-sorted completeness-descending (stored `words.completeness`, lazily recalculated when stale during the resolve) as `entries` (word, class, transcriptions, definitions, native-first translations cap 100, examples, etymologies), `form_of`, `is_form`, `frequency` (integer rank, null when unranked), `tags` (the linked word's tag names — for the Anki card; eager-loaded with the family via `WordFamily::EAGER_LOAD`, no per-open lazy query), `ai_asked` (a succeeded ask-AI ledger row exists — the toolbar's grey-icon flag) |
| `POST /ai/word-enrich` | `ReadingAiController::enrichWord` | Ask-AI enrichment for a known word ({word_id}, `AiWordEnrichRequest`, throttle 20/min): explanation-model strict-JSON ask through `AIModelResolver::ask`, lenient parse (`EnrichmentReply`), transactional merge with per-row `source='ai'` provenance and link-only translations (`WordAiEnrichmentService`), `word_ai_fetches` ledger locking on success; responds the word-show payload so the popup swaps it live (ADR 0082) |
| `POST /ai/word-explain` | `ReadingAiController::explainWord` | Context explanation: prev/current/next sentence of the clicked side's entity + focused prompt through `AIModelResolver::ask`, native-language reply (`AiWordExplainRequest`, throttle 20/min) |
| `PATCH /words/{word}/progress` | `WordController::setFamiliarity` | `UpdateWordProgressRequest` (`familiarity` 0–100); upsert `user_word` |
| `DELETE /words/{word}/progress` | `WordController::resetProgress` | Delete the `user_word` row (back to untouched) |
| `POST /word-events` | `WordController::recordEvents` | Ledger-deduplicated read/lookup events; returns resulting familiarity per word |

# Consuming surfaces

* **Reader** (`/reader/{entityId}`): props `rows` (Reading rows carrying
  their `key` and sentence ids), `wordMaps` `{a, b}` (page-scoped),
  `highlight`, `highlightable`/`explainable` (canonical-side maps), plus
  `explain` (`{enabled, modelKey, modelLabel, followsAnswer}` — the label
  fields feed the word popup's Models used popup, which on the reader lists
  the explanation model only); `ReaderRow` takes two display-column
  descriptors and renders both row halves through
  `WordText` (the primary line is a `role="button"` div so the word tokens —
  themselves `role="button"` spans — stay valid HTML inside it) and derives
  the popup font from its own `fontSize`. Lookup events only — no read
  crediting. Rows key `mm:{id}` / `es:{id}`; every sentence explains through
  its entity-sentence id.
* **Bilinguals simulator** (`POST /text`): response gains the reader's
  sibling shape — `word_maps` (`{a, b}`, page-scoped like the reader) plus
  `highlightable`/`explainable` as their own keys (ADR 0060 amendment);
  `TextContent` renders both cells
  through `WordText` over the canonical Reading rows
  (`firstSide`/`secondSide` pick the columns, and `explain = {enabled:
  canUseAi, modelKey, modelLabel, followsAnswer, answerLabel}` gated per
  side by `explainable`), fires read
  events from the row/column checkboxes, and enables the popup's Context
  explanation toggle.
