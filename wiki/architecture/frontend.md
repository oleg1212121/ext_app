---
type: Architecture
title: Frontend Architecture
description: The hybrid Inertia/React + Livewire + Alpine frontend, Tailwind 4 CSS-first config, and Vite build.
tags: [frontend, react, inertia, livewire, tailwind]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: package
    resource: laravel/package.json
    title: NPM manifest
  - id: css
    resource: laravel/resources/css/app.css
    title: Tailwind 4 CSS-first configuration
---

# Three UI stacks, one app

| Stack | Where | Used for |
|-------|-------|----------|
| **Inertia + React 19 (JSX)** | `resources/js/Pages/` | **Primary.** All new pages. Bilinguals simulator, Reader, React crossword, Dashboard, Welcome, auth pages |
| **Livewire 4** | `app/Livewire/`, `resources/views/livewire/` | Legacy only: `Crossword` and `WordsSearch` components |
| **Alpine.js 3** | loaded globally in `resources/js/app.jsx` | Lightweight interactivity in Blade |

**Rule: new pages are Inertia/React (JSX).** Do not add new Livewire
components; Livewire is kept only for the crossword/words-search legacy.

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
* The React crossword has its own API layer (`resources/js/Pages/Crossword/api.js`).
