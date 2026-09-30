// Parametric mid-sagittal articulation diagrams.
//
// Line-art style matching the app's icon set: monochrome strokes drawn with
// currentColor (the wrapping element sets the theme color), tissue hinted with
// low-opacity fills. All geometry lives in a 260x200 viewBox, subject facing
// left. Sounds are described by a state object (see NEUTRAL) and rendered to
// SVG path descriptors; the React wrapper turns those into <path> elements and
// diphthongs/affricates into side-by-side panels.
//
// Diagram states are plain data, so a future animation phase can interpolate
// between them instead of swapping artwork.
//
// Anatomy landmarks (fixed): upper teeth block x 48..66 with its bottom edge
// at y 88, alveolar ridge crest at (74, 66), palatal dome apex (104, 54),
// velum origin (148, 68), pharynx wall x ~172. Articulation contacts are
// expressed by placing tongue points ON those landmarks.

export const VIEW = {w: 260, h: 200};

// Baseline rest articulation. Coordinates are absolute; y grows downward.
// tongue: tip / blade / dorsum / root high points of the tongue surface,
//         front to back. curl bends the tip up and back (retroflex).
// jaw: 0 closed .. 1 wide open.
// lip: close (sealed..apart), round (protruded/rounded), spread (wide smile),
//      dental (lower lip tucked against the upper teeth, labiodentals).
// velum: 'raised' seals the nasal cavity, 'lowered' opens it (nasals).
export const NEUTRAL = {
    tongue: {tip: {x: 60, y: 100}, blade: {x: 94, y: 94}, dorsum: {x: 134, y: 92}, root: {x: 168, y: 118}, curl: 0},
    jaw: 0.2,
    lip: {close: 0.3, round: 0, spread: 0, dental: false},
    velum: 'raised',
};

export function cloneState(state) {
    return {
        tongue: {...state.tongue, tip: {...state.tongue.tip}, blade: {...state.tongue.blade}, dorsum: {...state.tongue.dorsum}, root: {...state.tongue.root}},
        jaw: state.jaw,
        lip: {...state.lip},
        velum: state.velum,
    };
}

// Secondary palatal articulation ("soft" Russian consonants): the tongue body
// rises toward the hard palate while the primary constriction is kept.
export function palatalize(state) {
    const s = cloneState(state);
    const toward = (pt, tx, ty, k) => ({x: pt.x + (tx - pt.x) * k, y: pt.y + (ty - pt.y) * k});
    s.tongue.dorsum = toward(s.tongue.dorsum, 112, 60, 0.72);
    s.tongue.blade = toward(s.tongue.blade, 92, 72, 0.45);
    s.tongue.root = toward(s.tongue.root, 164, 116, 0.3);
    return s;
}

// Raised-tongue-tip retroflex bend (American r, Russian hard sh/zh).
export function withCurl(state, curl) {
    const s = cloneState(state);
    s.tongue.curl = curl;
    return s;
}

// Catmull-Rom spline through points, emitted as a cubic-bezier path string.
function smooth(points, closed = false) {
    const pts = closed ? [points[points.length - 1], ...points, points[0]] : points;
    const n = pts.length;
    const at = (i) => pts[Math.max(0, Math.min(n - 1, i))];
    let d = `M ${pts[1].x.toFixed(1)} ${pts[1].y.toFixed(1)}`;
    for (let i = 1; i < n - 2; i++) {
        const p0 = at(i - 1);
        const p1 = at(i);
        const p2 = at(i + 1);
        const p3 = at(i + 2);
        const c1 = {x: p1.x + (p2.x - p0.x) / 6, y: p1.y + (p2.y - p0.y) / 6};
        const c2 = {x: p2.x - (p3.x - p1.x) / 6, y: p2.y - (p3.y - p1.y) / 6};
        d += ` C ${c1.x.toFixed(1)} ${c1.y.toFixed(1)}, ${c2.x.toFixed(1)} ${c2.y.toFixed(1)}, ${p2.x.toFixed(1)} ${p2.y.toFixed(1)}`;
    }
    if (closed) {
        d += ' Z';
    }
    return d;
}

// ---- fixed anatomy -------------------------------------------------------

