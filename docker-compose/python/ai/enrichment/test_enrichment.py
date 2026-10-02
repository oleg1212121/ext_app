"""Tests for the enrichment package (plain python, no pytest).

Deterministic parts (en_stress, phrasal) always run; the Silero
dependent ru_stress tests are skipped with a notice when the package or model
data is unavailable.

Run from anywhere (adds the package root to sys.path):

    docker exec ext_python python /app/ai/enrichment/test_enrichment.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from ai.enrichment import en_stress, phrasal  # noqa: E402
from ai.enrichment.ru_stress import COMBINING_ACUTE as ACUTE  # noqa: E402

A = "\\u0301"  # combining acute, escaped to keep expectations unambiguous

failures = []


def check(name, actual, expected):
    if actual != expected:
        failures.append(f"{name}\n  expected: {ascii(expected)}\n  actual:   {ascii(actual)}")


# --- en_stress.mark_word ----------------------------------------------------
check(
    "en: primary stress on first syllable",
    en_stress.mark_word("beautiful", ["/ˈbjuː.tɪ.fl/"]),
    "be" + ACUTE + "autiful",
)
check(
    "en: stress on second syllable",
    en_stress.mark_word("pronounces", ["/prəˈnʌn.sɪz/"]),
    "pron" + "o" + ACUTE + "unces",
)
check(
    "en: dictionary",
    en_stress.mark_word("dictionary", ["/ˈdɪk.ʃə.nə.ɹi/"]),
    "d" + "i" + ACUTE + "ctionary",
)
check(
    "en: information (stress past prefix)",
    en_stress.mark_word("information", ["/ˌɪn.fəˈmeɪ.ʃən/"]),
    "inform" + "a" + ACUTE + "tion",
)
check(
    "en: pronunciation (proportional alignment)",
    en_stress.mark_word("pronunciation", ["/prəˌnʌn.siˈeɪ.ʃən/"]),
    "pronunci" + "a" + ACUTE + "tion",
)
check(
    "en: unstressed function word stays plain",
    en_stress.mark_word("the", ["/ðə/"]),
    "the",
)
check(
    "en: no transcription stays plain",
    en_stress.mark_word("smizards", None),
    "smizards",
)
check(
    "en: second variant used when first lacks ˈ",
    en_stress.mark_word("contract", ["/kɒn.trækt/", "/kənˈtrækt/"]),
    "contr" + "a" + ACUTE + "ct",
)
check(
    "en: idempotent on already-marked word",
    en_stress.mark_word("be" + ACUTE + "autiful", ["/ˈbjuː.tɪ.fl/"]),
    "be" + ACUTE + "autiful",
)

# --- en_stress: ADR 0053 (pyphen alignment, silent-e, monosyllables, parts) --
# advánce/becáuse: the old proportional map saturated onto the silent final e.
check(
    "en: final-silent-e excluded (advance)",
    en_stress.mark_word("advance", ["/ədˈvɑːns/"]),
    "adv" + "a" + ACUTE + "nce",
)
check(
    "en: final-silent-e excluded (because)",
    en_stress.mark_word("because", ["/bɪˈkɒz/"]),
    "bec" + "a" + ACUTE + "use",
)
check(
    "en: believe (pyphen syllable alignment)",
    en_stress.mark_word("believe", ["/bɪˈliːv/"]),
    "bel" + "i" + ACUTE + "eve",
)
check(
    "en: final-stress word keeps the final syllable (referee)",
    en_stress.mark_word("referee", ["/ˌɹɛf.əˈɹiː/"]),
    "refer" + "e" + ACUTE + "e",
)
# CMUdict-style variants: monosyllables carry ˈ, inflected forms are present.
check(
    "en: monosyllable marked (turned)",
    en_stress.mark_word("turned", ["/tˈɜːnd/"]),
    "t" + "u" + ACUTE + "rned",
)
check(
    "en: monosyllable marked (cat)",
    en_stress.mark_word("cat", ["/kˈæt/"]),
    "c" + "a" + ACUTE + "t",
)
check(
    "en: inflected form with own variant (smiled)",
    en_stress.mark_word("smiled", ["/smˈaɪld/"]),
    "sm" + "i" + ACUTE + "led",
)
check(
    "en: uppercase surface marks the uppercase vowel",
    en_stress.mark_word("GAMBLERS", ["/ɡˈæmblɚz/"]),
    "G" + "A" + ACUTE + "MBLERS",
)
check(
    "en: hyphenated compound marked per part",
    en_stress.mark_word("seven-sided", None, [
        {"surface": "seven", "ipa": ["/ˈsɛvən/"]},
        {"surface": "sided", "ipa": ["/sˈaɪdɪd/"]},
    ]),
    "s" + "e" + ACUTE + "ven-s" + "i" + ACUTE + "ded",
)
check(
    "en: hyphenated compound with unresolvable part stays plain there",
    en_stress.mark_word("seven-sided", None, [
        {"surface": "seven", "ipa": ["/ˈsɛvən/"]},
        {"surface": "sided", "ipa": None},
    ]),
    "s" + "e" + ACUTE + "ven-sided",
)
check(
    "en: parts ignored when whole-token variant resolves",
    en_stress.mark_word("well-known", ["/ˌwɛlˈnəʊn/"], [
        {"surface": "well", "ipa": ["/wɛl/"]},
        {"surface": "known", "ipa": ["/nəʊn/"]},
    ]),
    "well-kn" + "o" + ACUTE + "wn",
)

# --- en_stress.mark_sentence (span rebuild) ---------------------------------
import regex as _regex

_WORD_RE = _regex.compile(r"[\p{L}\p{M}]+(?:['’\-][\p{L}\p{M}]+)*")

text = "She pronounces it beautifully."
IPA = {
    "she": ["/ʃi/"],
    "pronounces": ["/prəˈnʌn.sɪz/"],
    "it": ["/ɪt/"],
    "beautifully": ["/ˈbjuː.tɪ.fli/"],
}
tokens = [
    {"surface": m.group(0), "start": m.start(), "end": m.end(), "ipa": IPA[m.group(0).lower()]}
    for m in _WORD_RE.finditer(text)
]
check(
    "en: sentence rebuild preserves punctuation and spans",
    en_stress.mark_sentence(text, tokens),
    "She pron" + "o" + ACUTE + "unces it b" + "e" + ACUTE + "autifully.",
)
check(
    "en: digraph ay marks the a (away)",
    en_stress.mark_word("away", ["/əˈweɪ/"]),
    "aw" + "a" + ACUTE + "y",
)

# --- phrasal: spaCy multi-word verbs (ADR 0059; skipped without the model) ---
try:
    _ = phrasal._nlp()
    _HAS_SPACY = True
except RuntimeError:
    _HAS_SPACY = False
    print("spaCy / en_core_web_md unavailable — phrasal tests skipped")


def _en_tokens(text, verb_lemmas=None):
    """PHP-style tokens (WordTokenizer regex) with optional verb_lemmas."""
    tokens = [
        {"surface": m.group(0), "start": m.start(), "end": m.end()}
        for m in _WORD_RE.finditer(text)
    ]
    for key, lemmas in (verb_lemmas or {}).items():
        for token in tokens:
            if token["surface"].lower() == key:
                token["verb_lemmas"] = lemmas
    return tokens


def _find(text, lexicon=(), verb_lemmas=None):
    return phrasal.find_multiword_verbs(
        [{"id": 1, "text": text, "tokens": _en_tokens(text, verb_lemmas)}],
        set(lexicon),
    )[0]


if _HAS_SPACY:
    # Particle verbs hit on parser evidence alone — no lexicon needed.
    check(
        "mw: particle verb hits without a lexicon entry",
        _find("She gave up smoking."),
        [{"verb": "gave", "particles": ["up"], "start": 4, "end": 11, "phrase": "give up"}],
    )
    check(
        "mw: separated particle spans the whole chunk",
        _find("He looked it up on the network."),
        [{"verb": "looked", "particles": ["up"], "start": 3, "end": 15, "phrase": "look up"}],
    )
    # Prepositional verbs are dictionary-gated.
    check(
        "mw: prepositional verb with lexicon entry",
        _find("She depends on her network.", ["depend on"]),
        [{"verb": "depends", "particles": ["on"], "start": 4, "end": 14, "phrase": "depend on"}],
    )
    check(
        "mw: prepositional verb without lexicon entry stays a miss",
        _find("She sat in the car.", ["sit under"]),
        [],
    )
    check(
        "mw: phrasal-prepositional combo spans through the preposition",
        _find("She came up with a brilliant plan.", ["come up with"]),
        [{"verb": "came", "particles": ["up", "with"], "start": 4, "end": 16, "phrase": "come up with"}],
    )
    # The reported false positives ( AUX excluded; no prt/prep children).
    check("mw: modal+aux verb is no hit", _find("She could have given more.", ["give up", "give"]), [])
    check("mw: negated plain verb is no hit", _find("He did not say a word.", ["say"]), [])
    check("mw: punctuation-adjacent clause is no hit", _find("It was done, it was over.", ["do it"]), [])
    check("mw: passive participle without particle is no hit", _find("The work was done by noon.", ["do"]), [])
    # Directional adverbs: a goal/path preposition right after the particle
    # reads as direction, not a multi-word verb (ADR 0059 v3).
    check("mw: directional over-toward is no hit", _find("The ringmaster swung over toward Max."), [])
    check("mw: directional down-to is no hit", _find("She followed Max down to the basement."), [])
    check(
        "mw: locative preposition after particle still hits",
        _find("He looked it up on the network."),
        [{"verb": "looked", "particles": ["up"], "start": 3, "end": 15, "phrase": "look up"}],
    )
    check(
        "mw: infinitival to after particle still hits",
        _find("She looked it up to check the facts."),
        [{"verb": "looked", "particles": ["up"], "start": 4, "end": 16, "phrase": "look up"}],
    )
    # One hit per verb; two verbs give two hits.
    check(
        "mw: two verbs, two hits",
        _find("She gave up and looked it up."),
        [
            {"verb": "gave", "particles": ["up"], "start": 4, "end": 11, "phrase": "give up"},
            {"verb": "looked", "particles": ["up"], "start": 16, "end": 28, "phrase": "look up"},
        ],
    )
    # verb_lemmas (ADR 0058) ride along as extra lemma candidates.
    check(
        "mw: verb_lemmas hints still flow through",
        _find("She gave up smoking.", ["give up"], {"gave": ["give"]}),
        [{"verb": "gave", "particles": ["up"], "start": 4, "end": 11, "phrase": "give up"}],
    )
    # Empty lexicon + empty hints still work end to end.
    check("mw: plain sentence with empty lexicon", _find("The dog sleeps."), [])

# --- ru_stress (Silero; skipped when unavailable) -----------------------------
try:
    from silero_stress import load_accentor

    from ai.enrichment import ru_stress

    accentor = load_accentor()

    def tok(text):
        """Tokenize like WordTokenizer and build span dicts."""
        out = []
        for m in ru_stress.WORD_RE.finditer(text.replace("+", "")):
            out.append({"surface": m.group(0), "start": m.start(), "end": m.end(), "stressed": None})
        return out

    sentence = "Она произносит это красиво, но часто теряет мелодию."
    marked = ru_stress.mark_sentence(sentence, tok(sentence), accentor)
    check("ru: произносит gets acute", "произно" + ACUTE + "сит" in marked, True)
    check("ru: красиво gets acute", "краси" + ACUTE + "во" in marked, True)
    check("ru: punctuation preserved", marked.count(",") == sentence.count(","), True)

    yo_sentence = "Еще не все замки готовы."
    marked = ru_stress.mark_sentence(yo_sentence, tok(yo_sentence), accentor)
    check("ru: е→ё conversion (ещё)", "Ещё" in marked, True)

    premarked = "при́вычно звучит."
    marked = ru_stress.mark_sentence(premarked, tok(premarked), accentor)
    # привы́чно (1) + звучи́т (1): the pre-existing mark was replaced, not added to.
    check("ru: no double marks on pre-marked input", (marked.count(ACUTE) == 2 and "привы́чно" in marked), True)

    # Dictionary fallback unit checks (Silero-independent).
    from ai.enrichment.ru_stress import _dict_fallback

    check(
        "ru: dict fallback applies unique stress position",
        _dict_fallback("свободного", ["свобо́дного"]),
        "свобо́дного",
    )
    check(
        "ru: dict fallback skips ambiguous candidates",
        _dict_fallback("замки", ["за́мки", "замки́"]),
        "замки",
    )
    check(
        "ru: dict fallback yo candidate converts е→ё",
        _dict_fallback("еще", ["ещё"]),
        "ещё",
    )
except ImportError:
    print("silero-stress unavailable — ru_stress tests skipped")

if failures:
    print(f"FAIL ({len(failures)}):")
    for f in failures:
        print(" -", f)
    sys.exit(1)
print("all enrichment tests passed")
