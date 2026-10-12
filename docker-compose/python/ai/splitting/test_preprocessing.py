"""Unit tests for language preprocessing/postprocessing rules.

Guards the rule tables behind ``preprocess``/``postprocess`` — in particular
that Belarusian shares the exact Russian noise-cleanup rule list (same script,
same stray-marker noise) while en/ru/de/fr behavior is unchanged.

Run (inside the container):

    docker exec ext_python python -m pytest /app/ai/splitting/test_preprocessing.py -q
"""

from ai.splitting.preprocessing import postprocess, preprocess


def test_belarusian_shares_russian_noise_cleanup():
    # Belarusian: slashes, angle brackets and bullets are stripped exactly as
    # for Russian, plus the default whitespace/comma/dash cleanup.
    assert preprocess("Прыве/т • свет<>.", "be") == "Прывет свет."
    assert preprocess("Слова  двойн,, тире——.", "be") == "Слова двойн, тире—."
    # Russian reference behavior (must not change).
    assert preprocess("Прыве/т • свет<>.", "ru") == "Прывет свет."


def test_english_keeps_default_rules_only():
    # en has no noise cleanup: the noise markers survive.
    assert preprocess("Hello/ world • test.", "en") == "Hello/ world • test."


def test_german_rules_unchanged():
    # German quotes normalise and dates are protected with the placeholder.
    assert preprocess("Er kam am 5. Januar an.", "de") == "Er kam am 5%@% Januar an."


def test_french_postprocessing_unchanged():
    # A sentence starting with the » closer is reattached to the previous one.
    assert postprocess(["First", "» second"], "fr") == ["First »", " second"]
    assert postprocess(["First »", "second"], "fr") == ["First »", "second"]