// Profile hint: forehead, nose, upper-face contour. Lips, chin and jaw are
// moving parts and drawn separately. Light stroke — context, not focus.
function profile() {
    return [
        {d: [
            'M 56 10',
            'C 46 20 40 34 39 46',
            'C 38 50 36 54 32 58',
            'L 22 66',
            'C 19 68 21 72 26 73',
            'L 34 76',
        ].join(' '), fill: null, width: 2, opacity: 0.85},
        // back of the head, for context
        {d: 'M 56 10 C 96 2 150 8 172 34 C 182 46 186 62 186 78', fill: null, width: 2, opacity: 0.85},
    ];
}

// Nasal passage floor, just above the palate — air escapes here when the
// velum lowers.
function nasalFloor() {
    return [
        {d: 'M 40 64 C 60 56 88 50 116 48 C 138 47 154 50 164 55', fill: null, width: 1.3, opacity: 0.45},
        {d: 'M 30 70 C 40 62 52 58 62 58', fill: null, width: 1.3, opacity: 0.45},
    ];
}

function pharynxWall() {
    return {d: 'M 172 52 C 174 84 174 118 171 148 C 170 158 172 166 178 172', fill: null, width: 2};
}

// Roof of the mouth: gum ridge, alveolar crest, palatal dome, velum origin —
// the surface the tongue articulates against.
function palate() {
    return {d: [
        'M 66 70',
        'C 70 65 74 64 78 66',
        'C 84 58 96 53 106 54',
        'C 118 55 128 61 136 67',
        'C 140 71 144 74 148 76',
    ].join(' '), fill: null, width: 2.25};
}

function upperTeeth() {
    // two incisor silhouettes hanging from the gum
    return [
        {d: 'M 48 70 L 66 70 L 66 87 Q 60 90 54 88 L 48 86 Z', fill: 0.12, width: 1.9},
        {d: 'M 57 71 L 57 88', fill: null, width: 1.1, opacity: 0.4},
    ];
}

function velumRaised() {
    // flap pressed against the pharynx wall, sealing the nasal cavity
    return [
        {d: [
            'M 148 76',
            'C 154 75 162 77 168 82',
            'C 163 88 155 89 150 86',
            'C 148 83 148 79 148 76',
            'Z',
        ].join(' '), fill: 0.1, width: 2},
    ];
}

function velumLowered() {
    // flap hanging down, uvula clear of the pharynx wall: nasal escape open
    return [
        {d: [
            'M 148 76',
            'C 154 81 158 90 156 101',
            'C 155 107 150 109 148 104',
            'C 146 95 146 84 148 76',
            'Z',
        ].join(' '), fill: 0.1, width: 2},
        {d: 'M 160 84 C 165 79 168 71 169 62', fill: null, width: 1.3, opacity: 0.5},
    ];
}

// ---- moving parts --------------------------------------------------------

