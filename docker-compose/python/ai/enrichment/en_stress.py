"""English stress marking from Wiktionary IPA.

The caller supplies IPA transcription variants per token (``transcriptions``
rows keep the primary-stress ˈ). We locate the ˈ, count the vowel nuclei
before it to get the stressed syllable index, and map that index
proportionally onto the word's vowel letters — usually more numerous than the
nuclei (digraphs, silent letters) — then put a U+0301 combining acute after
the chosen letter: béautiful, pronóunces, informátion, pronunciátion.

Words with no ˈ in any variant (unstressed function words) and words with no
IPA at all stay unmarked. No model involved — pure string work.
"""

COMBINING_ACUTE = "\u0301"
STRESS_MARKS = "ˈˌ"

# IPA vowel symbols (base letters; combining diacritics after them do not
# start a new nucleus because they are not in this set).
IPA_VOWELS = set("aeiouyæɑɒøœəɪɛʌʊɔɜɚɝɐɞɤʉ")

EN_VOWELS = set("aeiouyAEIOUY")


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


def _vowel_positions(word: str) -> list[int]:
    """Positions of vowel letters, treating a word-initial y as consonant."""
    return [
        i
        for i, ch in enumerate(word)
        if ch in EN_VOWELS and not (i == 0 and ch in "yY")
    ]


def mark_word(word: str, ipa_variants: list[str] | None) -> str:
    """Return ``word`` with U+0301 on the stressed syllable's first vowel."""
    if COMBINING_ACUTE in word:
        return word  # already marked (idempotent)
    for ipa in ipa_variants or []:
        phon = _clean_ipa(ipa)
        if "ˈ" not in phon:
            continue
        head, _, _ = phon.partition("ˈ")
        stress_index = len(_nuclei(head))
        total = len(_nuclei(phon))
        letters = _vowel_positions(word)
        if not letters or total == 0:
            continue
        # Map the stressed IPA nucleus proportionally onto the (usually more
        # numerous) orthographic vowel letters: pronunciation has 5 nuclei and
        # 6 vowel letters, and eɪ (4th nucleus) lands on the "a" — pronunciátion.
        if stress_index == 0 or total <= 1:
            pos = 0
        else:
            pos = min(
                int(stress_index * (len(letters) - 1) / (total - 1)),
                len(letters) - 1,
            )
        gpos = letters[pos]
        # Digraph fix: a stressed final "ay/oy/ey" marks the first vowel letter.
        if word[gpos] in "yY" and gpos > 0 and word[gpos - 1] in EN_VOWELS:
            gpos -= 1
        return word[: gpos + 1] + COMBINING_ACUTE + word[gpos + 1 :]
    return word


def mark_sentence(text: str, tokens: list[dict]) -> str:
    """Return ``text`` with per-token stress marks, spans preserved.

    ``tokens`` are dicts with ``surface``/``start``/``end``/``ipa``. Non-token
    characters pass through verbatim, mirroring ru_stress.mark_sentence.
    """
    marked = {t["start"]: mark_word(t["surface"], t.get("ipa")) for t in tokens}
    out: list[str] = []
    cursor = 0
    for token in tokens:
        start, end = token["start"], token["end"]
        out.append(text[cursor:start])
        out.append(marked.get(start, text[start:end]))
        cursor = end
    out.append(text[cursor:])
    return "".join(out)
