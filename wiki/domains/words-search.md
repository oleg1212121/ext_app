---
type: Feature
title: Words Search
description: Legacy Livewire dictionary word search component.
tags: [livewire, legacy, dictionary]
status: stable
generated: { by: agent/kimi-k3, at: 2026-07-26T12:00:00Z }
sources:
  - id: component
    resource: laravel/app/Livewire/WordsSearch.php
    title: WordsSearch Livewire component
---

# What it is

`App\Livewire\WordsSearch` (Blade view in `resources/views/livewire/`) — a
Livewire word search over the dictionary tables. One of only two Livewire
components left (the other is [Crossword](crossword.md)).

# Status

Legacy. It is not linked from the main `routes/web.php` flow directly; it is
rendered inside Blade views that remain from the pre-Inertia UI. Do not build
new features on it — dictionary UI work belongs to the Inertia/React stack or
Filament admin.