function lipsAndJaw(state) {
    const {jaw} = state;
    const {lip} = state;
    const dy = 14 * jaw;
    const protr = 8 * lip.round - 2 * lip.spread;
    const px = (x) => x - protr;
    const shapes = [];

    // upper lip: from the philtrum around the lip edge to the teeth
    shapes.push({d: [
        `M ${px(34).toFixed(1)} 76`,
        `C ${px(27).toFixed(1)} 79 ${px(23).toFixed(1)} 84 ${px(24).toFixed(1)} 90`,
        `C ${px(25).toFixed(1)} 95 ${px(32).toFixed(1)} 96 ${px(40).toFixed(1)} 93`,
        `L ${px(46).toFixed(1)} 88`,
        `C ${px(38).toFixed(1)} 87 ${px(34).toFixed(1)} 82 ${px(34).toFixed(1)} 76`,
        'Z',
    ].join(' '), fill: 0.08, width: 2});

    // rounded pucker: lips protrude around a small circular opening
    if (lip.round > 0.5 && lip.close > 0.55) {
        shapes.push({smallCircle: {cx: px(30), cy: 90 + dy * 0.25, r: 3.2}});
    }

    // lower lip (labiodentals tuck it up behind the upper teeth)
    if (lip.dental) {
        shapes.push({d: [
            `M ${px(46).toFixed(1)} 90`,
            `C ${px(36).toFixed(1)} 89 ${px(28).toFixed(1)} 92 ${px(29).toFixed(1)} 98`,
            `C ${px(31).toFixed(1)} 102 ${px(40).toFixed(1)} 102 ${px(46).toFixed(1)} 98`,
            'Z',
        ].join(' '), fill: 0.08, width: 2});
    } else {
        const top = 98 + dy;
        shapes.push({d: [
            `M ${px(46).toFixed(1)} ${(top - 2).toFixed(1)}`,
            `C ${px(36).toFixed(1)} ${(top - 4).toFixed(1)} ${px(27).toFixed(1)} ${(top - 1).toFixed(1)} ${px(28).toFixed(1)} ${(top + 5).toFixed(1)}`,
            `C ${px(29).toFixed(1)} ${(top + 10).toFixed(1)} ${px(38).toFixed(1)} ${(top + 10).toFixed(1)} ${px(46).toFixed(1)} ${(top + 6).toFixed(1)}`,
            `L ${px(48).toFixed(1)} ${(top + 2).toFixed(1)}`,
            'Z',
        ].join(' '), fill: 0.08, width: 2});
        if (lip.close <= 0.55) {
            const gap = Math.max(0.8, (1 - lip.close) * (2 + 6 * jaw) - 2 * lip.round);
            shapes.push({d: `M ${px(30).toFixed(1)} ${(top - 3 + (6 - gap) / 2).toFixed(1)} L ${px(44).toFixed(1)} ${(top - 2 + (6 - gap) / 2).toFixed(1)}`, fill: null, width: 1.4, opacity: 0.45});
        }
    }

    // lower teeth
    shapes.push({d: [
        `M 46 ${(92 + dy).toFixed(1)}`,
        `L 64 ${(92 + dy).toFixed(1)}`,
        `L 64 ${(108 + dy).toFixed(1)}`,
        `Q 55 ${(111 + dy).toFixed(1)} 46 ${(108 + dy).toFixed(1)}`,
        'Z',
    ].join(' '), fill: 0.12, width: 1.9});

    // chin and jaw line, then the throat front
    shapes.push({d: [
        `M ${px(34).toFixed(1)} ${(108 + dy).toFixed(1)}`,
        `C ${px(28).toFixed(1)} ${(118 + dy).toFixed(1)} ${px(27).toFixed(1)} ${(132 + dy * 0.85).toFixed(1)} ${px(34).toFixed(1)} ${(142 + dy * 0.7).toFixed(1)}`,
        `C ${px(46).toFixed(1)} ${(156 + dy * 0.4).toFixed(1)} ${px(76).toFixed(1)} 166 ${px(104).toFixed(1)} 170`,
        `L 122 174`,
        'C 126 182 127 191 127 200',
    ].join(' '), fill: null, width: 2});

    return shapes;
}

function tongue(state) {
    const {tip, blade, dorsum, root, curl} = state.tongue;
    const dy = 14 * state.jaw;
    const floorDrop = dy * 0.35;

    // the visible tip: curled (retroflex) tips bend up and back
    const tipApex = curl > 0.05
        ? {x: tip.x + 9 * curl, y: tip.y - 7 * curl}
        : tip;

    const outline = [
        tipApex,
        // small notch under a curled tip keeps the retroflex shape readable
        ...(curl > 0.05 ? [{x: tip.x + 2 * curl, y: tip.y + 3}] : []),
        blade,
        dorsum,
        root,
        {x: 175, y: 136},
        {x: 160, y: 148 + floorDrop},
        {x: 122, y: 145 + floorDrop},
        {x: 88, y: 141 + floorDrop},
        {x: 64, y: 133 + floorDrop * 0.7},
        {x: tip.x + (curl > 0.05 ? 5 : 0), y: tip.y + 11},
    ];

    return [
        {d: smooth(outline, true), fill: 0.07, width: 2},
        // median groove hint along the tongue surface
        {d: smooth([{x: tip.x + 4, y: tip.y + 6}, {x: blade.x + 5, y: blade.y + 7}, {x: dorsum.x + 7, y: dorsum.y + 7}, {x: root.x - 2, y: root.y + 8}]), fill: null, width: 1.2, opacity: 0.35},
    ];
}

// ---- public API ----------------------------------------------------------

// Render one articulation state to a list of SVG shape descriptors:
// {d, fill?: number(opacity), width?: number, opacity?: number, smallCircle?}
export function buildDiagram(state) {
    return [
        ...profile(),
        ...nasalFloor(),
        pharynxWall(),
        palate(),
        ...(state.velum === 'lowered' ? velumLowered() : velumRaised()),
        ...upperTeeth(),
        ...lipsAndJaw(state),
        ...tongue(state),
    ];
}
