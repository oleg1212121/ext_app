---
type: Convention
title: Design System
description: Visual language for the app — colors, type, layout, and signature patterns to keep new pages consistent with the Bilinguals simulator redesign.
tags: [design, frontend, ui, tailwind, tokens]
status: stable
generated: { by: agent:ox-alpha, at: 2026-08-23T20:25:00Z }
---

# Design System

Shared visual language for the app. New pages should derive their look from this,
not from a new palette per page. The canonical implementation of this system is
the Bilinguals simulator (`/bilinguals/en/ru/simulator`).

# Subject & voice

The app is a **bilingual (EN/RU) language-learning workbench** for serious
learners and amateur translators. Voice is plain, conversational, no marketing
words. Copy names things by what the reader controls and what happens when they
use a control ("Load", "Ask", "Ask again", "Retry"). Empty states invite action;
errors state what went wrong and how to recover, in the interface's voice, not
a person's.

# Tokens

All tokens are defined in `laravel/resources/css/app.css` inside the `@theme`
block, so they emit as CSS variables usable both as Tailwind utilities
(`bg-[var(--wbench-paper)]`) and in plain CSS.

## Color — cold paper + ultramarine

| Token | Light | Dark | Use |
|-------|-------|------|-----|
| `--wbench-paper` | `#FBFAF8` | `#111111` | Page canvas |
| `--wbench-paper-deep` | `#F2F1ED` | `#1A1A1A` | Toolbar, panel recess, sticky headers |
| `--wbench-ink` | `#0D0D0F` | `#ECEAE6` | Primary text, headings |
| `--wbench-ink-soft` | `#54555A` | `#9A9890` | Secondary copy, labels, eyebrows |
| `--wbench-rule` | `#DAD8D1` | `#2D2D2D` | Hairlines, dividers, borders, drag handles |
| `--wbench-accent` | `#1F3DDB` | `#5A78FF` | Focus ring, active state, `em`, "Ask", link |
| `--wbench-accent-ink` | `#15296F` | — | Accent-on-hover (light) |
| `--wbench-danger` | `#B1251C` | `#E66B62` | Errors only |
| `--wbench-emphasis` | `#B0451E` | `#E0664A` | Bold emphasis (`b`, `strong`), `mark` tint |

**Accent discipline:** the ultramarine accent does *one* job per surface — focus
ring, active marker, the one italic emphasis run (`em`), the one CTA. Bold
emphasis (`strong`, `b`) is a separate channel, painted in `--wbench-emphasis`
(vermilion). Do not paint chrome with either. If a third accent appears to be
needed, re-read the layout; an accent is a signal, not decoration.

> The legacy `--color-vellum/*` token family (warm cream + vermilion) remains in
> `app.css` for backward compatibility with other pages and Blade/Alpine views.
> New Inertia/React pages should use `--wbench-*`, not `--color-*`. The crossword
workbench (`/crossword-react/{lang}`) is now on `--wbench-*` too, with a local
`.xword-edge` rule in `resources/css/crossword.css` that mirrors `.ribbon-mark`
but is retinted to `--wbench-accent` so it does not touch the global
vermilion `.ribbon-mark` shared with the simulator and Reader.

## Type — three roles, each full Latin + Cyrillic

Loaded in `laravel/resources/views/app.blade.php` from Google Fonts.

| Role | Family | Tailwind utility | Use |
|------|--------|-----------------|-----|
| Reading body | **Source Serif 4** | `font-[var(--font-wbench-serif)]` | Long-form content: text rows, AI prose, translation textarea content |
| Chrome | **IBM Plex Sans** | `font-[var(--font-wbench-sans)]` | Toolbar, buttons, dropdowns, page chrome, body fallback |
| Marginalia / data | **JetBrains Mono** | `font-[var(--font-wbench-mono)]` | Eyebrows, row numbers, column markers (`EN # RU`), counters, `§` stamp |

| Token | Light | Dark |
|-------|-------|------|
| `--font-wbench-serif` | `'Source Serif 4', 'Fraunces', Georgia, serif` | — |
| `--font-wbench-sans` | `'IBM Plex Sans', 'Figtree', system-ui, sans-serif` | — |
| `--font-wbench-mono` | `'JetBrains Mono', ui-monospace, Menlo, monospace` | — |

Pair deliberately; do not reach for the same families on every page. The serif
carries the content's personality; the sans is invisible infrastructure; the
mono is the only flourish and it signals "data / instrument".

## Compact scale

| Element | Value |
|---------|-------|
| Default reading font | 22px (simulator; calibrate per surface) |
| Toolbar vertical padding | `py-2` |
| Table row vertical padding | `py-2` |
| Eyebrow / mono caps | `text-[10px] tracking-[0.24em] uppercase` |
| Hairline weight | `border` (1px) on `--wbench-rule` |
| Active marker | 2px accent edge (see `.ribbon-mark` in `app.css`) |

