# ADR 0096: AI-answer words are map-less and adopt-less — the popup opens instantly and resolves in place

Date: 2026-11-02
Status: Accepted (the gesture is [ADR 0030](0030-lookup-requires-ctrl-click.md)'s, Ctrl-only; the render-time segmentation it looks up through is [ADR 0027](0027-render-time-word-segmentation.md)'s). Amended same-day on user feedback — see the amendment section (toolbar parity: disabled explain toggle + live robot; a loading line while the resolve runs).

## Context

The simulator's AI answer panel rendered the model's assessment as inert
HTML — the committed hover affordance on `#ai_answer_div` had no functional
referent. Making its words Ctrl+clickable (ai-answer-words tickets 01–03)
could not port the text columns' interactivity machinery wholesale,
because that machinery is all **shipped state** the AI answer does not
have: `EntityWordMap` word maps linking each token to a dictionary word at
a known familiarity, display-column descriptors carrying each column's
single language, and a `rowKey` scoping the familiarity ledger. An AI
answer streams in at ask time (rewritten wholesale on every throttled
render), belongs to no entity, and is **linguistically ambiguous** — an
assessment of a translation discusses both columns' languages at once, so
no single language code describes any given word of it.

This ADR records the four structural decisions the feature shipped with.

## Decisions

### 1. Map-less: lookup happens on click, not from shipped state

No word map is computed or shipped for the AI answer. The shared markdown
pipeline gains an off-by-default `wordTokens` option
(`laravel/resources/js/lib/markdown.js`) — the only consumer that opts in
is `useAiStream`, at all three render sites (throttled mid-stream, final,
error keep-partial), so tokens exist while the answer is still streaming —
wrapping every tokenizer word in a focusable `ai-word-token` span carrying
**only the normalized lookup key** (`data-word-key`, the shared
`wordTokenizer.mjs` pipeline: lowercase, marks stripped, ё→е). No word id,
no familiarity, no tinting. Clicking resolves the token against the
dictionary; until that click the answer carries no word identity at all.
No word-event ledger reaches the panel either: there is no `rowKey` and no
sentence id, so no read or lookup events fire from it — opening a popup
from the AI answer is familiarity-free.

Rejected: **building a per-answer word map** (server-side at ask time or
client-side at render) — the text is model-generated per ask and replaced
wholesale by a retry, belongs to no entity whose `entity_words` could map
it, and would be stale or wrong the moment the model revised the answer;
and **pre-resolving every token on render** — a fan of lookups per answer
for tokens the user never clicks, all to decorate spans that open the same
popup anyway.

### 2. Adopt-less: the columns remain the adoption path

The AI panel's popup mount passes **no `language` prop** — and the
missing-card's Ask-AI/adopt gating keys on exactly that prop
(`language?.code`). The icon therefore stays disabled on the AI panel:
**nothing is ever minted into the dictionary from AI-answer text.**
The reason is the ambiguity above: enrichment mints (or adopts) into ONE
language (`WordAiEnrichmentService::resolveOrAdopt` takes a single
`language_code`), and an AI answer's words have no definite one — picking
the learning or the base code arbitrarily would file words under the
wrong language. A user who wants a word from the AI answer in their
dictionary looks the same word up in a column, where the token's language
is definite, and adopts there. The rest of the columns' ledger is
structurally absent from the mount for the same reason: no familiarity
map (the popup opens showing untouched), no explain payload, no progress
recording.

Rejected: **adopt-on-ask with a guessed language** (wrong-language mints,
the exact duplicate hazard the `(l_word, language, class)` uniqueness
guards against) and **a two-ask disambiguation UI** (an interaction the
columns already serve better, at the cost of a second ask flow to
maintain).

### 3. Resolution is ordered learning→base through the popup's `languageCodes` resolve

When an AI-answer token opens the popup, the mount passes the ordered
`languageCodes=[learning, base]` and the popup resolves through
`lib/wordLookup.js`'s sequential `resolveWordId(surface, codes)`: codes
run in list order, **the first non-null word id wins** (short-circuit —
later codes never fire), a miss falls through to the next code, and **any
transport failure collapses the whole sequence to null immediately** — the
same degradation as the single-code contract the module extracted
(`lookupWordId`, the old component helper byte for byte): the plain
missing card, never an error state, and a broken transport never hammers
the remaining codes. Learning first because the learner's side is the
side whose words the user is training; base as the fallback for the
language names and grammar terms the answer quotes.

