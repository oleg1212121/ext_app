---
type: Architecture
title: Frontend Architecture
description: The hybrid Inertia/React + Livewire + Alpine frontend, Tailwind 4 CSS-first config, and Vite build.
tags: [frontend, react, inertia, livewire, tailwind]
status: stable
generated: { by: agent:zcode, at: 2026-09-23T14:35:00+03:00 }
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
