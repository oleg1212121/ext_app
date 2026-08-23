# Admin bypass via Gate::before

Authorization uses a single `Gate::before` hook in `AppServiceProvider::boot`
that returns `true` for any approved admin, so admins pass every ability
automatically; ability definitions (gates and, later, policies) only ever encode
the non-admin rule.

**Context.** The app had no policy/gate layer: access was a binary
`auth`+`approved` route group plus a Filament `canAccessPanel` check. Adding
per-ability authorization from scratch meant choosing where role checks live.
With only two roles (`user`, `admin`), every new ability would otherwise need a
hand-written `if (auth()->user()->isAdmin())` clause, which is easy to forget
and silently leaves a new ability admin-blocked.

**Decision.** Approved admins are auto-granted by `Gate::before`; the first
ability, `accessAdminPanel`, is defined explicitly as `isAdmin() &&
is_approved` and is the single source of truth for both `User::canAccessPanel`
and the frontend's `auth.can` map. The `before` hook also requires
`is_approved`, matching `canAccessPanel` semantics and closing the edge case
where an unapproved admin would pass checks outside the route middleware.

**Why.** Safe-by-default: a new ability is automatically admin-allowed, so
forgetting the admin clause is never a security gap. It is a few lines, but
every future policy silently depends on the hook's existence and its
`is_approved` coupling, which is why it is recorded here rather than left as
obvious code.
