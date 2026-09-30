// The phoneme reference: every card of the pronunciation modal.
//
// Content model per sound:
//   ipa      — the IPA symbol shown on the card
//   spell    — common spelling(s) of the sound
//   art      — one articulation state, or [start, end] for two-panel sounds
//              (diphthongs and affricates); built from the states below, so a
//              future animation phase can interpolate between the panels
//   desc     — how the sound is produced, keyed by interface language
//   hint     — cross-language hint for the learner, keyed by interface
//              language (EN cards hint for Russian speakers, RU cards for
//              English speakers); null when there is nothing useful to add
//   examples — words carrying the sound; `m` is the highlighted substring
//   rp       — optional note where British RP differs (English cards only)
//   anim     — reserved for phase 2 (tongue-position animation); always null
//
// English is taught as General American — the variety of the CMUdict
// transcriptions the word popups already show; RP differences surface as
// per-card notes rather than a separate chart.

import {NEUTRAL, cloneState, palatalize, withCurl} from './art.js';

const S = (over) => {
    const s = cloneState(NEUTRAL);
    if (over.t) {
        Object.assign(s.tongue, {
            tip: {x: over.t[0][0], y: over.t[0][1]},
            blade: {x: over.t[1][0], y: over.t[1][1]},
            dorsum: {x: over.t[2][0], y: over.t[2][1]},
            root: {x: over.t[3][0], y: over.t[3][1]},
        });
    }
    s.jaw = over.jaw ?? s.jaw;
    s.velum = over.velum ?? s.velum;
    if (over.lip) {
        Object.assign(s.lip, over.lip);
    }
    return s;
};

// Shared articulation states. Front cavity: lips ~x 24, teeth x 46..66,
// alveolar ridge crest (74, 66), palatal dome (104, 54), velum origin
// (148, 76), pharynx wall x 172.
const ST = {
    // vowels — the tongue body arch, front to back
    i: S({t: [[56, 92], [84, 66], [108, 60], [164, 118]], jaw: .1, lip: {spread: .5}}),
    ɪ: S({t: [[58, 96], [86, 72], [114, 66], [166, 117]], jaw: .12, lip: {spread: .2}}),
    e: S({t: [[58, 98], [86, 70], [112, 64], [166, 117]], jaw: .15}),
    ɛ: S({t: [[60, 100], [90, 78], [120, 72], [166, 116]], jaw: .2}),
    æ: S({t: [[60, 106], [92, 92], [126, 84], [168, 116]], jaw: .55, lip: {spread: .3}}),
    ɑ: S({t: [[68, 110], [100, 104], [140, 96], [172, 115]], jaw: .6}),
    ɔ: S({t: [[66, 108], [102, 100], [144, 82], [172, 115]], jaw: .45, lip: {round: .3}}),
    o: S({t: [[60, 102], [96, 78], [138, 72], [172, 115]], jaw: .3, lip: {round: .4}}),
    ʊ: S({t: [[62, 102], [94, 80], [136, 66], [168, 116]], jaw: .2, lip: {round: .25}}),
    u: S({t: [[58, 98], [90, 68], [132, 60], [168, 116]], jaw: .1, lip: {round: .7}}),
    ʌ: S({t: [[64, 104], [94, 90], [132, 84], [168, 116]], jaw: .3}),
    ə: S({t: [[64, 102], [94, 88], [132, 84], [168, 116]], jaw: .2}),
    ɜ: withCurl(S({t: [[74, 84], [98, 84], [134, 80], [168, 116]], jaw: .25}), .7),
    ы: S({t: [[58, 94], [88, 68], [120, 64], [168, 116]], jaw: .12, lip: {spread: .1}}),
    а: S({t: [[66, 108], [98, 98], [136, 90], [172, 115]], jaw: .6}),

    // consonants — primary constrictions
    p: S({jaw: 0, lip: {close: 1}}),
    b: S({jaw: 0, lip: {close: 1}}),
    t: S({t: [[72, 67], [90, 80], [126, 92], [168, 116]], jaw: .05}),
    d: S({t: [[72, 67], [90, 80], [126, 92], [168, 116]], jaw: .05}),
    k: S({t: [[64, 104], [98, 88], [144, 74], [172, 119]], jaw: .15}),
    g: S({t: [[64, 104], [98, 88], [144, 74], [172, 119]], jaw: .15}),
    tʃStop: S({t: [[64, 86], [80, 68], [128, 90], [168, 116]], jaw: .1}),
    tʃFric: S({t: [[66, 90], [82, 74], [130, 88], [168, 116]], jaw: .1, lip: {round: .25}}),
    f: S({jaw: 0, lip: {dental: true, close: .8}}),
    v: S({jaw: 0, lip: {dental: true, close: .8}}),
    θ: S({t: [[44, 84], [86, 84], [128, 94], [168, 116]], jaw: .1}),
    ð: S({t: [[44, 84], [86, 84], [128, 94], [168, 116]], jaw: .1}),
    s: S({t: [[70, 74], [88, 80], [130, 94], [168, 116]], jaw: .05}),
    z: S({t: [[70, 74], [88, 80], [130, 94], [168, 116]], jaw: .05}),
    ʃ: S({t: [[66, 90], [82, 74], [130, 88], [168, 116]], jaw: .1, lip: {round: .25}}),
    ʒ: S({t: [[66, 90], [82, 74], [130, 88], [168, 116]], jaw: .1, lip: {round: .25}}),
    m: S({jaw: 0, lip: {close: 1}, velum: 'lowered'}),
    n: S({t: [[72, 67], [90, 80], [126, 92], [168, 116]], jaw: .05, velum: 'lowered'}),
    ŋ: S({t: [[64, 104], [98, 88], [144, 74], [172, 119]], jaw: .15, velum: 'lowered'}),
    h: S({jaw: .35}),
    l: S({t: [[72, 67], [94, 98], [132, 96], [168, 116]], jaw: .05}),
    ɹ: withCurl(S({t: [[76, 80], [96, 84], [134, 86], [168, 116]], jaw: .2, lip: {round: .35}}), .6),
    j: S({t: [[58, 96], [84, 64], [108, 58], [166, 118]], jaw: .08}),
    w: S({t: [[64, 98], [96, 80], [142, 66], [172, 115]], jaw: .05, lip: {round: .9, close: .8}}),

    // Russian specifics
    р: S({t: [[68, 66], [90, 90], [130, 96], [168, 116]], jaw: .05}),
    л: S({t: [[52, 82], [92, 98], [138, 100], [172, 119]], jaw: .05}),
    т: S({t: [[52, 84], [88, 80], [128, 94], [170, 120]], jaw: .05}),
    с: S({t: [[56, 92], [68, 74], [130, 94], [170, 120]], jaw: .05}),
    ш: withCurl(S({t: [[84, 78], [94, 82], [140, 88], [172, 115]], jaw: .1, lip: {round: .3}}), .8),
    х: S({t: [[64, 104], [98, 88], [148, 78], [172, 119]], jaw: .15}),
    щ: S({t: [[60, 88], [76, 66], [112, 58], [166, 118]], jaw: .1}),
};

