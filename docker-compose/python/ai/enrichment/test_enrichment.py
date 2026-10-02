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

# --- phrasal -----------------------------------------------------------------
def toks(*specs):
    return [
        {"surface": s, "start": st, "end": en, "cls": c, **({"lemma": lm} if lm else {})}
        for s, st, en, c, lm in specs
    ]

hits = phrasal.find_phrasal_verbs(
    toks(("She", 0, 3, "pron", None), ("gave", 4, 8, "verb", "give"), ("up", 9, 11, "prep", None), ("smoking", 12, 19, "noun", None)),
    {"give up", "give"},
)
check(
    "phrasal: inflected lead matches lemma headword",
    hits,
    [{"verb": "gave", "particles": ["up"], "start": 4, "end": 11, "phrase": "give up"}],
)
hits = phrasal.find_phrasal_verbs(
    toks(("He", 0, 2, "pron", None), ("kicked", 3, 9, "verb", "kick"), ("the", 10, 13, "det", None), ("bucket", 14, 20, "noun", None)),
    {"kick the bucket"},
)
check(
    "phrasal: three-word idiom wins over shorter/none",
    hits,
    [{"verb": "kicked", "particles": ["the", "bucket"], "start": 3, "end": 20, "phrase": "kick the bucket"}],
)
hits = phrasal.find_phrasal_verbs(
    toks(("The", 0, 3, "det", None), ("setup", 4, 9, "noun", "set up"), ("failed", 10, 16, "verb", "fail")),
    {"set up"},
)
check("phrasal: non-verb lead is not a hit", hits, [])
hits = phrasal.find_phrasal_verbs(
    toks(("She", 0, 3, "pron", None), ("gave", 4, 8, "verb", "give"), ("up", 9, 11, "prep", None)),
    {},
)
check("phrasal: empty lexicon yields nothing", hits, [])
hits = phrasal.find_phrasal_verbs(
    toks(("Do", 0, 2, "verb", "do"), ("give", 3, 7, "verb", "give"), ("up", 8, 10, "prep", None)),
    {"give up"},
)
check("phrasal: hits consume their tokens (no inner re-match)", hits, [{"verb": "give", "particles": ["up"], "start": 3, "end": 10, "phrase": "give up"}])

# --- phrasal: verb-lemma candidates (ADR 0058) --------------------------------
# The lead's class hint can say noun (the stained-glass "came" outranks the
# verb in CLASS_PRIORITY); the caller's verb_lemmas rescue the match.
hits = phrasal.find_phrasal_verbs(
    [
        {"surface": "She", "start": 0, "end": 3, "cls": "pron"},
        {"surface": "came", "start": 4, "end": 8, "cls": "noun", "verb_lemmas": ["come"]},
        {"surface": "forward", "start": 9, "end": 16, "cls": "prep"},
        {"surface": "slowly", "start": 17, "end": 23, "cls": "adv"},
    ],
    {"come forward"},
)
check(
    "phrasal: noun-hijacked lead matches via verb_lemmas",
    hits,
    [{"verb": "came", "particles": ["forward"], "start": 4, "end": 16, "phrase": "come forward"}],
)
hits = phrasal.find_phrasal_verbs(
    [
        {"surface": "came", "start": 0, "end": 4, "cls": "noun"},
        {"surface": "forward", "start": 5, "end": 12, "cls": "prep"},
    ],
    {"come forward"},
)
check("phrasal: non-verb lead without verb_lemmas stays a miss", hits, [])
# The linked lemma wins over a verb_lemmas entry when both are candidates.
hits = phrasal.find_phrasal_verbs(
    [
        {"surface": "gave", "start": 0, "end": 4, "cls": "verb", "lemma": "give", "verb_lemmas": ["give", "gave"]},
        {"surface": "up", "start": 5, "end": 7, "cls": "prep"},
    ],
    {"gave up", "give up"},
)
check(
    "phrasal: linked lemma is the first candidate",
    hits,
    [{"verb": "gave", "particles": ["up"], "start": 0, "end": 7, "phrase": "give up"}],
)
# Longest window wins across candidates: a 3-window on the first candidate
# beats a 2-window on a later one.
hits = phrasal.find_phrasal_verbs(
    [
        {"surface": "came", "start": 0, "end": 4, "cls": "noun", "verb_lemmas": ["come"]},
        {"surface": "up", "start": 5, "end": 7, "cls": "prep"},
        {"surface": "with", "start": 8, "end": 12, "cls": "prep"},
    ],
    {"come up", "come up with"},
)
check(
    "phrasal: longest window wins across candidates",
    hits,
    [{"verb": "came", "particles": ["up", "with"], "start": 0, "end": 12, "phrase": "come up with"}],
)

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
