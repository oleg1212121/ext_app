# Sentence Alignment Context

The domain of pairing EN and RU sentences of the same text into meaning-equivalent
groups (meaning matches), produced by the alignment pipeline and refined by
humans in the Alignments editor.

## Language

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
A sentence's membership link to a meaning match. Its `order` is an **independent
within-row sequence** — the display order of the row's sentences. Dragging a
sentence within a row rewrites junction orders only and never affects the
sentence's document order.

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
