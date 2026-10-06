import {defineConfig} from 'vitest/config';

// Standalone config — deliberately does NOT load the laravel-vite plugin or
// the app's vite.config.js. Tests pin the pure lib/ modules in Node only;
// React components stay covered by the Pest feature suite (ADR 0066).
export default defineConfig({
    test: {
        environment: 'node',
        include: ['resources/js/**/*.test.{js,mjs}'],
    },
});
