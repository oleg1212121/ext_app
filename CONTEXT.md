# Sentence Alignment Context

The domain of pairing the sentences of two same-work entities (two versions of
one text — usually two languages, sometimes two same-language companions such
as exercises and their answer key) into meaning-equivalent groups (meaning
matches), produced by the alignment pipeline and refined by humans in the
Alignments editor.

## Language

**Work**:
The abstract book that entities translate: one row grouping every language version of the same text. Carries the title, author, and the **original language** the book was written in. An Entity always belongs to exactly one Work; a Work may hold several entities in the same language (competing translations, editions), told apart by their label.
_Avoid_: book (a legacy crossword-domain term), parent entity (Entity already means the per-language text), title

**Entity**:
A text in exactly one Language — the original or a translation of its **Work** — carrying a name, label, description, an uploaded text file, a signature, and an ordered list of sentences. An Entity does not span languages; a cross-language pairing is an Entity match, not a single Entity.
_Avoid_: text, document, article

**A-side / B-side**:
The two positions inside an entity match, stored canonically (the lower entity id is the A-side). The Python aligner's lists, the junction's `side` column, and the editor payloads all speak in sides; displays label them with each side's language. Sides are positional, not semantic — the original text can sit on either side.
_Avoid_: EN side / RU side (language-specific wording), left/right

**Entity match**:
The container pairing two distinct entities of the same **Work** ("the same text, two versions"), held by its **A-side** and **B-side**. The two entities are usually in different languages, but a same-language pairing (exercises + answers) is equally valid. See ADR 0019.
_Avoid_: match, alignment

**Original text**:
The language the book was authored in — a property of the **Work**, not of a
pairing. For an entity match, the original side is whichever side's entity is in
that language, or neither when both are translations of a third language. Not
storable per match; derived whenever needed.
_Avoid_: source text, prior text

**Original completeness**:
The invariant, enforced when an alignment run completes, that every sentence of the
original-side entity is junctioned into a meaning match (in original order). When
neither side is the original language (translation-pair), the invariant holds for
BOTH sides — cover both sides.
_Avoid_: no-unmatched guarantee

**Meaning match**:
A row inside an entity match: a group of EN and/or RU sentences that the aligner
or a human judged to share meaning. Carries its own `order` (row sequence) and
`similarity` (aligner confidence).
_Avoid_: pair, semantic pair, unit

**Single-sided meaning match**:
A meaning match with junctions on exactly one side — the junctioned sentence(s)
display with the other column empty. How the pipeline keeps an unmatched original
sentence visible. _Avoid_: skip row (implementation term), empty match

