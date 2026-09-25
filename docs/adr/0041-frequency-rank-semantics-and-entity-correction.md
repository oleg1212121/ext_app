# ADR 0041: Frequency rank semantics and the entity corpus correction

Date: 2026-09-25
Status: Accepted

## Context

`words.frequency` (ADR [0025](0025-crossword-word-index-and-progress.md),
decision 2) stores a **rank** — lower = more common — and crosswords select
words by band cutoffs on it. Three problems made the column unusable in
practice:

1. It defaulted to `0`, and since a word is eligible when
   `frequency <= cutoff`, unranked words passed **every** band.
2. The column was `numeric(8,2)` — max 999,999.99 — so no "unranked"
   marker above the widest cutoff (1,000,000) could even be stored, and the
   migration comment claimed count semantics ("more = more frequent"),
   contradicting the rank semantics the code and ADR 0025 rely on.
3. The user's library itself carries frequency signal — a text that uses a
   word a hundred times says something the global list does not — but
   nothing folded that signal back into the rank.

The entity signal lives in `entity_words` (per-token occurrence counts,
wholesale-rebuilt on every re-index, `word_id` filled in by the link pass).

## Decisions

### 1. Rank stays rank; 1,100,000 is the unranked marker

`frequency` remains a rank (lower = more common). The old `0 = unranked`
marker is replaced by `1,100,000` — one above the widest `CrosswordLevel`
cutoff — so an unranked word is eligible for **no** level until something
promotes it. The column is widened to `numeric(12,2)`: it must hold the
marker, and the entity correction produces fractional ranks (e.g. 123 →
125.46). The migration comment is corrected; `Word::FREQUENCY_UNRANKED`
names the marker in code. Alternatives rejected:

- *Storing occurrence counts*: contradicts every consumer
  (`CrosswordLevel`, ordering, band filters) and the committed glossary.
- *base_rank + derived frequency columns*: fully idempotent, but the user
  explicitly chose one column — re-imports are a one-time operation, and
  the entity corrections are meant to accumulate as the library grows.

### 2. The correction: a 2% pull toward the entity's own ranking

`WordFrequencyAccrual` processes each entity exactly once
(`entities.frequency_counted_at`, null = pending). The entity's linked
word list is ranked by occurrence count (`count` desc, `l_word` asc —
deterministic); for the word at position *p* with current rank *f*:

```
step = 2% × |f|          # 2% of the word's current rank
delta = clamp(p − f, −step, +step)
f ← max(1, f + delta)
```

The pull is **toward** the entity position: frequent-in-corpus words get
more common (rank shrinks), and a word rarer in the corpus than its global
rank suggests drifts slightly the other way. The clamp prevents
overshooting the position; the floor prevents rank 0. Every word-class row
of the headword moves together (the link pass picks one row per headword;
class siblings must not disagree on frequency). The update is a single
self-contained SQL statement so the rank is read and written atomically.

Convergence for unranked words: each entity pulls 2% of 1,100,000 ≈
22,000, so a word appearing in ~5 entities crosses the 1,000,000 cutoff
and becomes crossword-eligible — the user explicitly wanted corpus-heavy
unranked words (likely names/typos on the global scale) to earn their way
in through library usage, not through the global list.

Why not literal addition of occurrence counts (the original ask): with
rank semantics, adding counts points the wrong direction — a common word
would look rarer.

Why once per entity, never re-applied: `entity_words` is rebuilt
wholesale on re-index, so per-entity bookkeeping would either double-count
or need per-entity compensation state; re-applying after a rebuild would
also compound corrections built on already-corrected ranks. The drift is
accepted — the clamped 2% step bounds it. `--entity=` forces a re-apply
manually; the accrual command is idempotent per entity marker, and a
re-run after re-import reconstructs rather than duplicates because import
resets the markers (decision 3).

### 3. Import writes authoritative ranks and resets markers

`words:import-frequency {source}` accepts a local `rank,word` CSV or a
named source. Named sources download to `storage/app/frequency/`
(gitignored) and fix the language:

| Source | Language | Data |
|---|---|---|
| `en-opensubtitles` | en | OpenSubtitles 2018 full list (HermitDave/FrequencyWords, CC BY-SA 4.0) — surface forms, one per line with a count; **rank = line position** |
| `ru-rnc` | ru | Lyashevskaya & Sharoff (2009) RNC lemma dictionary — `Lemma,PoS,Freq(ipm),…` CSV; lemmas aggregated by summed ipm across parts of speech, ranked most-frequent first |

The official `dict.ruslang.ru` host for the RNC file serves a broken TLS
certificate; the pinned mirror URL lives in `config/services.frequency`
and is overridable by env. Both lists are matched onto words by direct
`l_word` equality, set-based through a session temp table (memory-safe at
the OpenSubtitles full-list size of ~2.4M lines), never creating words and
updating every word-class row of a matched headword.

OpenSubtitles lists surface forms, not lemmas — English works acceptably
with direct matching (inflected-form ranks stay close to lemma ranks), but
irregular lemmas ("be") rank only as high as their literal entry. Russian
uses the RNC list precisely because its lemma table avoids the case-form
splitting that would wreck OpenSubtitles ranks for Russian. Aggregating
the English surface forms onto lemmas via the `forms` table is a known,
deliberately deferred upgrade.

After a successful import, `frequency_counted_at` is cleared **for the
imported language's entities only**, so the scheduled accrual re-applies
each entity's pull once against the fresh ranks within minutes — the
corrected state is reconstructed, not doubled.

### 4. Scheduling

`words:accrue-entity-frequency` runs every five minutes with
`withoutOverlapping`, next to `crossword:refresh`. It processes entities
whose marker is null and whose `words_indexed_at` is at least 15 minutes
old (the grace gives the link pass a few sweeps to fill `word_id` before
the one-time pull burns the marker), in id order, bounded by `--limit`.

## Consequences

- Unranked words leave every crossword band by default (previously they
  sat in all of them) — puzzle selection changes immediately after
  migration, even before any import runs.
- Fractional ranks are legal everywhere `frequency` is compared or
  ordered; consumers only use `<=` and `ORDER BY`, so no consumer change
  was needed.
- The user's library gradually outranks the global list for words it
  actually uses; the effect is small by construction (2% per entity) and
  capped at the entity's true position.
