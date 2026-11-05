/**
 * Ask-AI is an approved-admin capability (word-popup-overhaul ticket 06):
 * the ask spends the asker's own API-key tokens, so the sparkles icon and
 * every surface gating it — the word popup's toolbar, the profile's popup
 * preview — read this one gate off the shared auth user. Strict `=== true`
 * on both facts: a truthy-but-not-true flag (a numeric 1, a lookalike role
 * string) never passes.
 */
export function isApprovedAdmin(user) {
    return user?.role === 'admin' && user?.is_approved === true;
}