**Needs review**:
A meaning match a human should inspect because it is low-confidence (similarity
below the pipeline's acceptance floor) or one-sided (incomplete). Surfaced in
the Alignments editor as a review list.
_Avoid_: low-similarity match (score-only wording, misses one-sided rows)

**Sentence**:
A split sentence of an entity. Its entity-global `order` is the **document order** —
the order of the sentence in the original text. The alignment pipeline and the
reader rely on it. The alignment editor renumbers it when a sentence is dragged
(see Move sentence); the entities frontend's insert/reorder operations are the
other mutation paths.
_Avoid_: line

**Junction**:
A sentence's membership link to a meaning match. Junctions are pure association
tables with no `order` column. Within-row display order is determined by each
sentence's document order (`*_entity_sentences.order`). Dragging a sentence —
within a row or across rows — renumbers document order on the sentence table so
the sentence sorts exactly where it was dropped.

**Unmatched sentence**:
A sentence with no junction to any meaning match.
_Avoid_: unpaired, unlinked

**Unlink**:
Remove a sentence's junction; the sentence becomes unmatched.

**Empty meaning match**:
A meaning match with zero junctions on both sides. A persistent, legitimate state.

**Similarity**:
Per-meaning-match aligner confidence (0–1). A human-confirmed grouping is trusted
at 1.0 (structural changes reset it).
_Avoid_: score

**Entity similarity**:
`entity_similarity` on the entity match — the whole-pair embedding similarity,
distinct from per-row similarity.

linked_count**:
The number of meaning matches in an entity match (empty ones included).

**Resume**:
Advance an alignment that has stopped before reaching the end of the text.
Triggered manually (Re-run) or automatically (the `alignments:resume` command).
The cursor — the per-side sentence offsets where the next chunk starts — is the
only state a resume reads, so a stopped run can continue without wiping
already-aligned chunks. _Avoid_: restart, retry.

**Add sentence**:
Create a new sentence in an entity and link it to a meaning match.
_Avoid_: insert sentence (the entities-frontend operation below — no junction).

**Insert sentence**:
Create a new sentence in an entity from the entities frontend (`/entities`),
placing it at a chosen document-order position. No junction to a meaning match
is created — the sentence starts unmatched. Distinct from Add sentence
(alignment editor), which always junctions. _Avoid_: add sentence (overloaded).

**Move sentence**:
Drag a sentence to a new position in the alignment editor — within a row,
across rows, or to/from the unmatched pool. The drop position wins: the
sentence's document order is renumbered (sparse, clamped by the nearest
sentences outside the destination row's span) so it sorts exactly where it was
dropped and the global numbering stays monotonic with row order. A drop into a
row empty on that side lands between the closest populated rows.
_Avoid_: relink (misses the renumbering)

**Create meaning match / delete meaning match**:
The row lifecycle. Delete returns the row's sentences to unmatched.

# Bilinguals Simulator Context

The domain of the bilinguals simulator's AI-assisted assessment surface — the
"Reader's gloss" rail where the reader answers a learner's translation.

## Language

**Gloss**:
The reader's AI answer text rendered in the Reader's gloss panel of the
bilinguals simulator. Not to be confused with a dictionary gloss.
_Avoid_: AI answer (transport/implementation term), response

**Gloss run**:
A hoverable text-level unit inside a gloss — any text-bearing element the
markdown-to-HTML conversion emits (paragraph, list item, heading, `em`/`strong`
run, code, etc.). Signals interactivity with a pointer cursor and an
accent-tinted background on hover. _Avoid_: html element (implementation term)

# Language Catalog Context

The domain of the admin-managed registry of languages available in the application, surfaced through the Filament `/admin` panel.

## Language

**Language**:
An admin-managed entry in the languages registry, identified by its ISO 639-1 code. Distinct from a program's translation locale.
_Avoid_: locale, tongue

**Language code**:
The ISO 639-1 two-letter code that uniquely identifies a Language (e.g. "en", "ru").
_Avoid_: locale code, language tag

**Enabled language**:
A Language whose `is_enabled` flag is true. The flag is a stored value only; the application does not automatically enforce it in existing code paths.
_Avoid_: active language, available language

**User settings**:
The per-user configuration row (one per user) holding the user's durable choices — the **Native language**, the **Interface language**, and **UI settings**. Stored in `user_settings`. _Avoid_: preferences, profile (the page, not the row).

**UI settings**:
The stable, user-chosen interface configuration inside User settings — simulator layout and panel visibility, font sizes, the selected AI model, the customized assessment question, and panel sizes. A sub-kind of User settings; changes follow the user across devices. See ADR 0024. _Avoid_: simulator cache, UI state (that includes Working state, which is not stored server-side).

**Working state**:
The per-device last position in the simulator — the current entity match, the page reached per alignment, and the last opened row with its revealed halves. Kept in the browser only, never stored server-side. See ADR 0024. _Avoid_: UI settings (durable, cross-device), session.

**Native language**:
The language a user is a native speaker of, chosen at registration and changeable from the profile page. References a **Language** in the catalog; defaults to English.
_Avoid_: mother tongue, first language

**Interface-enabled language**:
A Language flagged `is_interface_enabled` — usable as the language the web UI renders in. Only interface-enabled languages appear in interface-language pickers.
_Avoid_: supported language, active language

# Localization Context

The domain of the web UI's display language and the admin-curated interface text shown in it. Distinct from the Language Catalog (learning-content languages) and the Dictionary (word translations).

## Language

**Interface language**:
The language the web UI renders in for a user; a User settings field that, when null, follows the **Native language**. Resolution falls back to English.
_Avoid_: UI language, locale

**UI string**:
One piece of interface text, identified by a **UI string key**, with at most one value per interface-enabled language. Edited in the admin panel; missing values fall back to English. _Avoid_: translation, label

**UI string key**:
The dotted identifier of a **UI string** (e.g. `nav.library`); its first segment is its **string group**.
_Avoid_: translation key

**String group**:
The first segment of a UI string key, naming the surface the string belongs to (e.g. `nav`, `profile`, `reader`).
_Avoid_: namespace, category

# Dictionary Context

The domain of the Wiktionary-sourced dictionary — words per language with
their linguistic satellites, populated by the import pipeline and curated in
the admin panel.

## Language

**Word**:
A base-form word in exactly one Language, carrying its part of speech
(**Word class**), definitions, forms, etymology, transcriptions,
pronunciation audio, examples, and staged translations awaiting linking.
_Avoid_: entry, lemma (implementation shorthand), vocabulary item (legacy
crossword-domain term).

**Word class**:
The part-of-speech taxonomy entry a Word belongs to — per language, unique
by `(language, slug)`. Seeded with curated titles for en/ru; the import
auto-creates any unseen class with the slug as a placeholder title. A word
whose part of speech cannot be determined gets the `unknown` class.
_Avoid_: POS (dump-field jargon), category, speech part.

**Transcription type**:
The kind of phonetic notation a transcription is written in (e.g. IPA,
enpr) — per language, unique by `(language, slug)`. Auto-created by the
import with the slug as a placeholder title, curated afterwards.
_Avoid_: notation, phoneme set.

**Translation linking**:
The step that resolves each Word's **Staged translations** into
**Translation links** (one row per pair) between Words of different
languages — run after import for every language pair, and continued by
hand in the admin panel.
_Avoid_: translation sync, matching.

**Translation link**:
An association between two **Words** in different Languages that translate
each other, stored as one row per pair in `word_translations` and usable
from either Word. A Word may carry many links; links connect different
Languages only.
_Avoid_: translation (also means a **Staged translation** or the general
notion), relation, mapping, directed translation.

**Staged translation**:
A raw target-language word string the import recorded on a Word, awaiting
**Translation linking** into **Translation links**. Working data of the
pipeline, never shown to end users.
_Avoid_: raw translation, pending translation, translation (overloaded).

# Access Control Context

The domain of who may do what in the application — driven by a user's **Role**
and **Approved** state, with an **Admin bypass** that lets approved admins pass
every ability.

## Language

**Role**:
A user's access tier, stored as the `role` string on `users` (`'user'` | `'admin'`). _Avoid_: permission, grant, group.

**Approved**:
The `is_approved` boolean on `users`; the prerequisite state for holding any ability. An unapproved user cannot pass any authorization check. _Avoid_: active, verified (conflicts with email-verified).

**Admin bypass**:
The behavior by which an approved admin automatically passes every ability (via a `Gate::before` hook), so ability definitions only encode the non-admin rule. _Avoid_: superuser, god mode.

# AI Provider Context

The domain of the AI model catalog — the database-backed registry of models
available through AI providers, synced from provider APIs and managed via admin.

## Language

**User key**:
A per-user API key a user stores (encrypted) for one provider, used for every
user-facing AI request. One key per provider per user. _Avoid_: personal key.

**System key**:
The admin's `.env` key for a provider, used only for non-user paths (model
sync, CLI, admin tooling). _Avoid_: env key, admin key.

