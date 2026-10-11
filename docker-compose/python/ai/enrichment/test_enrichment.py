"""Tests for the enrichment package.

Deterministic parts (en_stress, dictionary fallbacks, versions) always run;
the Silero-dependent ru/be stress-marking tests skip when the package or
model data is unavailable, and the spaCy phrasal-verb tests skip without
en_core_web_md.

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/enrichment/test_enrichment.py -q
"""

import regex as _regex
import pytest

from ai.api.schemas import EnrichResponse
from ai.enrichment import be_stress, en_stress, phrasal
from ai.enrichment.ru_stress import COMBINING_ACUTE as ACUTE

_WORD_RE = _regex.compile(r"[\p{L}\p{M}]+(?:['’\-][\p{L}\p{M}]+)*")


def _spacy_ready() -> bool:
    try:
        phrasal._nlp()
        return True
    except RuntimeError:
        return False


HAS_SPACY = _spacy_ready()


# --- en_stress.mark_word ------------------------------------------------------


@pytest.mark.parametrize(
    ("word", "ipa", "parts", "expected"),
    [
        ("beautiful", ["/ˈbjuː.tɪ.fl/"], None, "be" + ACUTE + "autiful"),
        ("pronounces", ["/prəˈnʌn.sɪz/"], None, "pron" + "o" + ACUTE + "unces"),
        ("dictionary", ["/ˈdɪk.ʃə.nə.ɹi/"], None, "d" + "i" + ACUTE + "ctionary"),
        ("information", ["/ˌɪn.fəˈmeɪ.ʃən/"], None, "inform" + "a" + ACUTE + "tion"),
        ("pronunciation", ["/prəˌnʌn.siˈeɪ.ʃən/"], None, "pronunci" + "a" + ACUTE + "tion"),
        ("the", ["/ðə/"], None, "the"),
        ("smizards", None, None, "smizards"),
        ("contract", ["/kɒn.trækt/", "/kənˈtrækt/"], None, "contr" + "a" + ACUTE + "ct"),
        ("be" + ACUTE + "autiful", ["/ˈbjuː.tɪ.fl/"], None, "be" + ACUTE + "autiful"),
        # --- ADR 0053 (pyphen alignment, silent-e, monosyllables, parts) ---
        # advánce/becáuse: the old proportional map saturated onto the silent e.
        ("advance", ["/ədˈvɑːns/"], None, "adv" + "a" + ACUTE + "nce"),
        ("because", ["/bɪˈkɒz/"], None, "bec" + "a" + ACUTE + "use"),
        ("believe", ["/bɪˈliːv/"], None, "bel" + "i" + ACUTE + "eve"),
        ("referee", ["/ˌɹɛf.əˈɹiː/"], None, "refer" + "e" + ACUTE + "e"),
        # CMUdict-style variants: monosyllables carry ˈ, inflected forms exist.
        ("turned", ["/tˈɜːnd/"], None, "t" + "u" + ACUTE + "rned"),
        ("cat", ["/kˈæt/"], None, "c" + "a" + ACUTE + "t"),
        ("smiled", ["/smˈaɪld/"], None, "sm" + "i" + ACUTE + "led"),
        ("GAMBLERS", ["/ɡˈæmblɚz/"], None, "G" + "A" + ACUTE + "MBLERS"),
        (
            "seven-sided",
            None,
            [
                {"surface": "seven", "ipa": ["/ˈsɛvən/"]},
                {"surface": "sided", "ipa": ["/sˈaɪdɪd/"]},
            ],
            "s" + "e" + ACUTE + "ven-s" + "i" + ACUTE + "ded",
        ),
        (
            "seven-sided",
            None,
            [
                {"surface": "seven", "ipa": ["/ˈsɛvən/"]},
                {"surface": "sided", "ipa": None},
            ],
            "s" + "e" + ACUTE + "ven-sided",
        ),
        (
            "well-known",
            ["/ˌwɛlˈnəʊn/"],
            [
                {"surface": "well", "ipa": ["/wɛl/"]},
                {"surface": "known", "ipa": ["/nəʊn/"]},
            ],
            "well-kn" + "o" + ACUTE + "wn",
        ),
        ("away", ["/əˈweɪ/"], None, "aw" + "a" + ACUTE + "y"),
    ],
)
def test_en_mark_word(word, ipa, parts, expected):
    assert en_stress.mark_word(word, ipa, parts) == expected


def test_en_mark_sentence_rebuild_preserves_punctuation_and_spans():
    text = "She pronounces it beautifully."
    IPA = {
        "she": ["/ʃi/"],
        "pronounces": ["/prəˈnʌn.sɪz/"],
        "it": ["/ɪt/"],
        "beautifully": ["/ˈbjuː.tɪ.fli/"],
    }
    tokens = [
        {
            "surface": m.group(0),
            "start": m.start(),
            "end": m.end(),
            "ipa": IPA[m.group(0).lower()],
        }
        for m in _WORD_RE.finditer(text)
    ]
    assert en_stress.mark_sentence(text, tokens) == (
        "She pron" + "o" + ACUTE + "unces it b" + "e" + ACUTE + "autifully."
    )


