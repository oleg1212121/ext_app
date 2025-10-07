import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // 'resources/css/crossword.css',
            ],
            refresh: true,
        }),
    ],
    server: {
        host: '0.0.0.0', // Allow external connections (Docker)
        port: 8000,
        // strictPort: true,
        hmr: {
            host: 'localhost', // Critical for HMR in WSL2

        },
        watch: {
            usePolling: true,
        }
    },
});