**Available (to a user)**:
An AI provider the current user can use on the simulator: `is_enabled` and the
user has a **User key**. _Avoid_: configured (old env-key meaning), active.

**AI model catalog**:
The database table holding AI models available through providers, synced from
provider APIs. _Avoid_: model list, model registry

**Model sync**:
The operation of fetching available models from a provider's API and updating
the AI model catalog. _Avoid_: model refresh, model fetch

**Enabled model**:
A catalog entry marked as visible in the simulator's model picker.
_Avoid_: active model, visible model

**Models endpoint**:
The URL a provider exposes to list its available models, distinct from the
chat endpoint. Each provider's config carries it as `services.<provider>.models_url`;
blank until that provider's **Model sync** is wired up.
_Avoid_: model URL, list URL, models URL

**Chat endpoint**:
The URL a provider exposes for chat-completion requests; stored on the
provider class as `aiApiLink` (e.g. `services.<provider>.url`). Distinct from
the **models endpoint**.
_Avoid_: API URL, completion URL

# Entity Access Context

The domain of who may read an Entity or an Entity match, layered on top of the
Access Control Context. Every upload defaults to a Restricted state; an admin
publishes it to make it Public; a user who uploads text matching an existing
Entity is granted access to that Entity instead of creating a duplicate.

## Language

