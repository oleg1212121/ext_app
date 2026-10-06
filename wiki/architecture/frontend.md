---
type: Architecture
title: Frontend Architecture
description: The hybrid Inertia/React + Livewire + Alpine frontend, Tailwind 4 CSS-first config, Vite build, JS module layering (lib/, hooks/, page-local), and vitest for pure logic.
tags: [frontend, react, inertia, livewire, tailwind, vitest]
status: stable
generated: { by: agent:zcode, at: 2026-10-05T20:05:00+03:00 }
sources:
  - id: package
    resource: laravel/package.json
    title: NPM manifest
  - id: css
    resource: laravel/resources/css/app.css
    title: Tailwind 4 CSS-first configuration
  - id: vitest-config
    resource: laravel/vitest.config.js
    title: vitest standalone config (pure-logic tests, no vite plugin)
  - id: adr-vitest
    resource: docs/adr/0066-vitest-for-pure-logic-js-tests.md
    title: ADR 0066 — vitest for pure-logic JS tests
---

# Three UI stacks, one app

| Stack | Where | Used for |
|-------|-------|----------|
| **Inertia + React 19 (JSX)** | `resources/js/Pages/` | **Primary.** All new pages. Bilinguals simulator, Reader, Entities/Alignments, Dashboard, Welcome, auth pages |
| **Livewire 4** | `app/Livewire/`, `resources/views/livewire/` | Filament admin pages and the Filament alignment editor |
| **Alpine.js 3** | started in `resources/js/app.jsx`, only on non-Inertia pages | Lightweight interactivity in Blade (auth-page nav dropdowns) |

**Rule: new pages are Inertia/React (JSX).** Do not add new Livewire
components; Livewire remains only inside Filament.

Alpine starts only when the page has no Inertia `#app` root (Blade auth
pages). On React pages its global MutationObserver would re-walk every node
React touches — pure overhead there and a freeze ingredient on the reader
(see wiki/log.md, 2026-09-23).

UI kit: `flowbite-react` components (see `resources/js/Pages/` for usage).

# Tailwind CSS 4 — CSS-first config

Configuration lives in `resources/css/app.css` via directives, **not** in a JS
config file:

```css
@import "tailwindcss";
@import "flowbite-react/plugin/tailwindcss";
@source "../../.flowbite-react/class-list.json";
@plugin "@tailwindcss/forms";
@source '../**/*.blade.php';
@source '../**/*.js';
@variant dark (&:where(.dark, .dark *));
@theme { --font-sans: 'Figtree', ...; }
```

`tailwind.config.js` is intentionally minimal — **do not add JS-based Tailwind
config**. Dark mode uses the `.dark` class variant; pages that support dark
mode use `dark:` utilities.

# Build & dev

* Vite 7 with `laravel-vite-plugin` and `@vitejs/plugin-react`.
* `npm run dev` (inside container) — dev server on port 8002.
* `npm run build` — production build.
* `composer run dev` starts server + queue + logs + vite together.
* If a page errors with "Unable to locate file in Vite manifest", assets were
  not built — run `npm run build`.

# Inertia conventions

* Controllers return `Inertia::render('Page/Name', [...props])`.
* POST endpoints consumed by React return `JsonResponse`, not Inertia
  redirects (see `SimulatorController::text()`, `askAi()`).

# JS module layering

De-facto convention, now documented (follow it for new code):

* **`resources/js/lib/`** — framework-free modules the hooks and pages
  compose. Storage wrappers (`simulatorPosition`, `readingPosition`,
  `sideFlip` — private `KEY`, exported `loadX`/`saveX` pair, best-effort
  try/catch), pure logic (`readingRows.mjs`, `stressMarks.mjs`,
  `sseStream.mjs`, `simulatorText.mjs`, `aiAsk.mjs`), and browser/network
  helpers (`http.js`, `wordFamiliarity.js`). The `.mjs` extension marks
  modules loadable outside the app bundle (Node/CLI/vitest) — keep pure
  logic there so vitest can pin it.
* **`resources/js/hooks/`** — shared React hooks with an ADR-citing header
  comment: `useSideFlip` (ADR 0037), `useUiSettingsAutosave` (ADR 0024),
  `useDragResize` (panel drag mechanics shared by the simulator's AI panel
  and workplace and the crossword right panel). Hooks never touch
  localStorage directly — they import the load/save pair from `lib/`.
* **Page-local hooks** sit next to their page when only that page uses them
  (`Pages/Bilinguals/useSimulatorText.js`, `useAiStream.js`,
  `useAssessmentQuestion.js`; `Pages/Crossword/useCrossword.js`): the
  simulator page is layout + toolbar + wiring over its three engines, and
  each engine's state (`textPending`, `aiPending`) stays inside it.
* No path aliases — everything is relative imports; `.js` for app-bundle-only
  modules, `.jsx` for components.

# Pure-logic JS tests (vitest)

`npm run test` (= `vitest run`) executes `resources/js/**/*.test.{js,mjs}`
in Node — pure `lib/` modules only, colocated next to the code they pin.
No jsdom and no React testing: components and hooks are wiring, covered by
the Pest feature suite and manual verification (ADR 0066). `vitest.config.js`
is standalone and must not load the laravel-vite plugin. CI runs it as the
`frontend` job in `tests.yml`; `composer run test` deliberately does not
chain it. Invoke via `docker exec ext_app_laravel npm run test`.