const soft = (base) => palatalize(ST[base]);

// English (General American)
const EN = {
    vowels: [
        {
            ipa: 'i', spell: 'ee, ea', art: [ST.i], anim: null,
            desc: {en: 'High front vowel, tense and long. The tongue is high and forward, lips spread.', ru: 'Передний гласный верхнего подъёма, долгий и напряжённый. Язык высоко и впереди, губы растянуты.'},
            hint: {ru: 'похож на русский [и], но дольше и напряжённее — «и-и-и».'},
            examples: [{w: 'see', m: 'ee'}, {w: 'eat', m: 'ea'}, {w: 'machine', m: 'i'}],
        },
        {
            ipa: 'ɪ', spell: 'i, y', art: [ST.ɪ], anim: null,
            desc: {en: 'High front vowel, lax and short — the tongue sits slightly lower and further back than in /i/.', ru: 'Передний гласный верхнего подъёма, краткий и расслабленный — язык чуть ниже и дальше, чем для /i/.'},
            hint: {ru: 'короткий звук между русскими [и] и [ы]: говорите [и] без напряжения.'},
            examples: [{w: 'big', m: 'i'}, {w: 'city', m: 'i'}, {w: 'system', m: 'y'}],
        },
        {
            ipa: 'ɛ', spell: 'e, ea', art: [ST.ɛ], anim: null,
            desc: {en: 'Mid front vowel. The tongue is mid-height and forward, mouth slightly open.', ru: 'Гласный среднего подъёма переднего ряда. Язык в средней позиции и впереди, рот приоткрыт.'},
            hint: {ru: 'похож на русский [э], как в «этот».'},
            examples: [{w: 'bed', m: 'e'}, {w: 'ten', m: 'e'}, {w: 'bread', m: 'ea'}],
        },
        {
            ipa: 'æ', spell: 'a', art: [ST.æ], anim: null,
            desc: {en: 'Low front vowel. The mouth is wide open, the tongue low and forward.', ru: 'Гласный нижнего подъёма переднего ряда. Рот широко открыт, язык низко и впереди.'},
            hint: {ru: 'между [а] и [э]; рот открыт шире, чем для русского [э]. Не заменяйте на [э] — cat ≠ кэт.'},
            examples: [{w: 'cat', m: 'a'}, {w: 'man', m: 'a'}, {w: 'back', m: 'a'}],
        },
        {
            ipa: 'ɑ', spell: 'o, a', art: [ST.ɑ], anim: null,
            desc: {en: 'Low back vowel, unrounded. The tongue is low and pulled back, jaw wide.', ru: 'Задний гласный нижнего подъёма, без огубления. Язык низко и отодвинут назад, челюсть опущена.'},
            hint: {ru: 'глубокое заднее [а]: в слове hot американцы говорят [ɑ], британцы — короткое округлённое [ɒ].'},
            rp: {en: 'RP uses short rounded /ɒ/ in hot, stop, coffee.', ru: 'В британском произношении в hot, stop звучит короткое округлённое [ɒ].'},
            examples: [{w: 'hot', m: 'o'}, {w: 'father', m: 'a'}, {w: 'stop', m: 'o'}],
        },
        {
            ipa: 'ɔ', spell: 'aw, o', art: [ST.ɔ], anim: null,
            desc: {en: 'Mid-low back vowel, slightly rounded, with the tongue pulled back.', ru: 'Задний гласный средне-нижнего подъёма, слегка огубленный, язык отодвинут назад.'},
            hint: {ru: 'похоже на русское [о] в «дóм», но короче и с меньшим округлением губ.'},
            rp: {en: 'RP keeps a long rounded /ɔː/ here; in much of the US saw and dawn merge toward /ɑ/ (cot–caught merger).', ru: 'В RP здесь долгое округлённое [ɔː]; во многих диалектах США saw и dawn сливаются в [ɑ] (cot–caught merger).'},
            examples: [{w: 'dog', m: 'o'}, {w: 'saw', m: 'aw'}, {w: 'thought', m: 'ough'}],
        },
        {
            ipa: 'ʊ', spell: 'oo, u', art: [ST.ʊ], anim: null,
            desc: {en: 'High back vowel, lax and short, lips loosely rounded.', ru: 'Задний гласный верхнего подъёма, краткий и расслабленный, губы округлены слабо.'},
            hint: {ru: 'короткое, между [у] и [о]: говорите [у] без напряжения.'},
            examples: [{w: 'book', m: 'oo'}, {w: 'good', m: 'oo'}, {w: 'put', m: 'u'}],
        },
        {
            ipa: 'u', spell: 'oo, u, ew', art: [ST.u], anim: null,
            desc: {en: 'High back vowel, tense and long, lips firmly rounded.', ru: 'Задний гласный верхнего подъёма, долгий и напряжённый, губы плотно округлены.'},
            hint: {ru: 'как русское [у], но долгое: «у-у-у».'},
            examples: [{w: 'food', m: 'oo'}, {w: 'blue', m: 'ue'}, {w: 'student', m: 'u'}],
        },
        {
            ipa: 'ʌ', spell: 'u, o', art: [ST.ʌ], anim: null,
            desc: {en: 'Mid-low central vowel, short and relaxed, lips neutral.', ru: 'Гласный средне-нижнего подъёма, краткий и расслабленный, губы нейтральны.'},
            hint: {ru: 'короткое [а], чуть ближе к [ъ]: cup звучит почти как «кап», но короче.'},
            examples: [{w: 'cup', m: 'u'}, {w: 'sun', m: 'u'}, {w: 'love', m: 'o'}],
        },
        {
            ipa: 'ə', spell: 'a, o, u, e…', art: [ST.ə], anim: null,
            desc: {en: 'The schwa: mid central vowel of unstressed syllables. The tongue rests in the middle.', ru: 'Шва: нейтральный гласный безударных слогов. Язык лежит в нейтральном положении.'},
            hint: {ru: 'самый частый звук английского; как редуцированный [ъ] в русском «вода».'},
            examples: [{w: 'about', m: 'a'}, {w: 'sofa', m: 'a'}, {w: 'problem', m: 'o'}],
        },
        {
            ipa: 'ɜ', spell: 'ir, ur, er', art: [ST.ɜ], anim: null,
            desc: {en: 'R-colored mid central vowel: the tongue is bunched and pulled back, tip curled.', ru: 'Средний гласный с призвуком [р]: язык собран в комок и оттянут, кончик завёрнут.'},
            hint: {ru: 'долгий [э] с американским [р]-окрашиванием; не подставляйте русский раскатистый [р].'},
            rp: {en: 'RP is non-rhotic: bird sounds like [bɜːd], the r is not pronounced.', ru: 'По-британски r не произносится: bird ≈ [бёэд].'},
            examples: [{w: 'bird', m: 'ir'}, {w: 'turn', m: 'ur'}, {w: 'her', m: 'er'}],
        },
    ],
    diphthongs: [
        {
            ipa: 'eɪ', spell: 'a…e, ai, ay', art: [ST.e, ST.ɪ], anim: null,
            desc: {en: 'Diphthong: glides from mid front /e/ toward high /ɪ/.', ru: 'Дифтонг: скольжение от [э] к [и].'},
            hint: {ru: 'звучит как [эй]: day ≈ «дэй», а не «дэ».'},
            examples: [{w: 'day', m: 'ay'}, {w: 'rain', m: 'ai'}, {w: 'make', m: 'a'}],
        },
        {
            ipa: 'aɪ', spell: 'i…e, y, igh', art: [ST.æ, ST.ɪ], anim: null,
            desc: {en: 'Diphthong: glides from low /æ/ toward high /ɪ/.', ru: 'Дифтонг: скольжение от [а] к [и].'},
            hint: {ru: 'звучит как [ай]: time ≈ «тайм».'},
            examples: [{w: 'time', m: 'i'}, {w: 'my', m: 'y'}, {w: 'light', m: 'igh'}],
        },
        {
            ipa: 'ɔɪ', spell: 'oi, oy', art: [ST.ɔ, ST.ɪ], anim: null,
            desc: {en: 'Diphthong: glides from back rounded /ɔ/ toward high /ɪ/.', ru: 'Дифтонг: скольжение от [о] к [и].'},
            hint: {ru: 'звучит как [ой], почти как русское «ой».'},
            examples: [{w: 'boy', m: 'oy'}, {w: 'coin', m: 'oi'}, {w: 'noise', m: 'oi'}],
        },
        {
            ipa: 'aʊ', spell: 'ou, ow', art: [ST.æ, ST.ʊ], anim: null,
            desc: {en: 'Diphthong: glides from low /æ/ toward back rounded /ʊ/.', ru: 'Дифтонг: скольжение от [а] к [у].'},
            hint: {ru: 'звучит как [а́у] с ударением на первом звуке: now ≈ «на́у».'},
            examples: [{w: 'now', m: 'ow'}, {w: 'house', m: 'ou'}, {w: 'down', m: 'ow'}],
        },
        {
            ipa: 'oʊ', spell: 'o…e, oa, ow', art: [ST.o, ST.ʊ], anim: null,
            desc: {en: 'Diphthong: glides from mid back rounded /o/ toward /ʊ/.', ru: 'Дифтонг: скольжение от [о] к [у].'},
            hint: {ru: 'не чистое [о]: go ≈ «го́у». Первый звук как русское [о] во «втом».'},
            rp: {en: 'RP has a purer /əʊ/ — less lip rounding toward the end.', ru: 'По-британски это [əʊ] — меньше [у] в конце.'},
            examples: [{w: 'go', m: 'o'}, {w: 'home', m: 'o'}, {w: 'boat', m: 'oa'}],
        },
    ],
    stops: [
        {
            ipa: 'p', spell: 'p', art: [ST.p], anim: null,
            desc: {en: 'Voiceless bilabial stop: the lips seal, pressure builds, then releases.', ru: 'Глухой губно-губной взрывной: губы смыкаются, воздух накапливается и прорывается.'},
            hint: {ru: 'как русское [п], но с лёгким придыханием в начале слова.'},
            examples: [{w: 'pen', m: 'p'}, {w: 'happy', m: 'pp'}, {w: 'stop', m: 'p'}],
        },
        {
            ipa: 'b', spell: 'b', art: [ST.b], anim: null,
            desc: {en: 'Voiced bilabial stop: like /p/ but with vocal-fold vibration.', ru: 'Звонкий губно-губной взрывной: как /p/, но с голосом.'},
            hint: {ru: 'как русское [б]; в конце слова ослабляется (web ≈ «уэб»).'},
            examples: [{w: 'book', m: 'b'}, {w: 'rabbit', m: 'bb'}, {w: 'web', m: 'b'}],
        },
        {
            ipa: 't', spell: 't', art: [ST.t], anim: null,
            desc: {en: 'Voiceless alveolar stop: the tongue tip seals on the ridge behind the teeth.', ru: 'Глухой альвеолярный взрывной: кончик языка смыкается на бугорке за зубами.'},
            hint: {ru: 'как [т], но язык выше — на бугорке. Между гласными часто озвучивается: water ≈ «уо́дэр».'},
            examples: [{w: 'ten', m: 't'}, {w: 'water', m: 't'}, {w: 'cat', m: 't'}],
        },
        {
            ipa: 'd', spell: 'd', art: [ST.d], anim: null,
            desc: {en: 'Voiced alveolar stop: like /t/ but with voice.', ru: 'Звонкий альвеолярный взрывной: как /t/, но с голосом.'},
            hint: {ru: 'как [д], язык на бугорке за зубами, а не у верхних зубов.'},
            examples: [{w: 'dog', m: 'd'}, {w: 'ladder', m: 'dd'}, {w: 'read', m: 'd'}],
        },
        {
            ipa: 'k', spell: 'k, c, ck', art: [ST.k], anim: null,
            desc: {en: 'Voiceless velar stop: the back of the tongue seals against the soft palate.', ru: 'Глухой заднеязычный взрывной: задняя часть языка смыкается с мягким нёбом.'},
            hint: {ru: 'как русское [к].'},
            examples: [{w: 'cat', m: 'c'}, {w: 'kite', m: 'k'}, {w: 'school', m: 'ch'}],
        },
        {
            ipa: 'g', spell: 'g, gg', art: [ST.g], anim: null,
            desc: {en: 'Voiced velar stop: like /k/ but with voice.', ru: 'Звонкий заднеязычный взрывной: как /k/, но с голосом.'},
            hint: {ru: 'как русское [г], без фрикативного призвука.'},
            examples: [{w: 'go', m: 'g'}, {w: 'big', m: 'g'}, {w: 'struggle', m: 'gg'}],
        },
    ],
    affricates: [
        {
            ipa: 'tʃ', spell: 'ch, tch', art: [ST.tʃStop, ST.tʃFric], anim: null,
            desc: {en: 'Voiceless postalveolar affricate: a /t/-like stop released into /ʃ/ friction.', ru: 'Глухая аффриката: смычка [т] переходит в шум [ш].'},
            hint: {ru: 'как [ч], но твёрже русского: смычка сильнее.'},
            examples: [{w: 'chair', m: 'ch'}, {w: 'match', m: 'tch'}, {w: 'nature', m: 't'}],
        },
        {
            ipa: 'dʒ', spell: 'j, g(e/i), dge', art: [ST.d, ST.ʒ], anim: null,
            desc: {en: 'Voiced postalveolar affricate: the voiced counterpart of /tʃ/.', ru: 'Звонкая аффриката, парная к /tʃ/: смычка переходит в звонкий шум.'},
            hint: {ru: 'звонкая пара [ч]: слитное [дж], как в job — не разделяйте на [д]+[ж].'},
            examples: [{w: 'job', m: 'j'}, {w: 'bridge', m: 'dge'}, {w: 'gym', m: 'g'}],
        },
    ],
    fricatives: [
        {
            ipa: 'f', spell: 'f, ph', art: [ST.f], anim: null,
            desc: {en: 'Voiceless labiodental fricative: the lower lip touches the upper teeth, air hisses through.', ru: 'Глухой губно-зубной фрикативный: нижняя губа касается верхних зубов, воздух проходит с шумом.'},
            hint: {ru: 'как русское [ф].'},
            examples: [{w: 'fun', m: 'f'}, {w: 'coffee', m: 'ff'}, {w: 'phone', m: 'ph'}],
        },
        {
            ipa: 'v', spell: 'v', art: [ST.v], anim: null,
            desc: {en: 'Voiced labiodental fricative: like /f/ but with voice.', ru: 'Звонкий губно-зубной фрикативный: как /f/, но с голосом.'},
            hint: {ru: 'как [в]; в конце слова не оглушайте — love ≠ «лавф».'},
            examples: [{w: 'very', m: 'v'}, {w: 'love', m: 'v'}, {w: 'seven', m: 'v'}],
        },
        {
            ipa: 'θ', spell: 'th', art: [ST.θ], anim: null,
            desc: {en: 'Voiceless dental fricative: the tongue tip touches or pokes between the teeth.', ru: 'Глухой межзубный фрикативный: кончик языка у зубов или между ними.'},
            hint: {ru: 'нет в русском! язык между зубами, глухой шум — не заменяйте на [с], [т] или [ф].'},
            examples: [{w: 'think', m: 'th'}, {w: 'bath', m: 'th'}, {w: 'nothing', m: 'th'}],
        },
        {
            ipa: 'ð', spell: 'th', art: [ST.ð], anim: null,
            desc: {en: 'Voiced dental fricative: the /θ/ position with vocal-fold vibration.', ru: 'Звонкий межзубный фрикативный: положение как у /θ/, но с голосом.'},
            hint: {ru: 'тот же межзубный, но звонкий; почти всегда в служебных словах: this, the, that.'},
            examples: [{w: 'this', m: 'th'}, {w: 'mother', m: 'th'}, {w: 'the', m: 'th'}],
        },
        {
            ipa: 's', spell: 's, c(e)', art: [ST.s], anim: null,
            desc: {en: 'Voiceless alveolar sibilant: a narrow groove along the tongue focuses the hiss.', ru: 'Глухой альвеолярный свистящий: узкая бороздка вдоль языка фокусирует шум.'},
            hint: {ru: 'как [с], но язык чуть выше и шум резче.'},
            examples: [{w: 'sun', m: 's'}, {w: 'city', m: 'c'}, {w: 'books', m: 's'}],
        },
        {
            ipa: 'z', spell: 'z, s', art: [ST.z], anim: null,
            desc: {en: 'Voiced alveolar sibilant: like /s/ but with voice.', ru: 'Звонкий альвеолярный свистящий: как /s/, но с голосом.'},
            hint: {ru: 'как [з]; часто в окончании множественного числа: dogs, roses.'},
            examples: [{w: 'zoo', m: 'z'}, {w: 'nose', m: 's'}, {w: 'dogs', m: 's'}],
        },
        {
            ipa: 'ʃ', spell: 'sh, ti, ci', art: [ST.ʃ], anim: null,
            desc: {en: 'Voiceless postalveolar sibilant: the blade rises behind the ridge, lips slightly rounded.', ru: 'Глухой шипящий: передняя часть языка поднята за бугорком, губы чуть округлены.'},
            hint: {ru: 'мягче и ближе к зубам, чем русское [ш] — что-то между [ш] и [щ], губы трубочкой.'},
            examples: [{w: 'she', m: 'sh'}, {w: 'station', m: 'ti'}, {w: 'special', m: 'ci'}],
        },
        {
            ipa: 'ʒ', spell: 'si, ge, s', art: [ST.ʒ], anim: null,
            desc: {en: 'Voiced postalveolar sibilant: the voiced counterpart of /ʃ/.', ru: 'Звонкий шипящий, парный к /ʃ/.'},
            hint: {ru: 'звонкая пара [ʃ]: похоже на [ж] в заимствованиях «жюри», «Жак» — встречается редко.'},
            examples: [{w: 'vision', m: 'si'}, {w: 'usual', m: 'su'}, {w: 'garage', m: 'ge'}],
        },
        {
            ipa: 'h', spell: 'h', art: [ST.h], anim: null,
            desc: {en: 'Voiceless glottal fricative: plain breath through the open vocal tract.', ru: 'Глухой гортанный фрикативный: просто выдох через свободный речевой тракт.'},
            hint: {ru: 'лёгкий выдох, намного мягче русского [х]; язык никуда не поднимается.'},
            examples: [{w: 'hat', m: 'h'}, {w: 'behind', m: 'h'}, {w: 'who', m: 'wh'}],
        },
    ],
    nasals: [
        {
            ipa: 'm', spell: 'm', art: [ST.m], anim: null,
            desc: {en: 'Bilabial nasal: the lips seal and air escapes through the nose.', ru: 'Губно-губной носовой: губы сомкнуты, воздух идёт через нос.'},
            hint: {ru: 'как русское [м].'},
            examples: [{w: 'man', m: 'm'}, {w: 'summer', m: 'mm'}, {w: 'time', m: 'm'}],
        },
        {
            ipa: 'n', spell: 'n', art: [ST.n], anim: null,
            desc: {en: 'Alveolar nasal: the tip seals on the ridge, air escapes through the nose.', ru: 'Альвеолярный носовой: кончик языка у бугорка, воздух идёт через нос.'},
            hint: {ru: 'как [н], язык выше, чем для русского.'},
            examples: [{w: 'no', m: 'n'}, {w: 'sun', m: 'n'}, {w: 'funny', m: 'nn'}],
        },
        {
            ipa: 'ŋ', spell: 'ng, n(k)', art: [ST.ŋ], anim: null,
            desc: {en: 'Velar nasal: the back of the tongue seals on the soft palate, air escapes through the nose. Never released as [g].', ru: 'Заднеязычный носовой: задняя часть языка у мягкого нёба, воздух через нос. Взрыва [г] нет.'},
            hint: {ru: 'нет в русском! как [н], но язык задний; в конце слова не добавляйте [г]: sing без [г] на конце.'},
            examples: [{w: 'sing', m: 'ng'}, {w: 'think', m: 'n'}, {w: 'finger', m: 'ng'}],
        },
    ],
    approximants: [
        {
            ipa: 'l', spell: 'l, ll', art: [ST.l], anim: null,
            desc: {en: 'Alveolar lateral: the tip seals on the ridge and air flows around the sides of the tongue.', ru: 'Альвеолярный боковой: кончик языка у бугорка, воздух идёт по бокам.'},
            hint: {ru: 'похоже на [л], но кончик твёрже на бугорке; в конце слова звук темнее.'},
            examples: [{w: 'leg', m: 'l'}, {w: 'feel', m: 'l'}, {w: 'yellow', m: 'll'}],
        },
        {
            ipa: 'r', spell: 'r, rr', art: [ST.ɹ], anim: null,
            desc: {en: 'Postalveolar approximant: the tongue bunches and pulls back without touching the roof, lips slightly rounded.', ru: 'Аппроксимант: язык собран и оттянут назад, не касаясь нёба, губы чуть округлены.'},
            hint: {ru: 'не раскатистый [р]! кончик загибается назад и ничего не вибрирует: red без дрожи.'},
            rp: {en: 'RP pronounces /r/ only before vowels: car has no r in British English.', ru: 'По-британски [r] звучит только перед гласными: в car его нет.'},
            examples: [{w: 'red', m: 'r'}, {w: 'carry', m: 'rr'}, {w: 'write', m: 'wr'}],
        },
        {
            ipa: 'j', spell: 'y, u(e)', art: [ST.j], anim: null,
            desc: {en: 'Palatal approximant: the front of the tongue rises toward the hard palate, like a quick /i/ glide.', ru: 'Палатальный аппроксимант: передняя часть языка поднимается к твёрдому нёбу — короткое скольжение от [и].'},
            hint: {ru: 'это русский [й]: yes ≈ [йес].'},
            examples: [{w: 'yes', m: 'y'}, {w: 'music', m: 'u'}, {w: 'computer', m: 'u'}],
        },
        {
            ipa: 'w', spell: 'w, u', art: [ST.w], anim: null,
            desc: {en: 'Labial-velar approximant: lips round tightly while the back of the tongue rises — a quick /u/ glide.', ru: 'Губно-заднеязычный аппроксимант: губы округлены, задняя часть языка поднята — скольжение от [у].'},
            hint: {ru: 'губы трубочкой как для [у] + быстрый переход к следующему звуку: we ≈ [уи́].'},
            examples: [{w: 'we', m: 'w'}, {w: 'window', m: 'w'}, {w: 'quick', m: 'u'}],
        },
    ],
};