**Restricted entity**:
An Entity readable only by admin and explicitly granted users. The default
state for every newly uploaded Entity. _Avoid_: copyrighted (legally imprecise
— every original text is copyrighted by default), paid, premium, licensed.

**Public entity**:
An Entity any approved user may read. Set by an admin publishing a Restricted
entity (`is_restricted = false`). _Avoid_: free, open, public-domain (a legal
term with a specific meaning).

**Access grant**:
A recorded stake for a specific user in a specific Restricted entity, stored
in the `entity_user` pivot (with a nullable
`similarity`). Carries both read and edit permission on the entity (and its
sentences) until the entity is published. _Avoid_: link (too generic),
license (legal), permission (overlaps Role).

**Edit rule**:
Who may edit an Entity (name, description) and its sentences in the entities
frontend. A Restricted entity is editable by admin and grantees; a Public
entity is editable by any approved user. The rule mirrors read —
`EntityAccessService::canEdit` is structurally identical to `canRead`.
Sentence mutations (insert / update / delete / reorder) flip every
entity match involving the entity to `status = 'pending'`. Deleting a
junctioned sentence cascades (junctions removed, emptied meaning matches
deleted, `linked_count` updated) — a deliberate divergence from the alignment
editor's unlink-before-delete rule. See ADR 0015.

**Creator grant**:
An Access grant with a null `similarity`, recording that the user's upload
created the Entity (no prior Signature match existed). Distinct from a
Signature match grant, whose `similarity` is the cosine score.

**Signature match**:
The event of an uploaded text's Signature cosine-matching an existing Entity
at ≥0.95. Instead of creating a duplicate, the uploader receives an Access
grant on the existing Entity. _Avoid_: dedup (that is a side effect, not the
user-visible concept).

**Publish**:
An admin action flipping a Restricted entity to Public. Existing Access
grants remain as audit but are no longer enforced. _Avoid_: release, unlock.

**Readable count**:
Any count of entities or entity matches shown to a user counts only what that
user could actually open (Public entities plus their own grants; for matches,
both sides readable). A global total leaks Restricted entities' existence.
_Avoid_: total count, library size.

# Library Context

The domain of the user-facing browse surface for works and their texts — the
`/library` section that replaced the language-first entities pages.

## Language

**Library**:
The user-facing section (nav item, `/library`) where an approved user browses
the **Work catalog** and, inside a work, the entities they can read.
_Avoid_: entities page (the former language-first surface), Parallel Library
(the Reader's former on-page subtitle).

**Work catalog**:
The complete set of **Works**, visible to every approved user regardless of
entity access — including works with no entities yet. A Work carries no access
semantics of its own; access control and counts live on its entities (see
Readable count in the Entity Access Context). See ADR 0021.
_Avoid_: available works (Available is the AI-provider term), my library,
book collection.

# Crossword Context

The domain of crossword puzzles generated from a text's own vocabulary —
the entity's word inventory, the frequency-band Level that selects puzzle
words, and the player's per-word progress.

## Language

**Crossword**:
A puzzle laid out from a fixed set of words selected for one Entity and
Level. Deterministic: the same inputs always produce the same grid.
_Avoid_: puzzle generator (the algorithm, not the artifact), quiz.

**Entity word list**:
The complete inventory of unique words in one Entity with an occurrence
count for each, built by tokenizing the entity's sentences. Token-first —
it exists before any dictionary link; the dictionary **Word** link fills
in later (see ADR 0025).
_Avoid_: book words (legacy crossword-domain term), index (implementation
term), vocabulary (vague — the dictionary as a whole).

**Level**:
A global frequency-rank band (top 100, top 500, … top 1 000 000) used to
select puzzle words. A word is eligible for a Level when its rank (lower =
more common) is within the band's cutoff.
_Avoid_: difficulty (implies curated ordering), CEFR level.

**Word progress**:
The player's status for one dictionary Word, global across all works:
**learning** (selected in a generated puzzle), **solved** (its puzzle was
completed), or **known** (marked by hand). Generation skips solved and
known words, so completing puzzles advances down the Level band.
_Avoid_: score, knowledge level.
