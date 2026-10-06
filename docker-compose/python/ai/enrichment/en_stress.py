"""English stress marking from dictionary IPA (Wiktionary/kaikki + CMUdict).

The caller supplies IPA transcription variants per token (``transcriptions``
rows keep the primary-stress ˈ). Marking strategy (ADR 0053):

1. The ˈ gives the stressed-syllable index (vowel nuclei before it).
2. Primary placement path: pyphen hyphenates the word orthographically
   ("ad-vance", "be-cause"); when the syllable count matches the IPA nucleus
   count, the acute goes on the first vowel letter of the stressed syllable.
3. Fallback: proportional mapping onto the vowel letters, excluding a
   word-final silent "e" when other vowel letters remain — the old mapping
   saturated onto it (advancé/becausé for advánce/becáuse).
4. Hyphenated compounds with no usable whole-token variant are marked
   per part from the caller-supplied ``parts`` hint (SÉVEN-SĪDED).

Words with no ˈ in any variant (unstressed function words like "the /ðə/")
and words with no IPA at all stay unmarked. Monosyllables DO get marked when
a ˈ-carrying variant exists (CMUdict stresses them: cát, túrned) — matching
the Russian Silero behavior. No model involved — pure string work plus the
pyphen hyphenation dictionaries.
"""

try:
    import pyphen
except ImportError:  # pragma: no cover - pyphen is in requirements.txt
    pyphen = None

COMBINING_ACUTE = "\u0301"

# Reported on every /enrich response and stamped by Laravel; a bump here
# must be mirrored in EnglishStressEnricher::pythonVersion() (ADR 0067).
ALGORITHM_VERSION = 1

# IPA vowel symbols (base letters; combining diacritics after them do not
# start a new nucleus because they are not in this set).
IPA_VOWELS = set("aeiouyæɑɒøœəɪɛʌʊɔɜɚɝɐɞɤʉ")

EN_VOWELS = set("aeiouyAEIOUY")

_PYPHEN_DICS: dict[str, "object | None"] = {}


def _clean_ipa(ipa: str) -> str:
    return ipa.strip().strip("/").strip()


def _nuclei(phon: str) -> list[tuple[int, int]]:
    """Maximal runs of IPA vowels: (start, end_exclusive) per syllable nucleus."""
    runs: list[tuple[int, int]] = []
    i = 0
    while i < len(phon):
        if phon[i] in IPA_VOWELS:
            j = i
            while j < len(phon) and phon[j] in IPA_VOWELS:
                j += 1
            runs.append((i, j))
            i = j
        else:
            i += 1
    return runs


def _pyphen_dic(lang: str):
    if pyphen is None:
        return None
    if lang not in _PYPHEN_DICS:
        try:
            _PYPHEN_DICS[lang] = pyphen.Pyphen(lang=lang)
        except KeyError:
            _PYPHEN_DICS[lang] = None
    return _PYPHEN_DICS[lang]


def _syllable_spans(word: str) -> list[tuple[int, int]]:
    """Orthographic syllable (start, end) spans via pyphen, [] when unsplittable."""
    if pyphen is None:
        return []
    lower = word.lower()
    if len(lower) != len(word):
        return []
    for lang in ("en_US", "en_GB"):
        dic = _pyphen_dic(lang)
        if dic is None:
            continue
        hyphenated = dic.inserted(lower)
        if "-" not in hyphenated:
            continue
        spans: list[tuple[int, int]] = []
        start = 0
        for part in hyphenated.split("-"):
            spans.append((start, start + len(part)))
            start += len(part)
        if spans and spans[-1][1] == len(word):
            return spans
    return []


def _vowel_positions(word: str, exclude_final_silent_e: bool = False) -> list[int]:
    """Positions of vowel letters, treating a word-initial y as consonant.

    A word-final "e" stays excluded while at least two other vowel letters
    remain (advánce, becáuse, táble) — it is pronounced when it is one of
    at most two vowels (café /kæˈfeɪ/, the /ðiː/ emphatic).
    """
    positions = [
        i
        for i, ch in enumerate(word)
        if ch in EN_VOWELS and not (i == 0 and ch in "yY")
    ]
    if exclude_final_silent_e and len(word) > 2 and word[-1] in "eE":
        others = [i for i in positions if i != len(word) - 1]
        if len(others) >= 2:
            positions = others
    return positions


