import './bootstrap';
import '../css/app.css';
import {createInertiaApp} from '@inertiajs/react';
import {createRoot} from 'react-dom/client';

import Alpine from 'alpinejs';

import.meta.glob([
    '../images/**'
]);

window.Alpine = Alpine;

const inertiaRoot = document.getElementById('app');

// Alpine drives the Blade layouts' dropdown nav (auth pages, which have no
// #app root). On Inertia pages its global MutationObserver would walk every
// node React touches — pure overhead there, and a freeze ingredient on the
// reader — so it only starts where Inertia is absent.
if (!inertiaRoot) {
    Alpine.start();
}

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
