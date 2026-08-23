---
type: Access Control
title: Access Control
description: Role- and approval-based authorization for users, the admin bypass, and the last-admin invariant.
tags: [auth, authorization, roles, admin, approval]
status: stable
stale_after: 2026-10-23
generated: { by: human:alex, at: 2026-08-23T23:59:00Z }
verified: { by: human:alex, at: 2026-08-23T23:59:00Z }
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

# Out of scope

Per-record ownership (a user owning a model they created) does not yet exist:
no domain model carries a `user_id`, and all content is collaborative/curated.
Ownership policies belong to a future change once a concrete user-owned feature
appears.
