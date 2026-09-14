# Same-language entity matches

An Entity match may pair two entities of the same work in the **same language**
(for example a book of exercises and its answer key). Until now every creation
surface rejected same-language pairs ("Both entities must be in different
languages") — AlignmentController, both Filament match pages, the legacy
sentence importer, and the Alignments create form's language filter.

The rule everywhere is now: same work, two distinct entities, both readable.
Language (same or different) carries no validation weight. The a/b sides stay
canonical by entity id (the lower id is the a side), unchanged from
cross-language matches.

Consequences:

- The embedding aligner runs as-is for same-language pairs; it aligns similar
  text regardless of language. When both sides are in the work's original
  language, `EntityMatch::originalSide()` resolves to 'a' (first match wins) —
  acceptable because sides are positional, not semantic.
- `alignableWorks()` lists works with at least two eligible entities (any
  languages), not two distinct languages.
- The Filament "Find Match" candidate list (`TextSignatureService::findCrossLanguage`,
  name now historical) includes same-language candidates, still filtered by
  same work and the 0.95 signature threshold.

Alternatives rejected: keeping the cross-language-only rule (blocks the
exercises/answers use case), and special-casing same-language matches to skip
the aligner (two code paths for no user-visible benefit).

See also ADR 0013 (grant-based entity access) — matches remain readable only
when both sides' entities are readable.
