"""Unit tests for language preprocessing/postprocessing rules (plain python, no pytest).

Guards the rule tables behind ``preprocess``/``postprocess`` — in particular
that Belarusian shares the exact Russian noise-cleanup rule list (same script,
same stray-marker noise) while en/ru/de/fr behavior is unchanged.

Run from anywhere (adds the package root to sys.path):

    docker-compose/python/ai/venv/bin/python ai/splitting/test_preprocessing.py
"""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from ai.splitting.preprocessing import postprocess, preprocess


def check_belarusian_shares_russian_noise_cleanup() -> None:
    # Belarusian: slashes, angle brackets and bullets are stripped exactly as
    # for Russian, plus the default whitespace/comma/dash cleanup.
    assert preprocess("Прыве/т • свет<>.", "be") == "Прывет свет."
    assert preprocess("Слова  двойн,, тире——.", "be") == "Слова двойн, тире—."
    # Russian reference behavior (must not change).
    assert preprocess("Прыве/т • свет<>.", "ru") == "Прывет свет."


def check_english_keeps_default_rules_only() -> None:
    # en has no noise cleanup: the noise markers survive.
    assert preprocess("Hello/ world • test.", "en") == "Hello/ world • test."


def check_german_rules_unchanged() -> None:
    # German quotes normalise and dates are protected with the placeholder.
    assert preprocess("Er kam am 5. Januar an.", "de") == "Er kam am 5%@% Januar an."


def check_french_postprocessing_unchanged() -> None:
    # A sentence starting with the » closer is reattached to the previous one.
    assert postprocess(["First", "» second"], "fr") == ["First »", " second"]
    assert postprocess(["First »", "second"], "fr") == ["First »", "second"]


def main() -> int:
    checks = [
        check_belarusian_shares_russian_noise_cleanup,
        check_english_keeps_default_rules_only,
        check_german_rules_unchanged,
        check_french_postprocessing_unchanged,
    ]
    failures = 0
    for check in checks:
        try:
            check()
            print(f"PASS {check.__name__}")
        except AssertionError as exc:
            failures += 1
            print(f"FAIL {check.__name__}: {exc}")
    print(f"{len(checks) - failures}/{len(checks)} checks passed")
    return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
