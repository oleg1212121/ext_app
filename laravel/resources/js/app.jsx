import './bootstrap';
import '../css/app.css';
import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';

import Alpine from 'alpinejs';
// import './crossword.js';

import.meta.glob([
    '../images/**'
]);

window.Alpine = Alpine;

Alpine.start();

const inertiaRoot = document.getElementById('app');

if (inertiaRoot) {
    createInertiaApp({
        title: (title) => `Ext App ${title}`,
        resolve: (name) => {
            const pages = import.meta.glob('./Pages/**/*.jsx', {eager: true});
            return pages[`./Pages/${name}.jsx`];
        },
        setup({el, App, props}) {
            createRoot(el).render(<App {...props} />);
        },
    });
}