def _first_vowel(word: str, start: int, end: int) -> int | None:
    for i in range(start, end):
        if word[i] in EN_VOWELS and not (i == 0 and word[i] in "yY"):
            return i
    return None


def _last_run_start(word: str) -> int | None:
    """Start of the last contiguous vowel-letter run (final-e excluded)."""
    positions = _vowel_positions(word, exclude_final_silent_e=True)
    if not positions:
        return None
    start = prev = positions[0]
    for i in positions[1:]:
        if i != prev + 1:
            start = i
        prev = i
    return start


def _letter_position(word: str, stress_index: int, total: int) -> int | None:
    """Orthographic position of the stressed vowel letter, or None."""
    # Primary: pyphen syllables aligned by count with the IPA nuclei.
    spans = _syllable_spans(word)
    if len(spans) == total:
        start, end = spans[min(stress_index, len(spans) - 1)]
        pos = _first_vowel(word, start, end)
        if pos is not None:
            return pos
    # Fallback: proportional mapping over the vowel letters.
    letters = _vowel_positions(word, exclude_final_silent_e=True)
    if not letters:
        return None
    if stress_index == 0 or total <= 1:
        gpos = letters[0]
    elif stress_index == total - 1:
        # Final nucleus: anchor on the last vowel-letter run — digraphs
        # (afráid, aróund, becáuse) and silent-final-e words (advánce).
        anchor = _last_run_start(word)
        if anchor is None:
            return None
        gpos = anchor
    else:
        gpos = letters[min(
            int(stress_index * (len(letters) - 1) / (total - 1)),
            len(letters) - 1,
        )]
    # Digraph fix: a stressed final "ay/oy/ey" marks the first vowel letter.
    if word[gpos] in "yY" and gpos > 0 and word[gpos - 1] in EN_VOWELS:
        gpos -= 1
    return gpos


def _mark_with_variants(word: str, ipa_variants: list[str] | None) -> str | None:
    """Marked word, or None when no variant carries a usable primary stress."""
    if COMBINING_ACUTE in word:
        return word  # already marked (idempotent)
    for ipa in ipa_variants or []:
        phon = _clean_ipa(ipa)
        if "ˈ" not in phon:
            continue
        head, _, _ = phon.partition("ˈ")
        stress_index = len(_nuclei(head))
        total = len(_nuclei(phon))
        if total == 0:
            continue
        pos = _letter_position(word, stress_index, total)
        if pos is None:
            continue
        return word[: pos + 1] + COMBINING_ACUTE + word[pos + 1 :]
    return None


def mark_word(word: str, ipa_variants: list[str] | None, parts: list[dict] | None = None) -> str:
    """Return ``word`` with U+0301 on the stressed syllable's first vowel."""
    marked = _mark_with_variants(word, ipa_variants)
    if marked is not None:
        return marked
    # Hyphenated compound: mark each part from its own variants ("seven-sided"
    # has no whole-word entry; "seven" and "sided" do).
    if parts and "-" in word:
        segments = word.split("-")
        if len(segments) == len(parts):
            marked_parts = [
                _mark_with_variants(segment, part.get("ipa")) or segment
                for segment, part in zip(segments, parts)
            ]
            candidate = "-".join(marked_parts)
            if COMBINING_ACUTE in candidate:
                return candidate
    return word


def mark_sentence(text: str, tokens: list[dict]) -> str:
    """Return ``text`` with per-token stress marks, spans preserved.

    ``tokens`` are dicts with ``surface``/``start``/``end``/``ipa`` (and
    optional ``parts``). Non-token characters pass through verbatim,
    mirroring ru_stress.mark_sentence.
    """
    marked = {
        t["start"]: mark_word(t["surface"], t.get("ipa"), t.get("parts"))
        for t in tokens
    }
    out: list[str] = []
    cursor = 0
    for token in tokens:
        start, end = token["start"], token["end"]
        out.append(text[cursor:start])
        out.append(marked.get(start, text[start:end]))
        cursor = end
    out.append(text[cursor:])
    return "".join(out)
