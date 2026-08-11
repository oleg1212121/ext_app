# Sentence Alignment Context

The domain of pairing EN and RU sentences of the same text into meaning-equivalent
groups (meaning matches), produced by the alignment pipeline and refined by
humans in the Alignments editor.

## Language

**Entity match**:
The container pairing one EN entity with one RU entity ("the same text, two languages").
_Avoid_: match, alignment

**Meaning match**:
A row inside an entity match: a group of EN and/or RU sentences that the aligner
or a human judged to share meaning. Carries its own `order` (row sequence) and
`similarity` (aligner confidence).
_Avoid_: pair, semantic pair, unit

**Sentence**:
A split sentence of an entity. Its entity-global `order` is the single source of
truth for both original-text order and in-row sequence.
_Avoid_: line

**Junction**:
A sentence's membership link to a meaning match. Its `order` mirrors the linked
sentence's order; it is not an independent ordering.

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
