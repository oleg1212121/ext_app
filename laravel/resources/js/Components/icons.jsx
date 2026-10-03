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
};

export function Icon({name, className, strokeWidth = 2}) {
    return (
        <svg className={className} aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth={strokeWidth} d={ICON_PATHS[name]}/>
        </svg>
    );
}
