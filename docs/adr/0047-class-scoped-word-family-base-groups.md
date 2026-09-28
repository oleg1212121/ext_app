# ADR 0047: Class-scope word-family base groups

Date: 2026-09-27
Status: Accepted
Refines: ADR 0045 (decision 1)

## Context

ADR 0045 makes the Word popup aggregate the word family: the surface's own
headword group plus the headword groups of every Base word the `forms` table
maps the surface token to. "Groups" meant the whole headword group — every
word class sharing the base spelling — because the join discards which of the
base entries actually claims the form.

For "melted" → "melt" the collateral is invisible (melt has two classes and
the noun's "Molten material" rode along unnoticed). For single-letter
headwords it floods the popup: clicking "me" pulled in the character "I"
("ninth letter of the Latin alphabet"), the Roman numeral "I", and every
other article under the spelling "I" — none of which claims "me" as a form.
Only the pronoun does.

## Decisions

### 1. Scope each base group to the claiming word classes

`WordFamily::baseGroups` now collects the claiming rows as
`(base.l_word, base.word_class_id)` pairs and, after loading each base group
as before, keeps only the entries whose `word_class_id` a claim carries. The
forms row already points at the claiming entry, so no new data is needed —
the information ADR 0045 threw away is exactly the scope. A claim whose base
row somehow has no class keeps the whole group (defensive: `words.word_class_id`
is NOT NULL today, so the branch is unreachable through the schema).

### 2. Own group stays whole; ordering rules unchanged

Scoping applies to base groups only. Clicking a headword directly ("melt",
"I") still shows every class under the spelling — that is the headword
group's contract, and the linked word leads it. Group ranking (frequency,
class priority) is computed on the scoped groups, so it reflects what the
popup actually shows.

### 3. Not a typographic-class blocklist

Hiding base entries whose class is character/numeral/symbol was rejected: it
depends on the part-of-speech taxonomy staying meaningful (and on such
slugs existing per language), and it cannot catch cross-class noise
generally — a base group may carry any classes, and only the forms table
knows which one claims the surface.

## Consequences

Base groups shrink to what the surface really is. Visible changes: "me" now
shows only "I — Pronoun"; "melted" no longer shows "melt — Noun" (the noun
does not claim "melted") — clicking "melt" still shows it. Words whose
claims span several classes ("left" → leave verb + noun) are unaffected.
If kaikki's forms data ever under-claims (a form listed under one class
while the popup reader expects the sibling too), the fix is claim widening
at this one site, not a revert.
