import './bootstrap';

import Alpine from 'alpinejs';
// import './crossword.js';

import.meta.glob([
    '../images/**'
]);

window.Alpine = Alpine;

Alpine.start();
