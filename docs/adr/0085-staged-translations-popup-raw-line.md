# ADR 0085: The popup surfaces a word's staged translations as a language-less raw line; Anki keeps linked translations only

Date: 2026-10-20
Status: Accepted

Implements popup-provenance ticket 02. Complements
[ADR 0084](0084-word-source-provenance.md) (word-level provenance) in the
same feature: the other half of "where did this word's material come from"
is the material itself that never finished becoming links — the staged
translation candidates — which until now no end user could ever see.

## Context

`words.translations` holds the flat list of raw candidate strings staged by
the importers and the popup auto-fetch (`App\Support\StagedTranslations`
semantics: union-merge in, prune-on-link out, empty stores as null). It is
the link pass's working data; nothing in the product surfaced it — the popup
payload (`WordPopupPayload`) never read the column, so a word whose
candidates are all still unlinked showed only the calm "no translations
yet" note even while carrying a dozen raw glosses. The reader had no way to
tell "the pipeline found nothing" from "the pipeline found candidates it
has not linked yet", and no way to see what a re-link pass would have to
work with.

## Decision

1. **The shared payload carries a per-entry `staged_translations`
   list.** `WordPopupPayload::entry()` adds `staged_translations` beside
   `translations` — the entry word's staged strings verbatim, language-less
   (the column records no language metadata and none is invented; the
   staged candidates are "whatever the pipeline collected for this word"),
   in stored order. The ONE assembler serves both `GET /words/{word}` and
   `POST /ai/word-enrich`, so the two shapes cannot drift (ADR 0082
   decision 4).
2. **Deduped case-insensitively against the linked line's display words,
   and within itself.** A staged string that only restates a word the
   linked translations line already shows (any casing) is dropped, and a
   case twin of an earlier staged string keeps its first occurrence — the
   raw line adds information, never repeats it. The list is capped at the
   payload's existing `TRANSLATION_LIMIT` (100), the same ceiling the
   linked line has.
3. **Rendered as a second collapsible line directly under the linked green
   translations line, in the amber token.** The linked line keeps its
   preview cap of 8 and "+N more…" expander; the raw line has its own cap
   of 8 and its own expander (`word-popup-raw-translations` /
   `-raw-translations-more` classes, per-line expanded state), in amber
   (`--color-amber` / `--color-amber-night` — the staged/raw token from
   popup-provenance ticket 01) with the tiny muted localized
   `word.raw_translations` label ("raw:") in front. The line renders only
   when the entry has staged strings — hidden entirely otherwise — and the
   calm "no translations yet" placeholder shows only when linked AND
   staged are both empty: a word carrying candidates never reads as empty.
4. **Popup-only: the Anki note fields and the card Body keep rendering
   linked translations only.** `lib/ankiTemplate.js` reads the linked
   `entry.translations` (language-bearing objects) and is unchanged; the
   staged strings — working data, not curated content — must not leak into
   the user's deck.

## Consequences

- A staged-only word (nothing linked yet) now shows its raw candidates
  instead of the placeholder — the reader sees the pipeline's working set
  at a glance, and the popup is honest about "found, not yet linked".
- The staged strings render as-is: no stress-mark or casing normalization
  happens at display time (the writers already normalized at storage), and
  no language labels are shown — if the staged JSON ever grows language
  metadata, this line is where it would surface.
- The linked line's placeholder logic now depends on a second payload
  field; both fields ride every entry of every payload, so the frontend can
  read them unconditionally.
- Anki exports are deliberately unaffected: a card built before this ADR
  and one built after are byte-identical.
- Tests: Pest pins the payload on both endpoints (dedupe, empty shape,
  cap, and the ask-AI response parity); vitest source pins pin the raw
  line's rendering, absence, placeholder logic, amber token, and the
  ankiTemplate exclusion.
