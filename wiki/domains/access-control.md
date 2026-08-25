---
type: Access Control
title: Access Control
description: Role- and approval-based authorization for users, the admin bypass, and the last-admin invariant.
tags: [auth, authorization, roles, admin, approval]
status: stable
stale_after: 2026-10-23
generated: { by: human:alex, at: 2026-08-25T14:00:00Z }
verified: { by: human:alex, at: 2026-08-25T14:00:00Z }
sources:
   - id: app-provider
     resource: laravel/app/Providers/AppServiceProvider.php
     title: Gate::before admin bypass + accessAdminPanel gate
   - id: user-model
     resource: laravel/app/Models/User.php
     title: Role/approval helpers + last-admin invariant
   - id: inertia-share
     resource: laravel/app/Http/Middleware/HandleInertiaRequests.php
     title: auth.can ability map shipped to the frontend
   - id: user-resource
     resource: laravel/app/Filament/Resources/UserResource.php
     title: Panel-level self/last-admin guards
   - id: navbar
     resource: laravel/resources/js/Components/NavBar.jsx
     title: Frontend consumes the can() map instead of role strings
   - id: entity-access
     resource: laravel/app/Classes/EntityAccessService.php
     title: canRead / canReadMatch / grant / readable queries
   - id: entity-models
     resource: laravel/app/Models/EnEntity.php
     title: is_restricted flag + grantedUsers relation + en_entity_user pivot
---

# Purpose

Decide what an authenticated user is allowed to do. Two independent facts drive
every decision: their **Role** and their **Approved** state. Everything outside
the admin panel is role-gated through an **Admin bypass** so that adding a new
ability is safe-by-default for admins, and a **last-admin invariant** guarantees
the platform can never lose all administrator access.

# Model

A user has exactly one **Role** (`user` or `admin`) and a boolean **Approved**
flag. Authentication (who you are) is separate from authorization (what you may
do), which is expressed as **Abilities** — named checks such as
`accessAdminPanel`. The frontend receives the subset of abilities the current
user holds via the `auth.can` map rather than raw role strings, so UI gating
mirrors the server-side checks instead of re-implementing them.

# Rules

- **Approved is a prerequisite for any ability.** An unapproved user is bounced
  to the pending-approval screen by the `approved` route middleware and fails
  every ability check (the admin bypass also requires `is_approved`).
- **Admin bypass.** An approved admin passes every ability automatically via a
  `Gate::before` hook. Ability definitions therefore only ever encode the
  non-admin rule; they never repeat `if admin`.
- **Last-admin invariant.** At least one approved admin must always exist.
  Removing the final approved admin (by demotion, unapproval, or deletion) is
  rejected, and an admin may never remove their own admin access.
- **Filament panel.** The panel is reachable only by an approved admin; the
  `accessAdminPanel` ability is the single source of truth for both the panel's
  `canAccessPanel` contract and the frontend's admin link.
- **Entity-access enforcers.** `EntityAccessService` gates reads in
  `EntityController`, `ReaderController`, `SimulatorController`,
  `AlignmentController` (pair list + detail), and `AlignmentEditorController`
  (every read and mutation endpoint: rows / unmatched / needs-review reads, plus
  store / approve / destroy row, store / update / unlink / destroy / move
  sentence). Both alignment controllers run
  `abort_unless($access->canReadMatch(auth()->user(), $entityMatch), 403)` as the
  first statement of each endpoint, before any DB work, so non-granted users get
  403 (never 404) regardless of whether a nested id is valid.
- **Entity-edit enforcer (ADR 0015).** `EntityAccessService::canEdit` mirrors
  `canRead` (admin bypass; Restricted → grantees; Public → any approved user)
  and gates `EntityController::edit`, `update`, `sentences`, `storeSentence`,
  `updateSentence`, `destroySentence`, `reorderSentences` via
  `abort_unless($access->canEdit(...), 403)` before any DB work.

# Out of scope

Per-record ownership (a `user_id` on a content model denoting authorship) is
still absent: entities carry no `created_by`. What *does* exist now is
per-record **access** — see the [Entity Access](../../CONTEXT.md#entity-access-context)
context and ADRs [0013](../../docs/adr/0013-default-restricted-uploads-and-per-entity-grants.md)
/ [0014](../../docs/adr/0014-per-entity-grants-require-both-sides-for-simulator.md). Every
upload defaults to a Restricted entity; the uploader gets an Access grant, and a
Signature match links the uploader to the existing entity instead of creating a
duplicate. Content remains otherwise collaborative — anyone can read a Public
entity, and an admin may read everything via the Admin bypass.
