# Sentence Alignment Context

The domain of pairing EN and RU sentences of the same text into meaning-equivalent
groups (meaning matches), produced by the alignment pipeline and refined by
humans in the Alignments editor.

## Language

**Entity**:
A text in exactly one Language, carrying a name, description, an uploaded text file, a signature, and an ordered list of sentences. Concretely an `EnEntity` or a `RuEntity`. An Entity does not span languages; a cross-language pair is an Entity match, not a single Entity.
_Avoid_: text, document, article

**Entity match**:
The container pairing one EN entity with one RU entity ("the same text, two languages").
_Avoid_: match, alignment

**Original text**:
The language the paired text was authored in; the counterpart in the entity match
is a translation of it. A text-level property of the entity match.
_Avoid_: source text, prior text

**Original completeness**:
The invariant, enforced when an alignment run completes, that every sentence of the
original text is junctioned into a meaning match (in original order). Only
translation-side sentences may be unmatched.
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
reader rely on it; the alignment editor never changes an existing sentence's
document order.
_Avoid_: line

**Junction**:
A sentence's membership link to a meaning match. Junctions are pure association
tables with no `order` column. Within-row display order is determined by each
sentence's document order (`*_entity_sentences.order`). Dragging a sentence
within a row reorders via document order on the sentence table.

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
The cursor — the EN/RU sentence offsets where the next chunk starts — is the
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
in the `en_entity_user` / `ru_entity_user` pivot (with a nullable
`similarity`). Carries both read and edit permission on the entity (and its
sentences) until the entity is published. _Avoid_: link (too generic),
license (legal), permission (overlaps Role).

**Edit rule**:
Who may edit an Entity (name, description) and its sentences in the entities
frontend. A Restricted entity is editable by admin and grantees; a Public
entity is editable by any approved user. The rule mirrors read —
`EntityAccessService::canEdit` is structurally identical to `canRead`.
Sentence mutations (insert / update / delete / reorder) flip every
`EnRuEntityMatch` involving the entity to `status = 'pending'`. Deleting a
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
