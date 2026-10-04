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
The container pairing two distinct entities of the same **Work** ("the same text, two versions"), held by its **A-side** and **B-side**. The two entities are usually in different languages, but a same-language pairing (exercises + answers) is equally valid. Shown to users under the label "Alignments" (see the Library Context's **Alignments page**). See ADR 0019.
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
below the pipeline's acceptance floor) or one-sided (incomplete) and not
human-confirmed — a single-sided match trusted at similarity 1.0 was shaped by
a human on purpose and is treated as resolved (see ADR 0049). Surfaced in the
Alignments editor as a review list.
_Avoid_: low-similarity match (score-only wording, misses one-sided rows), resolved (not a stored state; the human-confirmed convention replaces it)

**Alignment editor**:
The human-refinement surface for one Entity match — the React page at
`/alignments/{id}`, backed by the surgical `AlignmentEditorController`
endpoints, where sentences are added, edited, dragged between rows and the
unmatched pool, and rows are created, approved, and deleted. Opened from the
work's Alignments page cards, the entity page, and the Filament Sentence
Alignment list's edit link. The one editing surface (ADR 0062); the Filament
resource remains the operations console (Re-align, Run from scratch) over
the same data.
_Avoid_: Filament editor (the retired draft editor), draft editor, alignment
editor page (there is only one).

**Sentence**:
A split sentence of an entity. Its entity-global `order` is the **document order** —
the order of the sentence in the original text. The alignment pipeline and the
reader rely on it. The alignment editor renumbers it when a sentence is dragged
(see Move sentence); the entities frontend's insert/reorder operations are the
other mutation paths.
_Avoid_: line

**Illustration**:
A picture inserted into an entity's text at a document-order position, carried
by a sentence of its own whose text is the optional caption. It participates in
meaning matches like any sentence — typically paired with the same picture in
the counterpart edition, validly unmatched or paired with text. It is never
sent to the aligner; an alignment's cursor space counts only image-less
sentences (see ADR 0050).
_Avoid_: image sentence (the marker is the image, not the type), figure.

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

**linked_count**:
The number of meaning matches in an entity match (empty ones included).

**Resume**:
Advance an alignment that has stopped before reaching the end of the text.
Triggered manually (Re-run) or automatically (the `alignments:resume` command).
The cursor — the per-side sentence offsets where the next chunk starts — is the
only state a resume reads, so a stopped run can continue without wiping
already-aligned chunks. _Avoid_: restart, retry.

**Stale**:
The display-only state of an entity match whose sentences changed (insert /
update / delete / reorder) after its last alignment run. Purely a signal —
the scheduler never picks it up; only an explicit human action (Re-align,
Run from scratch, a full editor save, a sentence re-import) acts on it or
clears it. Holds no processing slot. See ADR 0055. _Avoid_: pending (that is
the fresh-match state), outdated.

**Alignment copy**:
Satisfying a newly created Entity match by cloning a completed alignment that
already exists between an **Exact copy** of each side (same texts, same
languages, either orientation) instead of running the alignment pipeline. Rows
and junctions are cloned wholesale — the Nth sentence of a copy corresponds to
the Nth sentence of its source — human-confirmed rows included; the new match
is complete the moment it is created. No record of the source alignment is
kept on the copy. Falls back to the pipeline when the texts do not line up.
_Avoid_: alignment clone, cached alignment (it is copied, not memoized).
See ADR 0033.

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
The reader's AI answer text rendered in the AI Response panel of the
bilinguals simulator. Not to be confused with a dictionary gloss.
_Avoid_: AI answer (transport/implementation term), response

**Gloss run**:
A hoverable text-level unit inside a gloss — any text-bearing element the
markdown-to-HTML conversion emits (paragraph, list item, heading, `em`/`strong`
run, code, etc.). Signals interactivity with a pointer cursor and an
accent-tinted background on hover. _Avoid_: html element (implementation term)

**Question template**:
The admin-owned part of the assessment question — the format-rules
instruction the learner cannot edit, shown above the question input as
read-only text. Its `:base`/`:learning` placeholders stand for whichever
languages currently play the base and learning columns, so the displayed
text follows the language swap. Edited in the admin panel, not in UI
settings. _Avoid_: format rules (only its current content), prompt (the
assembled whole), default question (that was the old single-part text).

**Task list**:
The learner-editable part of the assessment question — what the reader
should do with the translation (assessments, corrections, improved
versions). The learner's customized task list is a UI settings entry;
clearing it falls back to the admin-managed default. The server joins the
Question template and the task list into the assessment question it sends.
_Avoid_: question (that is the assembled whole), tasks, prompt.

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
The stable, user-chosen interface configuration inside User settings — simulator layout and panel visibility, font sizes, the selected AI model, the customized assessment task list, and panel sizes. A sub-kind of User settings; changes follow the user across devices. See ADR 0024. _Avoid_: simulator cache, UI state (that includes Working state, which is not stored server-side).

**Working state**:
The per-device last position on a reading surface — in the simulator, the current entity match, the page reached per alignment, and the last opened row with its revealed halves; in the reader, the per-text **Reading position**. Kept in the browser only, never stored server-side. See ADR 0024 and ADR 0032. _Avoid_: UI settings (durable, cross-device), cache, session.

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

**Form-of entry**:
A Word imported from a Wiktionary form-of line — its definitions merely
relay to another Word ("past participle of the verb melt"). The Word popup
shows it as a "form of" pointer line, never as content of its own.
_Avoid_: duplicate, variant, stub.

**Base word**:
The Word a surface form belongs to via the forms table — "melt" is the Base
word of "melted". A Word popup lists every Base word's headword group after
its own. _Avoid_: lemma, root, parent.

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

**Raw dump**:
The monolithic kaikki.org extract of the entire English Wiktionary — one
JSON object per line, mixing the entries of every language in one file,
each entry carrying its language. Source material for **Language
extracts**; never imported directly.
_Avoid_: kaikki file (ambiguous — per-language dumps also exist), full dump.

**Language extract**:
The per-language file filtered from a **Raw dump** — raw lines of exactly
one language, safe to feed the single-language import. Working data of the
pipeline, kept next to the dump and overwritten on each extraction.
_Avoid_: split file, per-language dump (that's the kaikki.org pre-split
download).

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
the **Models endpoint**.
_Avoid_: API URL, completion URL

**Models used popup**:
The centered modal listing the AI models a reading surface currently answers
from — the model behind AI questions (the simulator's **Gloss**) and the
model behind **Context explanations** — each label linking to the profile's
AI Models tab, with a hint while the explanation model merely follows the
answer model. Opened from the robot-with-question-mark icon beside the AI
Response panel's title and beside the **Word popup**'s Explanation tab; a
reader without a **User key** meets the add-a-key call to action inside it
instead of the model rows.
_Avoid_: model tooltip (the retired always-visible header link it replaced),
help modal

# Entity Access Context

The domain of who may read an Entity or an Entity match, layered on top of the
Access Control Context. Every upload defaults to a Restricted state and records
its **Uploader**; an admin publishes it to make it Public; an upload that is an
Exact copy of an existing Entity still creates the uploader their own Entity,
cloned from the existing one (ADR 0033).

## Language

**Restricted entity**:
An Entity readable only by admin and explicitly granted users. The default
state for every newly uploaded Entity. _Avoid_: copyrighted (legally imprecise
— every original text is copyrighted by default), paid, premium, licensed,
private (the default-visibility state is Restricted; "private" reads as a
separate third tier that does not exist).

**Public entity**:
An Entity any approved user may read. Set by an admin publishing a Restricted
entity (`is_restricted = false`). _Avoid_: free, open, public-domain (a legal
term with a specific meaning).

**Uploader**:
The user who created the Entity, recorded at creation (null for system/admin
imports). The Uploader receives a **Creator grant** and may flip the entity's
**Approved** flag. _Avoid_: owner (implies transferable property), creator
(the Creator grant is the access artifact, not the person).

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
`EntityAccessService::canEdit` is structurally identical to `canRead` — except
that an **Approved entity** is editable by admin only. Sentence mutations
(insert / update / delete / reorder) flip every entity match involving the
entity to **Stale**. Deleting a junctioned sentence cascades
(junctions removed, emptied meaning matches deleted, `linked_count` updated)
— a deliberate divergence from the alignment editor's unlink-before-delete
rule. See ADR 0015, ADR 0034 and ADR 0055.

**Creator grant**:
An Access grant with a null `similarity`, recording that the user's upload
created the Entity (no prior **Exact copy** existed). Distinct from a
legacy Signature match grant, whose `similarity` is the cosine score.

**Approved entity**:
An Entity locked for content changes: its metadata, sentences, every entity
match it takes part in, and its deletion are frozen for everyone except
admins. The Uploader is locked out too — the only change that stays possible
is flipping the approval off again, which the Uploader and admins may do in
either direction. Distinct from an approved *User* (site-access approval).
_Avoid_: locked entity, protected entity, published (Publish is the
visibility flip).

**Exact copy**:
Two entities whose texts are identical sentence-for-sentence in the same
language — same sentence contents in the same document order, whitespace
differences aside, detected by comparing each text's **Text hash** (or, for
byte-identical uploads, the **File hash**). Exact copies coexist as
independent entities: an upload that is an Exact copy creates the uploader
their own Entity cloned from the existing one (sentences, signature, word
statistics) rather than being merged or rejected, and deleting either never
touches the other. _Avoid_: duplicate (implies one should be removed),
near-duplicate (the embedding-similarity notion; distinct).

**Text hash**:
A digest of an entity's sentence contents in document order, each sentence
whitespace-normalized. Two entities with equal Text hashes are Exact copies;
an **Alignment copy** between one exact-copy pair can be reused for another.
Recomputed after every sentence mutation.

**File hash**:
A digest of the uploaded file's raw bytes, taken at upload time. Byte-identical
uploads are Exact copies by definition, letting the upload skip even sentence
splitting. Coarser than the Text hash: an edited text keeps its File hash
while its Text hash changes.

**Signature match**:
(Removed as an upload-time concept, ADR 0033.) Historically: a cosine
similarity ≥ 0.95 between uploaded and existing signatures that granted
access instead of creating an entity. The embedding **Signature** now serves
only as a candidate finder — suggesting counterpart entities for an Entity
match (and gating pair verification) — never as a duplicate detector.
_Avoid_: dedup, similarity match.

**Publish**:
An admin action flipping a Restricted entity to Public. Existing Access
grants remain as audit but are no longer enforced. _Avoid_: release, unlock,
approve (that is the Approved edit lock).

**Readable count**:
Any count of entities or entity matches shown to a user counts only what that
user could actually open (Public entities plus their own grants; for matches,
both sides readable). A global total leaks Restricted entities' existence.
_Avoid_: total count, library size.

# Library Context

The domain of the user-facing browse surface for works and their texts — the
`/works` section reached through the navbar's Library dropdown.

## Language

**Library**:
The user-facing section — a nav dropdown with three branches over the
`/works` URL space — where an approved user browses the **Work catalog** and,
inside a work, its texts and entity matches. The branches: **Works** (the
catalog lists), a work's **Entities page**, a work's **Alignments page**.
_Avoid_: Parallel Library (the Reader's former on-page subtitle), tab (the
retired per-work tab layout — see ADR 0039).

**Practice**:
The navbar group of self-study surfaces — the Reader (the text library a
learner reads from) and the Simulator (the bilingual trainer with its
alignment picker). A menu group, not a surface of its own; both surfaces
also keep their deep-link entries from alignments. See ADR 0038.
_Avoid_: training, exercises.

**Work landing page**:
A work's own page (`/works/{id}`): the catalog metadata (title, author,
original language, description) plus the work's readable entity and
alignment counts (see Readable count), each linking to the work's
**Entities page** / **Alignments page**. _Avoid_: work page (ambiguous —
any of a work's pages), work detail.

**Entities page**:
A work's page (`/works/{id}/entities`) listing the per-language texts of
that work the user can read — where new texts are uploaded to the work.
_Avoid_: entities tab (retired with the tab layout), text list.

**Alignments page**:
A work's page (`/works/{id}/alignments`) listing the **Entity matches** of
that work, labeled "Alignments" in the UI — the canonical term stays Entity
match. Creating a match happens from this page: the work is the page the
form lives on, never a picker choice. An individual match's editor keeps
its own standalone address. _Avoid_: alignment list (the removed global
surface), global alignments, alignments tab (retired with the tab layout).

**Work catalog**:
The complete set of **Works**, visible to every approved user regardless of
entity access — including works with no entities yet. A Work carries no access
semantics of its own; access control and counts live on its entities (see
Readable count in the Entity Access Context). All three branch lists
(**Works**, and the per-branch lists at `/works/entities`,
`/works/alignments`) show this complete set; only the counts are
readable-scoped. See ADR 0021.
_Avoid_: available works (Available is the AI-provider term), my library,
book collection.

# Processing Limits Context

The domain of how much of the shared background pipeline one user may occupy
at once — the caps on a user's concurrently processing entities and
alignments, and the states that count against them. See ADR 0044.

## Language

**Processing status**:
The explicit lifecycle column on an Entity: `processing` while its upload
pipeline runs, `completed` when split/hash/signature are done, `failed` when
the pipeline exhausted its retries. Scheduled enrichment never re-enters
`processing`. _Avoid_: signature status (the display-only derivation),
entity state.

**Alignment owner**:
The user recorded in `entity_matches.created_by` — who created the match,
and whose alignment slot it consumes. The paired entities may have different
uploaders; ownership never derives from them. _Avoid_: creator (the Entity
term), uploader (belongs to entities).

**In-flight**:
An entity whose **Processing status** is `processing`, or an alignment whose
status is `pending` or `aligning` — the states that hold one of the owner's
slots. _Avoid_: pending (overloaded), running, active.

**Processing limit**:
The configurable per-user cap on concurrently **In-flight** entities and
alignments, enforced only at creation. Admins are exempt. _Avoid_: quota,
rate limit (a rate, not a concurrency), concurrency cap.

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

**Word list refresh**:
The background operation that rebuilds an Entity's **Entity word list**
and fills its dictionary **Word** links — a scheduled sweep dispatches one
queued refresh per entity whose list is stale or has unlinked tokens. Until
it completes, crossword generation reports the word list as still building.
_Avoid_: reindex, rebuild, backfill.

**Unmatchable stamp**:
The marker on an Entity word list entry that found no dictionary **Word**
to link to, letting later refresh runs skip it instead of re-scanning it.
Cleared when the dictionary grows — an import clears the stamps of the
imported language so the tokens get another linking chance. See ADR 0043.
_Avoid_: failure, dead token, blacklist.

**Level**:
A global frequency-rank band (top 100, top 500, … top 1 000 000) used to
select puzzle words. A word is eligible for a Level when its rank (lower =
more common) is within the band's cutoff.
_Avoid_: difficulty (implies curated ordering), CEFR level.

**Frequency rank**:
The number on a dictionary Word telling how common it is in the language:
lower = more common; 1 100 000 means unranked (absent from the imported
frequency lists, native-speaker territory). Imported from public
frequency lists, then nudged by Frequency corrections. _Avoid_:
frequency count (the number is a position, not an occurrence tally),
popularity.

**Frequency correction**:
The one-time pull a text applies to a word's Frequency rank: 2% of the
rank's own value toward the word's position in that text's word list,
never overshooting the position. Each text applies it exactly once; a
heavily-used unranked word earns its way into the Level bands over
several texts. _Avoid_: boost (moves both directions), accrual
(implementation term).

**Word familiarity**:
The reader's exposure score for one dictionary Word, global across all
works: 0–100, where 100 means the word is known and no row means never
touched. Reads add +1, lookups subtract 2, completed crosswords add 5.
_Avoid_: status, progress, level, score.

**Read event**:
One counted exposure of a word through a revealed sentence pair. Credited
once per sentence pair, ever.
_Avoid_: view, hit, impression.

**Lookup event**:
One counted dictionary-popup open of a word within a sentence pair — a
signal the reader did not know it. Credited once per sentence pair, ever.
_Avoid_: click (the mechanical action), search.

# Interactive Reading Context

The domain of reading surfaces where the text itself is interactive — words
looked up in the dictionary and tinted by the reader's **Word familiarity**.

## Language

**Interactive word**:
A dictionary-linked token rendered as clickable text on a reading surface;
Ctrl-clicking it opens the **Word popup** — a plain click does nothing.
Tokens without a dictionary link are never interactive.
_Avoid_: clickable text, word link.

**Reading side**:
The side of an **Entity match** a reading surface (reader, bilinguals
simulator) opens as the text being learned: never the side in the user's
**Native language** when exactly one side is native; otherwise the work's
**Original text** side; otherwise the A-side. The same rule drives the
library's Read button and the reading pages — see ADR 0037.
_Avoid_: primary side (payload/prop vocabulary, not the concept), learning
side (simulator display-column wording).

**Translation side**:
The side of an Entity match shown as the translation against the **Reading
side** — by construction the side in the user's Native language when one
exists. Not stored per match; derived by the same rule.
_Avoid_: base side (the simulator's display-column role, which is
positional, not language-derived).

**Side swap**:
A reader's per-device flip of the **Reading side** and **Translation side**
around their computed default, via the pages' language toggle. A
**Working state** kind: kept per device (keyed per text/match), never stored
server-side, and the default always recomputes from the Native language.
_Avoid_: language setting (a durable UI setting it is not), reverse mode.

**Word popup**:
The anchored popover a Ctrl-click on an Interactive word opens: one section
per Word class under the **Headword** with its definitions, transcriptions,
translations, examples and etymology. On surfaces that provide AI context it
is tabbed — the dictionary content on the first tab, the **Context
explanation** on the second — with the progress actions in a footer shared
by both tabs. Sized to the viewport — it flips above the word when there is
more room above and scrolls internally instead of running off-screen. Which
blocks below the headword render at all is the reader's choice through
**Popup preferences**; the headword itself always shows.
_Avoid_: modal (it is anchored to the word, not centered), tooltip.

**Popup preferences**:
A user's per-block visibility map for the Word popup — which lines
(familiarity, frequency, form-of), family sections, dictionary satellites,
the Explanation tab and the progress buttons render for them. One shared map
for every surface that shows the popup; the headword is never hideable, and
a block the map does not mention shows by default. Edited on the profile's
Popups tab over a live preview of the real popup.
_Avoid_: popup settings, popup config, section flags.

**Context explanation**:
The AI explanation of an Interactive word as it is used in its sentence —
built from the sentence before, the clicked sentence, and the sentence after
(by document order in the same entity), requested manually from the Word
popup's second tab and replied in the user's **Native language**. Distinct
from a **Gloss** (the simulator's assessment answer) and from dictionary
definitions (static imported content, no sentence context).
_Avoid_: word gloss (collides with Gloss), AI answer, definition.

**Headword**:
The spelling that groups the dictionary Words of one language: one Word per
part of speech may share it. The Word popup lists every Word under the
Headword, one section per Word class.
_Avoid_: lemma (implementation shorthand), entry (the popup section, not the
spelling).

**Word family**:
The set of headword groups the Word popup shows for one token: the surface's
own group plus, for every Base word claiming the token as one of its forms,
its group scoped to the claiming word classes — ranked by frequency. The
popup's content unit — not a stored structure.
_Avoid_: word group, cluster, entry set.

**Word occurrence**:
One place a token appears in an entity's text. Occurrences are derived from
the sentence text at render time and are never stored (see ADR 0027).
_Avoid_: word position (implementation detail), word hit.

**Reading row**:
The payload's unit of presentation on a reading surface (ADR 0060) — one
meaning match, or one sentence of an unaligned entity, shaped as a row
object with a `key` (`mm:`/`es:`) and its two sides in canonical A-side/B-side
order. Each side carries its sentences in document order as self-describing
objects (text with optional stress/phrasal annotations, or an illustration
with its caption); a single-language row has no second side. Which side a
surface displays first is the reader's flip around the server's default,
never a reordering of the rows themselves.
_Avoid_: bilingual row (single-language rows are Reading rows too),
alignment row (the Alignments editor's rows), pair (the old two-string
array shape).

**Word map**:
The per-entity lookup an interactive page carries — lowercase token to its
dictionary Word id and the reader's **Word familiarity** — covering the
entity's linked **Entity word list** entries only.
_Avoid_: dictionary (the whole kaikki import), vocabulary.

**Reading position**:
The per-device last page reached in one text on a reading surface — a
**Working state** kind, one entry per text in the browser's position store.
Restored when the text is reopened; never stored server-side. See ADR 0032.
_Avoid_: cache, bookmark, progress (that means Word familiarity here).

# Background Jobs Context

The domain of the application's queued background work — job classes, the
processing-order lane each runs in, and how workers consume those lanes.

## Language

**Job lane**:
The processing-order class of a background job, fixed on the job's type:
a job someone waits on runs in the immediate lane; a job nobody waits on
runs in the **Low lane** and is processed only when no immediate work is
pending. A lane changes when work runs, never how it is retried or treated
on failure. A dispatch site may still move a single dispatch to another
lane, but by default the type decides. See ADR 0042.
_Avoid_: priority level (a lane is a reason, not a rank score), importance,
queue (the implementation), urgent job, deferred job.

**Low lane**:
The lane for background jobs nobody actively waits on — scheduled sweeps
and hygiene work. May wait behind all immediate work; starving there is
accepted while immediate work exists.
_Avoid_: low-priority queue (the lane is not a queue setting of its own),
background lane, bulk lane.

**Run budget**:
The fixed maximum amount of work one background job run may process — byte
chunks, rows, or external calls. A run that reaches its budget stops
cleanly and hands control back to the queue or the scheduler; it never
processes "whatever is left". See ADR 0043.
_Avoid_: batch size (an insert/storage detail), limit (too generic), chunk
(means an alignment window here).

**Progress marker**:
Durable per-entity state recording how much of a pipeline earlier runs
committed, letting the next run resume instead of restarting. Committed
atomically with the work it describes; a re-supplied input resets it.
_Avoid_: offset (the column name), checkpoint, watermark, cache.

**Dispatch cap**:
The per-sweep maximum number of jobs a scheduled command dispatches; work
beyond it waits for the next tick of the same sweep. See ADR 0043.
_Avoid_: rate limit, throttle, batch.

# Sentence Enrichment Context

**Stress marks**:
The pronunciation aid drawn over a sentence's words: a combining acute on the
stressed vowel (Russian also places proper ё). Display-only — a property of
the sentence's presentation, never of its text.

**Stressed variant**:
The sentence with stress marks applied, kept beside the original sentence
text as its display substitute when the reader turns stress marks on. Always
sentence-aligned one-to-one with the original. See ADR 0052.
_Avoid_: stressed content (the text itself is never stressed), accent text.

**Multi-word verb hit**:
A detected multi-word verb inside a sentence — the verb with its
particle(s)/preposition and where they sit in the sentence. Detected by
dependency parsing: particle verbs ("gave up", "looked it up") on parse
evidence alone, prepositional ("depend on") and phrasal-prepositional
("put up with") verbs only where the dictionary lists them. Underlined
on the reading surfaces when the reader turns multi-word verbs on. See
ADR 0059. _Avoid_: phrasal link (nothing is linked), verb phrase
(broader), phrasal verb hit (the pre-parser term — a prepositional verb
is not a phrasal verb).

**Enricher**:
One enrichment analysis with declared language applicability and an
algorithm version — the unit that answers "what should be included in
the enrichment process" for a language (stress marks for Russian,
stress marks and multi-word verbs for English). Adding an analysis
means adding an enricher; languages it does not declare never run it.
See ADR 0057, ADR 0059. _Avoid_: processor (collides with entity/alignment
processing states), algorithm (the code-level notion, not the domain unit),
pipeline (the whole orchestration, not one analysis).

**Enrichment staleness**:
Whether a sentence set needs (re-)enrichment, tracked per enricher: an
enricher is stale when it never ran, when it is newly registered (no
stamp), when it was last run by an older algorithm version, or when any
sentence changed since it did. A language with no enrichers cannot go
stale. See ADR 0052, ADR 0057, ADR 0059.
_Avoid_: enrichment status (there is no processing state, only staleness),
dirty.

# Phoneme Reference Context

**Resources menu**:
The navbar dropdown holding reference surfaces — content that supports study
but is not itself a practice flow. The **Phoneme reference** is its first
entry. See ADR 0056. _Avoid_: tools, extras, reference section (ambiguous
with the word-level reference material of the Dictionary Context).

**Phoneme reference**:
The site-wide pronunciation chart: every sound of English and Russian as a
card with an articulation diagram, reached from the navbar's **Resources
menu**. Reference content shipped with the app (static data + inline SVG),
not user- or DB-derived. English is taught as General American; Russian as
the practical hard/soft inventory. See ADR 0054, ADR 0056.
_Avoid_: transcription table (that names the word-level **Transcription**
records of the Dictionary Context), sounds table, IPA chart.

**Phoneme card**:
One sound's entry in the reference: IPA symbol, common spellings, articulation
diagram, bilingual production description, example words with the sound
marked, and a **cross-language hint**. Clicking a card opens the enlarged
detail view.

**Articulation diagram**:
The mid-sagittal mouth drawing on a phoneme card: tongue, lips, teeth, palate
and velum positions for that sound. Rendered parametrically from a state
description (framework-free geometry code), not from image files; two states
side by side (start → end) for diphthongs and affricates.
_Avoid_: mouth picture, GIF (nothing animated ships yet).

**Sound group**:
The section a phoneme card belongs to within a language tab: English groups
by manner (vowels, diphthongs, stops, …); Russian groups as six vowels, the
hard/soft pairs, always-hard and always-soft consonants.

**Cross-language hint**:
The per-sound tip that anchors a sound in the learner's other language
(English cards hint against Russian and vice versa). Shown in the interface
language. Distinct from the production description, which only says how the
sound is made.
_Avoid_: tip (too generic), translation.

**RP note**:
An English card's note where British Received Pronunciation differs from the
taught General American form (a different symbol, a non-rhotic realization).
Never a second chart — one variant per note.
_Avoid_: British chart, RP tab.
