import {defineConfig} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // 'resources/css/crossword.css',
                // 'resources/css/simulator.css',
                // Add any other custom CSS files here
            ],
            refresh: true,
        }),
    ],
    server: {
        host: '0.0.0.0', // Allow external connections (Docker)
        port: 8001, // Changed from 8000 to avoid conflict with Laravel
        strictPort: true,
        hmr: {
            host: 'localhost',
            // protocol: 'ws',
            // port: 5173,
        },
        watch: {
            usePolling: true,
        }
    },
});