// Russian
const RU_PAIRS = [
    {
        hard: {ipa: 'п', spell: 'п', art: [ST.p], anim: null, desc: {en: 'Hard bilabial stop, like English /p/.', ru: 'Твёрдый губно-губной взрывной: губы смыкаются и размыкаются.'}, hint: {en: 'like "p" in "pen", without extra aspiration.'}, examples: [{w: 'пол', m: 'п'}, {w: 'суп', m: 'п'}]},
        soft: {ipa: 'пʲ', spell: 'пь, пе, пи', art: [soft('p')], anim: null, desc: {en: 'Palatalized /p/: the tongue body rises toward the hard palate during the stop — a "py" flavor.', ru: 'Мягкий /п/: тело языка поднимается к твёрдому нёбу — согласный с призвуком [и].'}, hint: {en: 'like "p" in "pure" — say "p" with a quick "y" glide.'}, examples: [{w: 'пить', m: 'п'}, {w: 'пена', m: 'пе'}]},
    },
    {
        hard: {ipa: 'б', spell: 'б', art: [ST.b], anim: null, desc: {en: 'Hard voiced bilabial stop.', ru: 'Твёрдый звонкий губно-губной взрывной.'}, hint: {en: 'like "b" in "book".'}, examples: [{w: 'брат', m: 'б'}, {w: 'зуб', m: 'б'}]},
        soft: {ipa: 'бʲ', spell: 'бь, бе, би', art: [soft('b')], anim: null, desc: {en: 'Palatalized /b/ — the body of the tongue rises toward the hard palate.', ru: 'Мягкий /б/: тело языка поднимается к твёрдому нёбу.'}, hint: {en: 'like "b" in "beauty" — "b" plus a quick "y" glide.'}, examples: [{w: 'бить', m: 'б'}, {w: 'обед', m: 'бе'}]},
    },
    {
        hard: {ipa: 'ф', spell: 'ф', art: [ST.f], anim: null, desc: {en: 'Hard labiodental fricative.', ru: 'Твёрдый губно-зубной фрикативный.'}, hint: {en: 'like "f" in "fun".'}, examples: [{w: 'фонарь', m: 'ф'}, {w: 'шарф', m: 'ф'}]},
        soft: {ipa: 'фʲ', spell: 'фи, фе', art: [soft('f')], anim: null, desc: {en: 'Palatalized /f/ — body of the tongue raised toward the palate.', ru: 'Мягкий /ф/: тело языка поднято к твёрдому нёбу.'}, hint: {en: 'like "f" in "few" — "f" plus a quick "y" glide.'}, examples: [{w: 'фильм', m: 'ф'}, {w: 'фикус', m: 'фи'}]},
    },
    {
        hard: {ipa: 'в', spell: 'в', art: [ST.v], anim: null, desc: {en: 'Hard labiodental fricative, voiced.', ru: 'Твёрдый звонкий губно-зубной фрикативный.'}, hint: {en: 'like "v" in "very".'}, examples: [{w: 'вода', m: 'в'}, {w: 'внук', m: 'вн'}]},
        soft: {ipa: 'вʲ', spell: 'ви, ве', art: [soft('v')], anim: null, desc: {en: 'Palatalized /v/ — body of the tongue raised toward the palate.', ru: 'Мягкий /в/: тело языка поднято к твёрдому нёбу.'}, hint: {en: 'like "v" in "view" — "v" plus a quick "y" glide.'}, examples: [{w: 'весна', m: 'ве'}, {w: 'виноград', m: 'ви'}]},
    },
    {
        hard: {ipa: 'т', spell: 'т', art: [ST.т], anim: null, desc: {en: 'Hard dental stop: the tip touches the upper front teeth (further forward than English /t/).', ru: 'Твёрдый зубной взрывной: кончик языка касается верхних передних зубов.'}, hint: {en: 'like "t" but with the tongue at the teeth, not the ridge.'}, examples: [{w: 'там', m: 'т'}, {w: 'стол', m: 'т'}]},
        soft: {ipa: 'тʲ', spell: 'ть, те, ти', art: [soft('т')], anim: null, desc: {en: 'Palatalized dental stop — "ty" with the body of the tongue raised.', ru: 'Мягкий зубной взрывной: тело языка поднято к нёбу.'}, hint: {en: 'like "ty" in "hit you", said together.'}, examples: [{w: 'тень', m: 'те'}, {w: 'титан', m: 'ти'}]},
    },
    {
        hard: {ipa: 'д', spell: 'д', art: [ST.d], anim: null, desc: {en: 'Hard voiced dental stop, tongue tip at the upper teeth.', ru: 'Твёрдый звонкий зубной взрывной: кончик языка у верхних зубов.'}, hint: {en: 'like "d" but dental, tongue at the teeth.'}, examples: [{w: 'дом', m: 'д'}, {w: 'да', m: 'д'}]},
        soft: {ipa: 'дʲ', spell: 'дь, де, ди', art: [soft('d')], anim: null, desc: {en: 'Palatalized dental stop — "dy" with a raised tongue body.', ru: 'Мягкий зубной взрывной: тело языка поднято к нёбу.'}, hint: {en: 'like "dy" in "did you", said together.'}, examples: [{w: 'день', m: 'де'}, {w: 'диво', m: 'ди'}]},
    },
    {
        hard: {ipa: 'с', spell: 'с', art: [ST.с], anim: null, desc: {en: 'Hard dental sibilant: the blade is near the upper teeth, tip low.', ru: 'Твёрдый зубной свистящий: передняя часть языка у верхних зубов, кончик опущен.'}, hint: {en: 'like "s" in "soup", but hiss at the teeth.'}, examples: [{w: 'сок', m: 'с'}, {w: 'нос', m: 'с'}]},
        soft: {ipa: 'сʲ', spell: 'си, се', art: [soft('с')], anim: null, desc: {en: 'Palatalized sibilant — "sy" with a raised tongue body.', ru: 'Мягкий свистящий: тело языка поднято к твёрдому нёбу.'}, hint: {en: 'like "s" in "this year" blended together.'}, examples: [{w: 'сено', m: 'се'}, {w: 'сила', m: 'си'}]},
    },
    {
        hard: {ipa: 'з', spell: 'з', art: [ST.z], anim: null, desc: {en: 'Hard voiced dental sibilant.', ru: 'Твёрдый звонкий зубной свистящий.'}, hint: {en: 'like "z" in "zoo".'}, examples: [{w: 'зал', m: 'з'}, {w: 'коза', m: 'з'}]},
        soft: {ipa: 'зʲ', spell: 'зи, зе', art: [soft('z')], anim: null, desc: {en: 'Palatalized voiced sibilant — "zy".', ru: 'Мягкий звонкий свистящий: тело языка поднято к нёбу.'}, hint: {en: 'like "z" said with a quick "y" glide.'}, examples: [{w: 'зима', m: 'зи'}, {w: 'зебра', m: 'зе'}]},
    },
    {
        hard: {ipa: 'н', spell: 'н', art: [ST.n], anim: null, desc: {en: 'Hard dental nasal: tip at the upper teeth, air through the nose.', ru: 'Твёрдый зубной носовой: кончик языка у верхних зубов, воздух через нос.'}, hint: {en: 'like "n" in "not", but dental.'}, examples: [{w: 'нос', m: 'н'}, {w: 'сон', m: 'н'}]},
        soft: {ipa: 'нʲ', spell: 'нь, не, ни', art: [soft('n')], anim: null, desc: {en: 'Palatalized nasal — "ny".', ru: 'Мягкий носовой: тело языка поднято к твёрдому нёбу.'}, hint: {en: 'like "ny" in "onion".'}, examples: [{w: 'нет', m: 'не'}, {w: 'нитка', m: 'ни'}]},
    },
    {
        hard: {ipa: 'м', spell: 'м', art: [ST.m], anim: null, desc: {en: 'Hard bilabial nasal: lips sealed, air through the nose.', ru: 'Твёрдый губно-губной носовой: губы сомкнуты, воздух через нос.'}, hint: {en: 'like "m" in "mom".'}, examples: [{w: 'мама', m: 'м'}, {w: 'дом', m: 'м'}]},
        soft: {ipa: 'мʲ', spell: 'мь, ме, ми', art: [soft('m')], anim: null, desc: {en: 'Palatalized bilabial nasal — "my".', ru: 'Мягкий губно-губной носовой: тело языка поднято к нёбу.'}, hint: {en: 'like "m" in "music".'}, examples: [{w: 'мёд', m: 'мё'}, {w: 'миска', m: 'ми'}]},
    },
    {
        hard: {ipa: 'л', spell: 'л', art: [ST.л], anim: null, desc: {en: 'Hard "dark" l: the tip touches the teeth while the back of the tongue is raised — a velarized l.', ru: 'Твёрдый «тёмный» [л]: кончик у зубов, задняя часть языка приподнята (веляризация).'}, hint: {en: 'unlike English "l": keep the back of the tongue raised, the sound is darker, like "l" plus a hint of "w".'}, examples: [{w: 'лампа', m: 'л'}, {w: 'стол', m: 'л'}]},
        soft: {ipa: 'лʲ', spell: 'ли, ле, ль', art: [soft('л')], anim: null, desc: {en: 'Palatalized l: tip at the teeth, body raised — a light, clear l.', ru: 'Мягкий [л’]: кончик у зубов, тело языка поднято — звук светлый.'}, hint: {en: 'like "l" in "million" — light and forward.'}, examples: [{w: 'лист', m: 'ли'}, {w: 'лебедь', m: 'ле'}]},
    },
    {
        hard: {ipa: 'р', spell: 'р', art: [ST.р], anim: null, desc: {en: 'Alveolar trill: the tip vibrates against the ridge in the airstream.', ru: 'Альвеолярный дрожащий (раскатистый): кончик языка вибрирует у бугорка.'}, hint: {en: 'the rolled r: let the tongue tip vibrate, like Scottish "r".'}, examples: [{w: 'река', m: 'р'}, {w: 'двор', m: 'р'}]},
        soft: {ipa: 'рʲ', spell: 'ря, ри, ре', art: [soft('р')], anim: null, desc: {en: 'Palatalized trill — body of the tongue raised while the tip trills.', ru: 'Мягкий раскатистый: кончик вибрирует, тело языка поднято к нёбу.'}, hint: {en: 'a rolled r said with a "y" glide, like "ry".'}, examples: [{w: 'рябь', m: 'ря'}, {w: 'берёза', m: 'рё'}]},
    },
    {
        hard: {ipa: 'к', spell: 'к', art: [ST.k], anim: null, desc: {en: 'Hard velar stop: back of the tongue against the soft palate.', ru: 'Твёрдый заднеязычный взрывной: задняя часть языка смыкается с мягким нёбом.'}, hint: {en: 'like "k" in "cat".'}, examples: [{w: 'кот', m: 'к'}, {w: 'сок', m: 'к'}]},
        soft: {ipa: 'кʲ', spell: 'ки, ке', art: [soft('k')], anim: null, desc: {en: 'Palatalized velar stop — the closure moves forward, toward the hard palate.', ru: 'Мягкий заднеязычный взрывной: смычка продвигается вперёд, к твёрдому нёбу.'}, hint: {en: 'like "k" in "key" — closer to a "ky" sound.'}, examples: [{w: 'кит', m: 'ки'}, {w: 'кепка', m: 'ке'}]},
    },
    {
        hard: {ipa: 'г', spell: 'г', art: [ST.g], anim: null, desc: {en: 'Hard voiced velar stop.', ru: 'Твёрдый звонкий заднеязычный взрывной.'}, hint: {en: 'like "g" in "go", a clean stop — not the fricative [h].'}, examples: [{w: 'год', m: 'г'}, {w: 'нога', m: 'га'}]},
        soft: {ipa: 'гʲ', spell: 'ги, ге', art: [soft('g')], anim: null, desc: {en: 'Palatalized velar stop — closure shifted toward the hard palate.', ru: 'Мягкий заднеязычный взрывной: смычка продвинута вперёд.'}, hint: {en: 'like "g" in "gear".'}, examples: [{w: 'гитара', m: 'ги'}, {w: 'герой', m: 'ге'}]},
    },
    {
        hard: {ipa: 'х', spell: 'х', art: [ST.х], anim: null, desc: {en: 'Hard velar fricative: friction at the soft palate.', ru: 'Твёрдый заднеязычный фрикативный: шум у мягкого нёба.'}, hint: {en: 'like "ch" in Scottish "loch" or German "Bach".'}, examples: [{w: 'холод', m: 'х'}, {w: 'хлеб', m: 'х'}]},
        soft: {ipa: 'хʲ', spell: 'хи, хе', art: [soft('х')], anim: null, desc: {en: 'Palatalized velar fricative — friction moved toward the hard palate.', ru: 'Мягкий заднеязычный фрикативный: шум продвинут к твёрдому нёбу.'}, hint: {en: 'like a light, forward "h" with a "y" glide — "hy".'}, examples: [{w: 'химия', m: 'хи'}, {w: 'хижина', m: 'хи'}]},
    },
];

