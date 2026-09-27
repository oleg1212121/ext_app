# ADR 0045: Word popup aggregates the word family

Date: 2026-09-27
Status: Accepted

## Context

Clicking a word like "melted" in a text opens the Word popup on a single
definition: "past participle of the verb melt". The chain that produces it:

- The Kaikki import stores every dump line as a standalone `words` row keyed by
  `(word, language, word_class)` — including Wiktionary's "form-of" entries,
  whose senses are verbatim boilerplate relaying to the base word. Nothing
  marks them; nothing links "melted" to "melt" (the link lives only in
  `forms`, where melt/verb lists `melted` among its inflected forms).
- The entity-word linker's exact pass prefers an exact `(l_word)` match over
  the forms pass, so the "melted" token links to the standalone "melted" rows.
- `WordController::show` loads only entries sharing the linked row's
  `l_word` — the melt entries are never queried, and `is_form` is false
  because surface == l_word.

So the reader gets one relay definition and no path to the real content.

## Decisions

### 1. Aggregate at display time

`WordFamily::resolve($linked, $surface)` builds the popup's entry list per
request: the surface's own headword group (as before, linked word first) plus
the headword groups of every **Base word** the `forms` table maps the surface
token to (`forms.l_word = lookupKey(surface)`, same language, own headword
excluded — kaikki lists a headword among its own forms). Base groups rank by
`words.frequency` (lower = more common), class priority as tiebreak, and are
uncapped — a form can legitimately point at several distinct lemmas
("left" → leave/lift), and truncation would hide the sense the reader wants.

Import-time tagging (an `is_form_of` column set from kaikki sense tags) and
linker re-targeting (linking "melted" straight to "melt") were rejected: the
first costs a 2.7 GB re-import for a flag the popup can infer, the second
changes `entity_words.word_id`, which familiarity (`user_word`), the crossword
word list and clues are all keyed by — a much bigger blast radius for a
display complaint.

### 2. Relay entries hide behind a pointer line

A **Form-of entry** is recognized at runtime by its definitions alone: an
entry whose definitions are all relay glosses ("simple past and past
participle of melt", "plural of ghetto", "Alternative form of X", Russian
"…от …" shapes — a bounded pattern list in `WordFamily`, each anchored at the
start so real definitions like "A form of address…" never match, with an
optional leading parenthetical tag since kaikki glosses carry
"(colloquial, nonstandard)" prefixes). When at least one base group exists,
such entries are dropped from the popup and the payload carries
`form_of: [base headwords]` — the UI renders the existing "«surface» — form
of «…»" muted line instead. Without a base group nothing is hidden: a lone
relay section beats an empty popup.

Because kaikki merges form-of lines into real entries ("saw/verb" carries
real saw senses *and* "simple past of see"), relay glosses are also filtered
per-definition from the remaining entries whenever base groups are present.

### 3. Popup-only: linking, familiarity, crossword untouched

`entity_words.word_id` stays wherever the linker put it, so `user_word`
familiarity, the crossword word list/clues, and the word maps are unaffected.
The payload change is additive: each entry gains its own `word` (so base
sections label themselves "melt — Verb"), plus the top-level `form_of`.

## Consequences

The pattern list can misfire in both directions; hardening it is a list edit,
and if runtime detection proves unreliable the escape hatch is the
`is_form_of` column + import tags + one-off backfill. The crossword still
shows boilerplate clues for form-of puzzle words (follow-up: route clue
selection through `WordFamily`). The AI context explanation still receives the
linked word as its "dictionary form" hint. A surface with combining marks
(Russian stress) makes `is_form` true even when the family lookup matched —
the popup prefers the `form_of` line, so the display stays correct.
