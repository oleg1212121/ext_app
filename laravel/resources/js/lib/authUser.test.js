import {describe, expect, it} from 'vitest';
import {isApprovedAdmin} from './authUser';

// word-popup-overhaul ticket 06 — ask-AI is an approved-admin capability
// (the ask spends the asker's own API-key tokens), extracted here from the
// two verbatim-duplicated derivations in WordPopup.jsx and the profile's
// PopupsSettings.jsx so both surfaces — live popup and preview — read one
// gate off the shared auth user.

describe('isApprovedAdmin', () => {
    it('an approved admin passes', () => {
        expect(isApprovedAdmin({role: 'admin', is_approved: true})).toBe(true);
    });

    it('an unapproved admin fails — approval is part of the gate', () => {
        expect(isApprovedAdmin({role: 'admin', is_approved: false})).toBe(false);
    });

    it('an approved non-admin fails', () => {
        expect(isApprovedAdmin({role: 'user', is_approved: true})).toBe(false);
    });

    it('a truthy-but-not-true flag never passes — both facts are strict', () => {
        expect(isApprovedAdmin({role: 'admin', is_approved: 1})).toBe(false);
        expect(isApprovedAdmin({role: 'administrator', is_approved: true})).toBe(false);
    });

    it('a missing or anonymous user fails', () => {
        expect(isApprovedAdmin(null)).toBe(false);
        expect(isApprovedAdmin(undefined)).toBe(false);
    });

    it('a user without the flags fails', () => {
        expect(isApprovedAdmin({})).toBe(false);
    });
});