const RU = {
    vowels: [
        {
            ipa: 'а', spell: 'а, я', art: [ST.а], anim: null,
            desc: {en: 'Low central vowel, unrounded. The tongue lies low, mouth wide.', ru: 'Гласный нижнего подъёма, среднего ряда. Язык лежит низко, рот открыт широко.'},
            hint: {en: 'like "a" in "father", but shorter.'},
            examples: [{w: 'мама', m: 'а'}, {w: 'да', m: 'а'}],
        },
        {
            ipa: 'э', spell: 'э, е', art: [ST.ɛ], anim: null,
            desc: {en: 'Mid front vowel, unrounded.', ru: 'Гласный среднего подъёма переднего ряда, неогубленный.'},
            hint: {en: 'like "e" in "bet".'},
            examples: [{w: 'это', m: 'э'}, {w: 'мэр', m: 'э'}],
        },
        {
            ipa: 'ы', spell: 'ы', art: [ST.ы], anim: null,
            desc: {en: 'High central vowel, unrounded: the tongue is high and pulled back, lips relaxed. The hardest Russian vowel.', ru: 'Гласный верхнего подъёма среднего ряда: язык высоко и оттянут назад, губы расслаблены.'},
            hint: {en: 'no English equivalent: say "ee" and slowly pull the tongue back, lips flat — between "ee" and "oo".'},
            examples: [{w: 'сын', m: 'ы'}, {w: 'мы', m: 'ы'}],
        },
        {
            ipa: 'о', spell: 'о', art: [ST.ɔ], anim: null,
            desc: {en: 'Mid back vowel, rounded. Only stressed; unstressed о reduces to а-like sounds (аканье).', ru: 'Гласный среднего подъёма заднего ряда, огубленный. Только под ударением; без ударения редуцируется (аканье).'},
            hint: {en: 'like "o" in "more", shorter; unstressed "o" sounds like "a": молоко ≈ "malakó".'},
            examples: [{w: 'дом', m: 'о'}, {w: 'нос', m: 'о'}],
        },
        {
            ipa: 'у', spell: 'у', art: [ST.u], anim: null,
            desc: {en: 'High back vowel, tightly rounded.', ru: 'Гласный верхнего подъёма заднего ряда, огубленный.'},
            hint: {en: 'like "oo" in "boot", lips firmly rounded and forward.'},
            examples: [{w: 'лук', m: 'у'}, {w: 'ухо', m: 'у'}],
        },
        {
            ipa: 'и', spell: 'и', art: [ST.i], anim: null,
            desc: {en: 'High front vowel, unrounded.', ru: 'Гласный верхнего подъёма переднего ряда, неогубленный.'},
            hint: {en: 'like "ee" in "see" but short and relaxed.'},
            examples: [{w: 'мир', m: 'и'}, {w: 'ива', m: 'и'}],
        },
    ],
    pairs: RU_PAIRS,
    always_hard: [
        {
            ipa: 'ж', spell: 'ж', art: [ST.ш], anim: null,
            desc: {en: 'Voiced retroflex sibilant, always hard: the tip is curled back, lips slightly rounded.', ru: 'Звонкий шипящий, всегда твёрдый: кончик языка завёрнут назад, губы чуть округлены.'},
            hint: {en: 'like "s" in "pleasure" but harder and further back — no Russian-style soft "zh" exists.'},
            examples: [{w: 'жук', m: 'ж'}, {w: 'нож', m: 'ж'}],
        },
        {
            ipa: 'ш', spell: 'ш', art: [ST.ш], anim: null,
            desc: {en: 'Voiceless retroflex sibilant, always hard: tip curled back, hollow under the tongue.', ru: 'Глухой шипящий, всегда твёрдый: кончик завёрнут назад, под языком «чашечка».'},
            hint: {en: 'like "sh" in "ship" but harder, further back, lips less rounded.'},
            examples: [{w: 'школа', m: 'ш'}, {w: 'душ', m: 'ш'}],
        },
        {
            ipa: 'ц', spell: 'ц', art: [ST.т, ST.с], anim: null,
            desc: {en: 'Dental affricate, always hard: a [т] stop released into [с] friction.', ru: 'Зубная аффриката, всегда твёрдая: смычка [т] переходит в свист [с].'},
            hint: {en: 'one sound: "ts" as in "cats", said together.'},
            examples: [{w: 'цирк', m: 'ц'}, {w: 'конец', m: 'ц'}],
        },
    ],
    always_soft: [
        {
            ipa: 'ч', spell: 'ч', art: [soft('т'), ST.щ], anim: null,
            desc: {en: 'Palatalized affricate, always soft: a [ть] stop released into [щ]-like friction.', ru: 'Аффриката, всегда мягкая: смычка [т’] переходит в шипящий [щ].'},
            hint: {en: 'like "ch" in "cheese" — softer than English "ch" in "chat".'},
            examples: [{w: 'чай', m: 'ч'}, {w: 'ночь', m: 'ч'}],
        },
        {
            ipa: 'щ', spell: 'щ', art: [ST.щ], anim: null,
            desc: {en: 'Long soft sibilant: the front of the tongue is raised toward the palate, the sound is drawn out.', ru: 'Долгий мягкий шипящий: передняя часть языка поднята к твёрдому нёбу, звук тянется.'},
            hint: {en: 'a long, soft "sh-sh" — like "fresh shrimp" said slowly, or "sh" with a strong "y" glide.'},
            examples: [{w: 'щука', m: 'щ'}, {w: 'борщ', m: 'щ'}],
        },
        {
            ipa: 'й', spell: 'й', art: [ST.j], anim: null,
            desc: {en: 'Palatal glide, always soft: a quick rise of the tongue toward the hard palate.', ru: 'Палатальный сонорный, всегда мягкий: быстрый подъём языка к твёрдому нёбу.'},
            hint: {en: 'like "y" in "yes" or "boy".'},
            examples: [{w: 'йога', m: 'й'}, {w: 'май', m: 'й'}],
        },
    ],
};

export const PHONEME_CHART = {
    en: {
        label: {en: 'English', ru: 'Английский'},
        groups: [
            {id: 'vowels', items: EN.vowels},
            {id: 'diphthongs', items: EN.diphthongs},
            {id: 'stops', items: EN.stops},
            {id: 'affricates', items: EN.affricates},
            {id: 'fricatives', items: EN.fricatives},
            {id: 'nasals', items: EN.nasals},
            {id: 'approximants', items: EN.approximants},
        ],
    },
    ru: {
        label: {en: 'Russian', ru: 'Русский'},
        groups: [
            {id: 'vowels', items: RU.vowels},
            {id: 'pairs', items: RU.pairs},
            {id: 'always_hard', items: RU.always_hard},
            {id: 'always_soft', items: RU.always_soft},
        ],
    },
};

export const PHONEME_LANGUAGES = ['en', 'ru'];

// Credits for the diagram lineage (CC0 imposes no obligation; we credit
// anyway because the tract proportions descend from the Wright & McCloy set).
export const DIAGRAM_CREDITS = 'Diagram lineage based on CC0 mid-sagittal articulation artwork by Richard Wright & Dan McCloy (phonetics-teaching-assets), redrawn as parametric SVG.';
