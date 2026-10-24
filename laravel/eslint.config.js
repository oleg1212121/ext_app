// Flat ESLint config (eslint 10). Goal: static correctness, NOT style —
// no-undef / undefined imports / React-hook misuse. The motivating bug: a
// missing `deckNames` import in lib/ankiSync.js sailed through `vite build`
// (anki-connection ticket 03) and only a manual Node smoke caught it. Vite
// does no static resolution of what an identifier refers to at runtime; this
// config is the guard. Style-opinionated rules stay off/warn (noted inline) —
// Pint owns PHP formatting, nobody owns JS formatting by design.
import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import globals from 'globals';

export default [
    // Third-party and generated output — never linted, even when ESLint is
    // pointed at a wider directory than resources/js.
    {
        ignores: ['node_modules/**', 'vendor/**', 'public/**'],
    },

    {
        files: ['resources/js/**/*.{js,mjs,jsx}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            parserOptions: {ecmaFeatures: {jsx: true}},
            // Browser globals for the app surface (Pages/Components run in
            // the browser; console/fetch/URL also exist in Node 20+ for the
            // lib modules that vitest exercises).
            globals: {...globals.browser},
        },
        rules: {
            // Core recommended set: correctness rules only (no-undef,
            // no-dupe-keys, no-unreachable, eqeqeq-adjacent traps, ...).
            ...js.configs.recommended.rules,

            // Dead imports/vars are lint noise, not style — but enforcing as
            // an error would demand mass edits; warn keeps them visible in
            // CI logs without blocking.
            'no-unused-vars': 'warn',
        },
    },

    // React hooks correctness: rules-of-hooks plus the v7 compiler-backed
    // set (purity, immutability, set-state-in-render, refs, ...). The four
    // overrides below keep the v7 *migration-analysis* rules visible (warn)
    // instead of failing CI: every hit is a legacy-but-working pattern whose
    // refactor is behavior-sensitive work far beyond this ticket's scope —
    // refs (14): the deliberate latest-value ref write during render;
    // set-state-in-effect (13): hydrate-from-store effects; immutability (2):
    // analyzer flags deferred calls to consts declared later in the body
    // (safe — effects run post-render); preserve-manual-memoization (1): a
    // compiler optimizer-skip notice, not a bug.
    {
        ...reactHooks.configs.flat['recommended-latest'],
        files: ['resources/js/**/*.{js,mjs,jsx}'],
        rules: {
            ...reactHooks.configs.flat['recommended-latest'].rules,
            'react-hooks/refs': 'warn',
            'react-hooks/set-state-in-effect': 'warn',
            'react-hooks/immutability': 'warn',
            'react-hooks/preserve-manual-memoization': 'warn',
        },
    },

    // File-scoped carve-outs for deliberate patterns (rule stays on
    // everywhere else, so new code keeps the guard):
    // lib/markdown.js matches \u0000/\u0001 on purpose — they are the
    // sentinel placeholders protecting code spans during ==highlight==
    // conversion. i18n.jsx reassigns the module-level `strings` cache inside
    // useI18n — that IS the t() mechanism; a context refactor is out of
    // scope (react-hooks/globals).
    {
        files: ['resources/js/lib/markdown.js'],
        rules: {'no-control-regex': 'off'},
    },
    {
        files: ['resources/js/i18n.jsx'],
        rules: {'react-hooks/globals': 'off'},
    },

    // Node-only files: vitest specs (ADR 0066 — pure lib/ modules tested in
    // Node) and the tokenizer CLI (process.argv) get the Node globals.
    {
        files: [
            'resources/js/**/*.test.{js,mjs}',
            'resources/js/lib/wordTokenizer.cli.mjs',
        ],
        languageOptions: {
            globals: {...globals.node},
        },
    },
];
