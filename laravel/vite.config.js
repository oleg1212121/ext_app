import {defineConfig} from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import flowbiteReact from "flowbite-react/plugin/vite";

export default defineConfig({
    plugins: [
        react(),
        tailwindcss(),
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.jsx',
                // 'resources/js/pages/alignments.jsx',
                // 'resources/js/pages/alignments-show.jsx',
                // 'resources/css/crossword.css',
                // 'resources/css/simulator.css',
                // Add any other custom CSS files hereq
            ],
            refresh: true,
        }),
        flowbiteReact()
    ],
    server: {
        host: '0.0.0.0', // Allow external connections (Docker)
        port: 8002, // Changed from 8000 to avoid conflict with Laravel
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