# --- phrasal: spaCy multi-word verbs (ADR 0059; skipped without the model) ----


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


@pytest.mark.spacy
@pytest.mark.skipif(not HAS_SPACY, reason="spaCy / en_core_web_md unavailable")
class TestPhrasal:
    def test_particle_verb_hits_without_a_lexicon_entry(self):
        assert _find("She gave up smoking.") == [
            {"verb": "gave", "particles": ["up"], "start": 4, "end": 11, "phrase": "give up"}
        ]

    def test_separated_particle_spans_the_whole_chunk(self):
        assert _find("He looked it up on the network.") == [
            {"verb": "looked", "particles": ["up"], "start": 3, "end": 15, "phrase": "look up"}
        ]

    def test_prepositional_verb_with_lexicon_entry(self):
        assert _find("She depends on her network.", ["depend on"]) == [
            {"verb": "depends", "particles": ["on"], "start": 4, "end": 14, "phrase": "depend on"}
        ]

    def test_prepositional_verb_without_lexicon_entry_stays_a_miss(self):
        assert _find("She sat in the car.", ["sit under"]) == []

    def test_phrasal_prepositional_combo_spans_through_the_preposition(self):
        assert _find("She came up with a brilliant plan.", ["come up with"]) == [
            {
                "verb": "came",
                "particles": ["up", "with"],
                "start": 4,
                "end": 16,
                "phrase": "come up with",
            }
        ]

    # The reported false positives (AUX excluded; no prt/prep children).
    def test_modal_aux_verb_is_no_hit(self):
        assert _find("She could have given more.", ["give up", "give"]) == []

    def test_negated_plain_verb_is_no_hit(self):
        assert _find("He did not say a word.", ["say"]) == []

    def test_punctuation_adjacent_clause_is_no_hit(self):
        assert _find("It was done, it was over.", ["do it"]) == []

    def test_passive_participle_without_particle_is_no_hit(self):
        assert _find("The work was done by noon.", ["do"]) == []

    # Directional adverbs: a goal/path preposition right after the particle
    # reads as direction, not a multi-word verb (ADR 0059 v3).
    def test_directional_over_toward_is_no_hit(self):
        assert _find("The ringmaster swung over toward Max.") == []

    def test_directional_down_to_is_no_hit(self):
        assert _find("She followed Max down to the basement.") == []

    def test_locative_preposition_after_particle_still_hits(self):
        assert _find("He looked it up on the network.") == [
            {"verb": "looked", "particles": ["up"], "start": 3, "end": 15, "phrase": "look up"}
        ]

    def test_infinitival_to_after_particle_still_hits(self):
        assert _find("She looked it up to check the facts.") == [
            {"verb": "looked", "particles": ["up"], "start": 4, "end": 16, "phrase": "look up"}
        ]

    def test_two_verbs_two_hits(self):
        assert _find("She gave up and looked it up.") == [
            {"verb": "gave", "particles": ["up"], "start": 4, "end": 11, "phrase": "give up"},
            {"verb": "looked", "particles": ["up"], "start": 16, "end": 28, "phrase": "look up"},
        ]

    def test_verb_lemmas_hints_still_flow_through(self):
        # verb_lemmas (ADR 0058) ride along as extra lemma candidates.
        assert _find("She gave up smoking.", ["give up"], {"gave": ["give"]}) == [
            {"verb": "gave", "particles": ["up"], "start": 4, "end": 11, "phrase": "give up"}
        ]

    def test_plain_sentence_with_empty_lexicon(self):
        assert _find("The dog sleeps.") == []


# --- ru_stress (Silero; skipped when unavailable) ------------------------------


def _ru_tokens(ru_stress_module, text):
    """Tokenize like WordTokenizer and build span dicts."""
    out = []
    for m in ru_stress_module.WORD_RE.finditer(text.replace("+", "")):
        out.append({"surface": m.group(0), "start": m.start(), "end": m.end(), "stressed": None})
    return out


@pytest.fixture(scope="module")
def ru_accentor():
    pytest.importorskip("silero_stress")
    from silero_stress import load_accentor

    return load_accentor()


