// The one sample word fixture shared by every profile preview (the Popups
// tab's live popup preview and the Anki tab's card-template preview both
// render from this single source). It mirrors the word-payload shape the
// reader/simulator popups receive (GET /words/{word}).

// A fixture-style word family exercising every state the popup can render:
// a form of "melt" whose base-word section ("MELT — verb") carries two
// transcriptions ("+1 more…" toggle), 10 translations (8 shown + "+2 more…")
// and counted definitions/examples/etymology collapses — plus the popup's
// own adjective section ("MELTED — adjective") with zero translations, which
// shows the no-translations placeholder. A rank and a marked familiarity top
// it off.
export const SAMPLE_DATA = {
    id: 1,
    word: 'melted',
    language_code: 'en',
    word_class: 'Verb',
    is_form: true,
    form_of: ['melt'],
    frequency: 2143,
    entries: [
        {
            id: 1,
            word: 'melt',
            word_class: 'Verb',
            transcriptions: [
                {value: 'melt', type: 'IPA'},
                {value: 'MELT', type: 'English Pronunciation'},
            ],
            definitions: [
                'To change from a solid to a liquid state under heat.',
                'To dissolve, blend, or merge — the crowd melted away.',
            ],
            translations: [
                {id: 1, word: 'таять', language_code: 'ru'},
                {id: 2, word: 'плавить', language_code: 'ru'},
                {id: 3, word: 'растворяться', language_code: 'ru'},
                {id: 4, word: 'плавиться', language_code: 'ru'},
                {id: 5, word: 'топить', language_code: 'ru'},
                {id: 6, word: 'растапливать', language_code: 'ru'},
                {id: 7, word: 'расплавлять', language_code: 'ru'},
                {id: 8, word: 'сливаться', language_code: 'ru'},
                {id: 9, word: 'смягчаться', language_code: 'ru'},
                {id: 10, word: 'растворить', language_code: 'ru'},
            ],
            examples: [
                'The snow melted in the sun.',
                'Her heart melted at the sight.',
                'The chocolate melted into the warm batter.',
            ],
            etymologies: [
                'From Old English meltan, "to dissolve into liquid".',
                'Cognate with Old Norse melt ("to digest").',
            ],
        },
        {
            id: 2,
            word: 'melted',
            word_class: 'Adjective',
            transcriptions: [],
            definitions: ['Changed into liquid form; no longer solid.'],
            translations: [],
            examples: [],
            etymologies: [],
        },
    ],
}

export const SAMPLE_FAMILIARITY = 40
export const SAMPLE_EXPLAIN = {rowKind: 'preview', rowId: 1, modelKey: 'preview', enabled: true, modelLabel: 'Sample model', followsAnswer: false}

// Anki preview only: the fixture carries no `tags` (the real popup passes
// the payload's `tags: [names]`), so the tab's card preview and sample card
// show the Tags field through this stand-in.
export const SAMPLE_TAGS = ['A2', 'top-3000']
