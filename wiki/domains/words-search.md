---
type: Feature
title: Words Search
description: Removed legacy Livewire dictionary word search component (SQLi dead code).
tags: [livewire, legacy, dictionary, removed]
status: deprecated
generated: { by: agent/glm-5.2, at: 2026-08-26T12:00:00Z }
---

# What it was

`App\Livewire\WordsSearch` (Blade view in `resources/views/livewire/`) — a
Livewire word search over the dictionary tables. It was legacy (pre-Inertia UI)
and had **zero references** anywhere in the app, routes, or tests.

# Status

**Removed (2026-08-26, production-launch Phase 1).** `WordsSearch.php:15`
interpolated `$this->search` into a raw `DB::select()` — SQL injection. It was
confirmed dead code (no references) and deleted rather than fixed. The only
remaining Livewire component is now [Crossword](crossword.md). Dictionary UI
work belongs to the Inertia/React stack or Filament admin.
