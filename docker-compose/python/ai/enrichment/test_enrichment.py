"""Tests for the enrichment package (plain python, no pytest).

Deterministic parts (en_stress, phrasal, intonation) always run; the Silero
dependent ru_stress tests are skipped with a notice when the package or model
data is unavailable.

Run from anywhere (adds the package root to sys.path):

    docker exec ext_python python /app/ai/enrichment/test_enrichment.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from ai.enrichment import en_stress, intonation, phrasal  # noqa: E402
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
    [{"verb": "gave", "particles": ["up"], "start": 4, "end": 11}],
)
hits = phrasal.find_phrasal_verbs(
    toks(("He", 0, 2, "pron", None), ("kicked", 3, 9, "verb", "kick"), ("the", 10, 13, "det", None), ("bucket", 14, 20, "noun", None)),
    {"kick the bucket"},
)
check(
    "phrasal: three-word idiom wins over shorter/none",
    hits,
    [{"verb": "kicked", "particles": ["the", "bucket"], "start": 3, "end": 20}],
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
check("phrasal: hits consume their tokens (no inner re-match)", hits, [{"verb": "give", "particles": ["up"], "start": 3, "end": 10}])

# --- intonation --------------------------------------------------------------
t = toks(("She", 0, 3, "pron", None), ("has", 4, 7, "verb", None), ("finished", 8, 16, "verb", None), ("it", 17, 19, "pron", None))
check(
    "intonation: declarative, aux skipped for nucleus",
    intonation.annotate(t, "She has finished it.", "en"),
    {"nuclear": {"start": 8, "end": 16}, "terminal": "fall"},
)
t = toks(("Do", 0, 2, "verb", None), ("you", 3, 6, "pron", None), ("know", 7, 11, "verb", None), ("her", 12, 15, "pron", None))
check(
    "intonation: yes/no question rises",
    intonation.annotate(t, "Do you know her?", "en"),
    {"nuclear": {"start": 7, "end": 11}, "terminal": "rise"},
)
t = toks(("Where", 0, 5, "adverb", None), ("is", 6, 8, "verb", None), ("he", 9, 11, "pron", None))
check(
    "intonation: wh-question falls",
    intonation.annotate(t, "Where is he?", "en"),
    {"nuclear": {"start": 0, "end": 5}, "terminal": "fall"},
)
check(
    "intonation: no content word -> nuclear None",
    intonation.annotate(toks(("Okay", 0, 4, "intj", None)), "Okay?", "en"),
    {"nuclear": None, "terminal": "rise"},
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
