# ADR 0059: Multi-word verbs via spaCy dependency parsing

Date: 2026-10-02
Status: Accepted

## Context

Real-text review produced false positives — "done, it", "not say",
"could have given" underlined as phrasal verbs; some are not even verb
structures. Three root causes compounded:

1. **Lexicon junk (data)**: the lexicon is *all* dictionary multi-word
   verb headwords, and both write paths pollute that set — the Wiktionary
   import stores dump pos verbatim ("do it", "could have", "be there"),
   and `FetchWordTranslations` stored multi-word provider candidates
   under the provider's pos, creating junk verb rows ("not say").
2. **Matcher blindness (code)**: the n-gram matcher sees only word
   tokens — punctuation is invisible ("done, it" is a contiguous
   window), any verb-classed lead can match, and there is no notion of
   particle vs object after the verb.
3. **Scope honesty**: window matching can never validate structure; the
   false-positive class is inherent to ADR 0052's contiguous n-gram
   design.

The user chose a rewrite over a matcher repair.

## Decision

- **spaCy replaces n-gram matching** (`ai/enrichment/phrasal.py`):
  `en_core_web_md` parses the raw sentence (baked into the image via
  `requirements.txt` — package + model wheel); the matcher walks VERB
  tokens (AUX excluded — "could have given" can never match) and
  combines the lemma with `prt`/`prep` children. Particle verbs hit on
  **parser evidence alone** ("gave up", "looked it up" — separable
  verbs now work); prepositional ("depend on") and
  phrasal-prepositional ("come up with") matches are
  **dictionary-gated**. Hit shape `{verb, particles, start, end,
  phrase}` is unchanged; ADR 0058's `verb_lemmas` ride along as lexicon
  lemma candidates.
- **The lexicon is shape-curated** (`App\Classes\MultiwordVerbShape`):
  2–4 tokens whose trailing tokens are all in a closed
  particle/preposition whitelist. Junk ("do it") and idioms ("kick the
  bucket") can never pass — **idioms are out of scope** (no
  particle/prep structure to parse). `FetchWordTranslations` now stores
  multi-word candidates under the `phrase` class, and
  `words:reclass-multiword {--dry-run}` reclasses existing English
  junk verb rows to `phrase`.
- **Versioned enrichment stamps**: `Enricher::version()`; stamps are
  `{v, at}` (legacy bare-string stamps read as v1) and a version bump
  re-stales the corpus so the five-minute sweep re-runs everything with
  no manual backfill. `en_phrasal` is now **v2**;
  `entities:enrich --enricher=en_phrasal --limit=N` stays the manual
  accelerator (it already ignores stamps — no new flag).
- **Fail loudly**: `/enrich` returns 503 when spaCy or the model is
  missing; empty enrichment is never written and stamped as done.
- **User-facing term**: "Multi-word verbs" (UI string; ru keeps
  "Фразовые глаголы" — the established umbrella in Russian pedagogy).
  Storage keys (`en_phrasal`, `phrasal_verbs` column) unchanged.

This supersedes the n-gram matcher of ADR 0052 and the matcher half of
ADR 0058 (whose `verb_lemmas` stand). ADR 0052's ground rules — local
only, `content` never mutated, spans into `content` — all hold.

## Consequences

- The reported false positives die structurally (AUX exclusion, no
  prt/prep children, shape-gated lexicon), pinned by python and Pest
  regression tests.
- Sense ambiguity remains: a lexiconed prepositional verb can hit in a
  literal reading ("sat in the car" when "sit in" is lexiconed). Parser
  evidence bounds it; eliminating it would need sense disambiguation —
  out of scope.
- `en_core_web_md` is ~40 MB installed and ~0.5 GB RAM: fits the prod
  python container's 5 GB `mem_limit` beside BGE-M3/LaBSE; ~10k
  words/sec CPU — a 75-sentence chunk parses well under a second.
- Deploy: `requirements.txt` is a container-definition file — the stamp
  guard refuses until `build python`, `up -d`, `./deploy.sh --stamp`;
  the native path pip-syncs the venv automatically. Dev/prod data:
  `words:reclass-multiword` (dry-run first), then the sweep grinds the
  v2 backfill (or the accelerator).
- Russian is untouched; other languages stay non-enriched.