@pytest.mark.silero
class TestRuStressModel:
    def test_proiznosit_gets_acute(self, ru_accentor):
        from ai.enrichment import ru_stress

        sentence = "Она произносит это красиво, но часто теряет мелодию."
        marked = ru_stress.mark_sentence(sentence, _ru_tokens(ru_stress, sentence), ru_accentor)
        assert "произно" + ACUTE + "сит" in marked

    def test_krasivo_gets_acute(self, ru_accentor):
        from ai.enrichment import ru_stress

        sentence = "Она произносит это красиво, но часто теряет мелодию."
        marked = ru_stress.mark_sentence(sentence, _ru_tokens(ru_stress, sentence), ru_accentor)
        assert "краси" + ACUTE + "во" in marked

    def test_punctuation_preserved(self, ru_accentor):
        from ai.enrichment import ru_stress

        sentence = "Она произносит это красиво, но часто теряет мелодию."
        marked = ru_stress.mark_sentence(sentence, _ru_tokens(ru_stress, sentence), ru_accentor)
        assert marked.count(",") == sentence.count(",")

    def test_yo_conversion(self, ru_accentor):
        from ai.enrichment import ru_stress

        sentence = "Еще не все замки готовы."
        marked = ru_stress.mark_sentence(sentence, _ru_tokens(ru_stress, sentence), ru_accentor)
        assert "Ещё" in marked

    def test_no_double_marks_on_pre_marked_input(self, ru_accentor):
        from ai.enrichment import ru_stress

        sentence = "при́вычно звучит."
        marked = ru_stress.mark_sentence(sentence, _ru_tokens(ru_stress, sentence), ru_accentor)
        # привы́чно (1) + звучи́т (1): the pre-existing mark was replaced, not added to.
        assert marked.count(ACUTE) == 2 and "привы́чно" in marked


# --- dictionary fallbacks (Silero-independent) ---------------------------------


def test_ru_dict_fallback_applies_unique_stress_position():
    from ai.enrichment.ru_stress import _dict_fallback

    assert _dict_fallback("свободного", ["свобо́дного"]) == "свобо́дного"


def test_ru_dict_fallback_skips_ambiguous_candidates():
    from ai.enrichment.ru_stress import _dict_fallback

    assert _dict_fallback("замки", ["за́мки", "замки́"]) == "замки"


def test_ru_dict_fallback_yo_candidate_converts_e_to_yo():
    from ai.enrichment.ru_stress import _dict_fallback

    assert _dict_fallback("еще", ["ещё"]) == "ещё"


def test_be_dict_fallback_counts_i_as_a_vowel():
    # і counts as a vowel (ru's set has и, be's has і): the acute lands on і.
    from ai.enrichment.be_stress import _dict_fallback

    assert _dict_fallback("кніга", ["кні́га"]) == "кні́га"


def test_be_dict_fallback_skips_non_syllabic_u_short():
    # ў is non-syllabic and never a vowel: the ё-style candidate's stress index
    # counts а…ё without it, so the ё stays put instead of drifting one slot.
    from ai.enrichment.be_stress import _dict_fallback

    assert _dict_fallback("паўднёвы", ["паўднёвы"]) == "паўднёвы"


def test_be_dict_fallback_skips_ambiguous_candidates():
    from ai.enrichment.be_stress import _dict_fallback

    assert _dict_fallback("замак", ["за́мак", "замаќ"]) == "замак"


# --- be_stress model (the package's Belarusian accentor; skipped w/o silero) ---


@pytest.fixture(scope="module")
def bel_accentor():
    pytest.importorskip("silero_stress")
    from silero_stress import load_accentor

    return load_accentor(lang="bel")


@pytest.mark.silero
class TestBeStressModel:
    def test_yana_gets_acute_on_final_a(self, bel_accentor):
        sentence = "Яна прыйшла ў горад раніцай."
        marked = be_stress.mark_sentence(sentence, _ru_tokens(be_stress, sentence), bel_accentor)
        assert "Яна" + ACUTE in marked

    def test_horad_gets_acute_on_o(self, bel_accentor):
        sentence = "Яна прыйшла ў горад раніцай."
        marked = be_stress.mark_sentence(sentence, _ru_tokens(be_stress, sentence), bel_accentor)
        assert "г" + "о" + ACUTE + "рад" in marked

    def test_non_syllabic_u_short_stays_unmarked(self, bel_accentor):
        sentence = "Яна прыйшла ў горад раніцай."
        marked = be_stress.mark_sentence(sentence, _ru_tokens(be_stress, sentence), bel_accentor)
        assert " ў " in marked


# --- algorithm versions (ADR 0067) ---------------------------------------------
# Each module reports its version on every /enrich response; Laravel stamps
# the reported value and re-stales when it disagrees with the enricher's
# declared pythonVersion().


def test_module_versions():
    from ai.enrichment import ru_stress

    assert ru_stress.ALGORITHM_VERSION == 1
    assert en_stress.ALGORITHM_VERSION == 1
    assert phrasal.ALGORITHM_VERSION == 3
    assert be_stress.ALGORITHM_VERSION == 1


def test_versions_ride_the_enrich_response_schema():
    response = EnrichResponse(results=[], versions={"en_phrasal": phrasal.ALGORITHM_VERSION})
    assert response.versions == {"en_phrasal": 3}