Tighter than the app's original default (was 30px reading, `py-3` rows). Match
this on working surfaces; relax it only where a surface is genuinely an
entrance / marketing page.

# Layout principles

## Structure is information

Structural devices (numbers, eyebrows, dividers, hairlines) must encode
something true about the content — not decorate it. Mono row numbers (`01`,
`02`) are right because simulator rows are an ordered sequence; do not paste
`01 / 02 / 03` onto a feature grid that has no inherent order. Question: *does
the order carry information the reader needs?* If not, omit the numbers.

## Hairlines over chrome

Prefer 1px `--wbench-rule` hairlines to boxed panels and shadows. Drag handles
are hairlines that flip to accent on `hover`/`active` — not grey bars. See
`public/css/simulator.css` for the pattern.

## One signature per page

Each working surface should have one memorable element that embodies the brief.
For the simulator it is the **AI Response rail**: deliberately designed empty,
loading (one filling accent underline + mono `WORKING`), answer (scoped prose),
and error (real message + `Retry`) states. Spend the visual budget on the
panel that takes request responses; keep everything around it quiet.

## Request-replaceable surfaces have four states

Any region whose content comes from a request (table body, AI panel, paginated
list, search results) must implement all four, in the interface's voice:

| State | Treatment |
|-------|-----------|
| **Empty (no request yet)** | Serif sentence inviting the next action; mono eyebrow drops the drop-label (`NO TEXT LOADED`) |
| **Loading (request pending)** | One orchestrated moment, not scattered shimmer — a single filling accent rule or ping dot with a mono `WORKING` label; reduced-motion stays static |
| **Answer / data** | Scoped `.ai-prose`-style treatment; accent `em`, emphasis-red `strong`, mono-caps headings (`1.15em`, scale with the reading font); **gloss-run hover** — text-bearing elements get a pointer cursor and a soft gray shadow cast just below the glyphs on `:hover`, scoped to `#ai_answer_div` (no background fills) |
| **Error** | Serif line in `--wbench-danger` with the real failure message + a `Retry` control that re-sends the original payload |

No region returns blank `''` HTML. No region displays fake skeleton content when
nothing has loaded.

## Density matches the vision

Compact density (above) is the default for working surfaces. Elegance is
executing the chosen density well — hairline alignment, tabular numerals for
counters, consistent spacing, visible keyboard focus, reduced-motion respected.
Cut anything that does not serve the reader's task.

## Motion is restrained

One orchestrated moment per page (the AI loader rule, a hover edge reveal). Do
not stack transitions across every element. `prefers-reduced-motion` must defang
every animation; reduced motion is not a graceful-degradation afterthought.

# Reusable patterns already in the codebase

| Pattern | Where | Notes |
|---------|-------|-------|
| Scoped `--wbench-*` tokens | `resources/css/app.css` `@theme` | Emit as CSS vars; usable from Tailwind arbitrary-value utilities |
| `.ribbon-mark` active row edge | `resources/css/app.css` | Width 0 → 3px on `group:hover` / `focus-within`; retint to `--wbench-accent` |
| `.ai-prose` markdown styling | `public/css/simulator.css` | For AI / markdown-rendered answer divs only; accent `em`/`i`, emphasis-red `b`/`strong`, plus `mark`/`u`/`del`/`code`/`blockquote` treatments; gloss-run `:hover` affordance (pointer + accent text-shadow below) scoped to `#ai_answer_div` |
| `.ai-loader-rule` filling underline | `public/css/simulator.css` | Keyframe `ai-rule-fill`, 900ms cubic ease-out; `motion-safe`-gated |
| `.resizeable_element` font-scaling hook | `public/css/simulator.css` + `Bilinguals.jsx::updateResizeableFontStyles` | For surfaces that support user font-size control |
| Segmented tab strip with `Underline` | `Bilinguals.jsx` | 2px accent underline, `scale-x` reveal, shared `tabClass()` helper |
| Drag handles (vertical / horizontal) | `public/css/simulator.css` | Hairlines that flip to accent on hover |
| Empty / loading / answer / error states | `AI.jsx`, `TextContent.jsx` | The four-state contract for request-replaceable surfaces |

# Dark mode

All `--wbench-*` tokens have a `*-night` pair. Apply with `dark:` variants
(`bg-[var(--wbench-paper)] dark:bg-[var(--wbench-paper-night)]`). The project's
dark variant is configured as `@variant dark (&:where(.dark, .dark *))` in
`app.css` — class-based, toggled by a `.dark` ancestor.

# Files a new page should read first

* `laravel/resources/css/app.css` — the `@theme` block (tokens, fonts)
* `laravel/public/css/simulator.css` — `.ai-prose`, drag handles, `.ribbon-mark` overrides
* `laravel/resources/js/Pages/Bilinguals/Bilinguals.jsx` — toolbar, tab strip, state plumbing
* `laravel/resources/js/Pages/Bilinguals/Components/AI.jsx` — the four-state signature surface