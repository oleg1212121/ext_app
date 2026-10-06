# ADR 0070: Band word lists min-merge onto frequency ranks

Date: 2026-10-06
Status: Accepted

## Context

ADR [0041](0041-frequency-rank-semantics-and-entity-correction.md) gave
`words.frequency` its rank semantics and two writers: the authoritative
corpus imports (`words:import-frequency`) and the per-entity 2% correction.
Two gaps remained in practice:

1. The Wiktionary import never ranks words — most of the dictionary sits at
   the unranked marker (1,100,000) and is crossword-ineligible until entity
   corrections pull it in (~5 entities per word).
2. The corpus ranks are surface-form ranks matched by direct `l_word`
   equality; the deferred forms-based aggregation (ADR 0041) meant a lemma
   whose inflected forms carry the corpus signal ranked poorly or not at
   all.

Separately, hand-curated **band word lists** exist — top-100 style lists and
CEFR lists (the 11 files shipped as `public/frequencies`, ~51k lines): each
carries a language code, a band number, and bare words, one per line. Their
bands match the `CrosswordLevel` cutoffs (100, 500, 1000, 3000, 5000, …).
They are an opinion about "words a learner should know by level N", not a
corpus measurement, and they carry no per-word values — every word in a
file would get the same rank.

## Decisions

### 1. A second rank source: `words:import-frequency-lists`

The command scans a directory (default
`storage/app/frequency-lists/`, `--path=` override; `--dry-run` supported)
for `*.txt` files and validates every header (line 1: language code known
to the app; line 2: positive band number) **before** touching the
database, so a malformed file fails the whole run instead of half-applying
the bands.

- **Uniform rank per file**: every word in a file gets the file's line-2
  band, trusting the label over the actual line count (a list labeled 5000
  holding 2000 words is fine). Alternative rejected: rank = line position —
  most files are alphabetical or thematic, so position would be noise.
- **min-merge, never overwrite**: `frequency = least(current, band)` — the
  clamp can only lower a rank. This is the deliberate opposite of
  `words:import-frequency`, whose ranks are authoritative overwrites: the
  lists fill the coarse gaps (unranked words, words the corpus lists miss)
  without disturbing finer corpus ranks. It is idempotent and
  order-independent — processing files largest-band-first is a readability
  choice, not a correctness one. A later corpus import overwrites
  band-clamped ranks (it is authoritative); re-run this command after one
  if the coarse bands should apply again.
- **Matching through forms** (the ADR 0041 deferred aggregation, delivered
  for the list path): a file word matches `words.l_word` directly **and**
  `forms.l_word → word_id` into its base word, so inflected entries rank
  their lemma. **All** matches get the min — a homograph like "left"
  lowers both the noun "left" and the base verb "leave". The bleed is
  bounded and the entity correction drifts ranks back over time; the
  alternative (direct match wins, forms only as fallback) was rejected as
  trading coverage for a rare cosmetic inaccuracy. The forms update is
  aggregated (`group by f.word_id, min(t.rank)`) — a bare
  `update … from` over a fan-out join would let Postgres pick an
  *arbitrary* join row instead of the smallest band.
- Every word-class row of a matched headword moves together (set-based
  through a session temp table, mirroring `words:import-frequency`).
  Matching is by normalized key — lowercased, combining marks stripped —
  so Russian stress-marked entries match their `l_word`.
- **Never creates words**; misses (including POS-tagged lines like
  `hatred n`) are skipped and counted in the end-of-run summary.

### 2. Scope: the frequency column and nothing else

Unlike `words:import-frequency`, a successful run does **not** reset
`entities.frequency_counted_at`: the correction markers stay burned. A
clamp to a coarse band is not an authoritative re-baseline — the entity
corrections already applied remain valid against it, and re-applying them
would compound drift for zero benefit.

### 3. Location: storage/app, tracked in git

The lists live in `storage/app/frequency-lists/`, next to the corpus
downloads of `words:import-frequency`, but unlike those they are **tracked
in git** (a `!frequency-lists/**` exception in `storage/app/.gitignore` —
that file's unanchored `*` also needs the parent-directory negation).
They are small, hand-compiled by the user from scratch — no third-party
source, no attribution obligation — and not re-downloadable, while
`deploy.sh` ships code via git pull, so tracking is what carries them to
prod and backs them up. The corpus downloads stay ignored: they are large
and fetched on demand.

## Consequences

- Words absent from every corpus list but present in a band list become
  crossword-eligible immediately; the "unranked" marker now survives only
  on words no list mentions.
- Homograph inflections can pull an unrelated base word into a coarse
  band ("left" → "leave"); entity corrections re-loosen it.
- The corpus-side forms aggregation (ADR 0041's deferred upgrade) remains
  open — this ADR covers only the list path.
- The lists are versioned data: edits to them are reviewable in git
  history and ship to prod with the next deploy.
