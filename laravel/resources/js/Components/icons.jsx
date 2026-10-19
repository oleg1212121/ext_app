// Grey line-art icon set shared by the reading surfaces (the simulator's tab
// strip and the reader's toolbar). Heroicons-outline-style stroke paths drawn
// with currentColor, so the caller's text-color classes drive both the resting
// grey and the accent-when-active state — the SVG never carries its own color.
const ICON_PATHS = {
    // Stress marks: a capital A with an acute stroke above it.
    stress: 'M7.5 20 12 8.5 16.5 20M9.3 15.2h5.4M11 5.5 13.5 3',
    // Phrasal verbs: two word blocks joined by a dotted underline.
    phrasal: 'M5 6v5h5V6H5zm7 0v5h5V6h-5zM4 16h2m3 0h2m3 0h2m3 0h2',
    // Simulator tab strip (paths previously inlined in Bilinguals.jsx).
    bookOpen: 'M12 6.03v13m0-13c-2.819-.831-4.715-1.076-8.029-1.023A.99.99 0 0 0 3 6v11c0 .563.466 1.014 1.03 1.007 3.122-.043 5.018.212 7.97 1.023m0-13c2.819-.831 4.715-1.076 8.029-1.023A.99.99 0 0 1 21 6v11c0 .563-.466 1.014-1.03 1.007-3.122-.043-5.018.212-7.97 1.023',
    pencil: 'm14.304 4.844 2.852 2.852M7 7H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1v-4.5m2.409-9.91a2.017 2.017 0 0 1 0 2.853l-6.844 6.844L8 14l.713-3.565 6.844-6.844a2.015 2.015 0 0 1 2.852 0Z',
    questionMarkCircle: 'M9.529 9.988a2.502 2.502 0 1 1 5 .191A2.441 2.441 0 0 1 12 12.582V14m-.01 3.008H12M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    highlighter: 'm14.613 3.514 5.873 5.874a1 1 0 0 1 0 1.414l-7.172 7.172a1 1 0 0 1-.707.293H8.414a1 1 0 0 1-.707-.293L2.939 13.2a1 1 0 0 1 0-1.414L10.2 4.46a1 1 0 0 1 1.414 0Zm-2.6 11.5L19.5 7.5m-13 13H20',
    codeBracket: 'm8 8-4 4 4 4m8 0 4-4-4-4m-2-3-4 14',
    // Reader toolbar.
    arrowLeft: 'M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18',
    minus: 'M19.5 12h-15',
    plus: 'M12 4.5v15m7.5-7.5h-15',
    eye: 'M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178ZM15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
    // Two panes side by side (the side-by-side layout toggle).
    columns: 'M4 5.75h7v12.5H4zM13 5.75h7v12.5h-7z',
    // Arrows pointing out (the wide-mode toggle).
    expand: 'M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15',
    speakerWave: 'M19.114 5.636a9 9 0 0 1 0 12.728M16.463 8.288a5.25 5.25 0 0 1 0 7.424M6.75 8.25l4.72-4.72a.75.75 0 0 1 1.28.53v15.88a.75.75 0 0 1-1.28.53l-4.72-4.72H4.51c-.88 0-1.704-.507-1.938-1.354A9.009 9.009 0 0 1 2.25 12c0-.83.112-1.633.322-2.396C2.806 8.756 3.63 8.25 4.51 8.25H6.75Z',
    play: 'M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z',
    pause: 'M15.75 5.25v13.5m-7.5-13.5v13.5',
    stop: 'M5.25 7.5A2.25 2.25 0 0 1 7.5 5.25h9a2.25 2.25 0 0 1 2.25 2.25v9a2.25 2.25 0 0 1-2.25 2.25h-9a2.25 2.25 0 0 1-2.25-2.25v-9Z',
    chevronLeft: 'M15.75 19.5 8.25 12l7.5-7.5',
    chevronRight: 'M8.25 4.5l7.5 7.5-7.5 7.5',
    // Word popup action toolbar.
    checkCircle: 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    minusCircle: 'M15 12H9m12 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    // A plus on the front of two stacked cards — the collection's new note.
    ankiAdd: 'M16.5 8.25V6a2.25 2.25 0 0 0-2.25-2.25H6A2.25 2.25 0 0 0 3.75 6v8.25A2.25 2.25 0 0 0 6 16.5h2.25m8.25-8.25H18A2.25 2.25 0 0 1 20.25 10.5V18A2.25 2.25 0 0 1 18 20.25h-7.5A2.25 2.25 0 0 1 8.25 18v-7.5A2.25 2.25 0 0 1 10.5 8.25h6ZM14.25 11.75v5m-2.5-2.5h5',
    // Two chasing arrows (heroicons-outline arrow-path) — the word already
    // sits in the deck; a click refreshes the card in place.
    ankiUpdate: 'M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99',
    // A left/right pair of speech bubbles (heroicons-outline
    // chat-bubble-left-right) — the popup's on-demand Context explanation.
    chatBubble: 'M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 0 1-.825-.242m9.345-8.334a2.126 2.126 0 0 0-.476-.095 48.64 48.64 0 0 0-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0 0 11.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155',
    // Sparkles (heroicons-outline sparkles) — the word popup's Ask-AI icon.
    sparkles: 'M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z',
};

export function Icon({name, className, strokeWidth = 2}) {
    return (
        <svg className={className} aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth={strokeWidth} d={ICON_PATHS[name]}/>
        </svg>
    );
}