The codes are **the ask's, not the page's**: `useAiStream` snapshots the
ask payload's language codes into `answerLanguages` the moment a stream
starts, so a side flip after the ask changes the columns but never the
resolution of the already-displayed answer, and Ask again re-captures by
streaming the stored payload through the same snapshot line. An answer
that never asked (or asked with no usable codes) resolves against an
empty list — the popup stays exactly like a column-less mount: missing
card, no adoption.

The reader and the simulator text columns pass no list and keep the
single-language resolve; the ordered variant is purely additive
(`languageCodes` keyed as a joined string so an inline-array prop cannot
re-trigger the resolve effect).

### 4. The popup opens instantly and resolves in place

The popup mounts **immediately on Ctrl+click** — Ctrl-only per
[ADR 0030](0030-lookup-requires-ctrl-click.md) (Cmd neither required nor
sufficient; Ctrl+Enter/Ctrl+Space keyboard parity; Ctrl+click inside a
link prevented from navigating) — in a `document.body` portal, in
**missing mode** (`wordId={null}`) from the RAW token surface (the span's
`textContent`; the tokenizer's segment text carries no edge punctuation),
positioned from the span's rect, at the page's popup font
([ADR 0031](0031-popup-typography-follows-page-font.md)).
Zero network precedes the paint: the missing card renders at once, and
the ordered resolve then runs **in place** — a hit feeds the popup's
`lookedUpId`, the existing detail fetch swaps the full dictionary view
into the open card, and a miss or transport failure just leaves the
missing card. The handling is **event delegation on the `#ai_answer_div`
container** (click + keydown props on that one div; tokens found via
`event.target.closest('[data-word-key]')`), because streaming rewrites
the answer HTML wholesale — a per-token handler could never survive
mid-stream; the pure open-or-not and codes-derivation decisions live in
`lib/aiAnswerTokens.mjs` (Node-tested).

In the columns a mapped token opens straight to its dictionary view (the
word id rides the map). Here **every** open starts missing and resolves —
the missing card is the instant-open state, not a dead end.

Rejected: **gating the open on the resolve** — a network round-trip
before anything renders, on the surface whose defining property is that
its text arrived over a stream; and **a loading placeholder card** in
place of the missing card — same latency cost, plus a fourth popup state
to style and test for what is a sub-second swap.

## Amendment (2026-11-02, user feedback on the shipped branch)

Three corrections to the posture above, none touching its core (map-less,
adopt-less, instant open):

### 5. Toolbar parity: the explain config rides along — toggle disabled, robot live

The mount now passes the columns' explain config
(`{enabled, modelKey, modelLabel, followsAnswer, answerLabel}` — the same
server payload the text columns gate per side, threaded
Bilinguals → AI → WordPopup) **without a sentence id**. `PopupContent`
renders the Explanation toggle **disabled** on exactly that absence
(`explainDisabled = explainProps.sentenceId == null`; grey through the
shared toolbar class's `disabled:opacity-40`, non-responsive as a
disabled button) and the robot (Models used) **live** — it reads
`answerLabel`/`modelLabel`/`followsAnswer` from the config and shows the
answer + explanation models, matching the panel header's own robot.
Decision 2's adopt-less stance is untouched: the `language` prop stays
structurally absent, Ask-AI/adopt stay disabled, nothing is minted.

Rejected: **a working explain toggle with an answer-context mode** (a new
context_text mode on `/ai/word-explain` — real machinery for a first
pass; the grey toggle says honestly that no sentence context exists to
ask about); and **the old posture verbatim** (a toolbar missing two of
the columns' six icons read as a broken popup, which is what the user
reported).

### 6. The missing card holds a loading line while the resolve runs

Decision 4's rejection of a loading state is **superseded in part**: the
open stays instant and the card structure unchanged (title + toolbar
paint at once), but while the on-open resolve is in flight the missing
card's body renders the shared `word.loading` line instead of the
not-in-dictionary note (`MissingPopupContent`'s `resolving` prop, fed
`lookupBusy`). The note is a **verdict** — "this word isn't in the
dictionary yet" — and pronouncing it before the resolve answered read as
an empty popup that later swapped itself; on a miss the note settles
back in, on a hit the existing fetch takes over (its own loading line,
then the dictionary view). The same fix covers the columns' ticket-07
missing mode, which shared the premature flash.

## Consequences

- **The hover affordance finally means something**: the pointer cursor
  and hover fill long committed on `#ai_answer_div` had decorated inert
  text; with Ctrl+click wired they advertise a working popup (and focused
  tokens carry the columns' 2px focus ring).
- **Nothing is ever minted from AI-answer text** — the dictionary grows
  only through the columns' adopt-on-ask, where language is definite.
- **Every AI-answer popup costs one to two small lookup POSTs** (one per
  code until a hit) on click only — never on render, never on stream.
- **A resolved token's card is the ordinary dictionary card** — the same
  payload, Anki send, and re-ask affordances as a column popup for the
  resolved word; only the ledger plumbing is absent.
- **The posture generalizes**: any future surface that renders AI text
  (chat, per-sentence explanations) can adopt the same map-less,
  adopt-less stance through the same seams — the `renderMarkdown`
  `wordTokens` option, `aiAnswerTokens.mjs`, and `wordLookup.js`.

## Amendment (2026-11-05, word-popup-overhaul tickets 02 + 05)

Two corrections recorded by the word-popup-overhaul spec; the core
posture (map-less, instant open, in-place resolve) stands.

### 7. The adopt-less rule is reversed for this surface — the ask's learning-first language un-suppresses Ask-AI

Decision 2's blanket refusal is **superseded**: the AI panel's popup
mount now passes ONE `language` descriptor —
`answerAskLanguage(answerLanguages)` in `lib/aiAnswerTokens.mjs`, the
**first code of the snapshotted resolution order** (the ask's learning
language, base fallback — the same learning-first priority decision 3
resolves with), `{code}`-shaped, null when the ask carried no usable
code. The missing-card's Ask-AI gating keys on exactly that descriptor
(`language?.code`), so the icon goes live for approved admins (the flow
is admin-only and queued since word-popup-overhaul ticket 06 — ADR 0082's
amendment): asking about an unknown answer word mints (or adopts onto an
existing row — adoption still wins, never duplicates) into that language
through the existing adopt-on-ask path (`resolveOrAdopt`, ADR 0081's
lookup key), and the card populates on the popup's next open — the
`{queued: true}` dispatch swaps nothing live. Decision 2's ambiguity
hazard is met not by refusing adoption but by **pinning the language to
the ask's own learning-first code** — the one definite language an AI
answer's words can claim, and the one the ordered resolve already looks
under first, so a mint lands where the resolve looks.

The rest of decision 2 stands unchanged: the mount is still **map-less**
(no familiarity map, tokens never tint) and carries no sentence-bound
ledger — no read events (no sentence identity exists). Its one
familiarity interaction is ADR 0028's popup-open penalty as amended,
posted once per popup instance when the resolve lands a hit; a token
that stays missing costs nothing.

Rejected: **keeping adopt-less** (a user who wants an answer word in
their dictionary was told to re-find it in a column — on the panel
where admins actually read unknown words this read as a broken popup,
and the ask is the panel's only route from missing to dictionary);
**guessed or user-picked languages per ask** (the wrong-language mints
decision 2 rejected still lurk there — the snapshot's learning-first
code needs no new UI and cannot contradict the resolve); and **a
side-aware two-column mint** (a word filed under both codes doubles the
dictionary on one ask).

### 8. The hover affordance is underline-only — the panel paints no background

The Consequences' "hover fill" bullet and the original commit's
background-on-hover rules are stale since word-popup-overhaul ticket 02:
the vellum `:hover` background block (light + `.dark`, every
text-bearing element under `#ai_answer_div`) is deleted from
`public/css/simulator.css`. The hovered word token's underline (the
columns' `.word-token:hover` affordance scoped to the panel) is the
panel's **whole** hover affordance; plain (non-token) answer text gives
only the pointer cursor; the focused token's 2px accent ring is
untouched.
