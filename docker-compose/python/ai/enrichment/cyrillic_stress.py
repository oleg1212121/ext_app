"""Shared Cyrillic stress-marking machinery (ru, be).

One algorithm, one vowel-set parameter: Silero Stress runs on the
mark-stripped sentence, emits a literal ``+`` immediately before the stressed
vowel, which we convert to a U+0301 combining acute placed after the vowel
(omitted on ё, which is stressed by definition); tokens Silero leaves
unmarked fall back to the caller-supplied dictionary candidates, applied only
when all of them agree on the stressed vowel.

The languages differ only in their vowel letters (ru has и, be has і; ў is
non-syllabic and never counts): :data:`RU_VOWELS` / :data:`BE_VOWELS`. The
per-language modules (:mod:`ai.enrichment.ru_stress`,
:mod:`ai.enrichment.be_stress`) close these over and own their
``ALGORITHM_VERSION``; this module holds everything they share verbatim —
tokenization mirroring Laravel's WordTokenizer, mark stripping, marker
conversion, dictionary fallback, span-preserving rebuild.

Rebuilding strategy: Silero runs on the mark-stripped sentence (pre-marked
input would otherwise get double marks), its output is tokenized with the
same word regex Laravel's WordTokenizer uses, and the final string is rebuilt
by replacing each caller-supplied token span — so char offsets into the original
``content`` stay valid and punctuation is untouched.
"""

import regex

# Mirrors laravel/app/Classes/WordTokenizer.php: letters + combining marks,
# with internal apostrophe/hyphen joins. \x01 is Silero's converted "+" marker
# so it never splits a token (real "+" in text is never inside a letter run).
WORD_RE = regex.compile(r"[\p{L}\p{M}\x01]+(?:['’\-][\p{L}\p{M}\x01]+)*")

COMBINING_ACUTE = "\u0301"

# Russian vowel letters (и, no і).
RU_VOWELS = frozenset("аеиоуыэюяёАЕИОУЫЭЮЯЁ")

# Belarusian vowel letters: і instead of и; non-syllabic ў (U+045E) is
# deliberately NOT a vowel. ё carries stress by definition, as in Russian.
BE_VOWELS = frozenset("аеёіоуыэюяАЕЁІОУЫЭЮЯ")


def strip_marks(text: str) -> str:
    """Remove combining marks (U+0300..) so Silero does not double-mark."""
    return regex.sub(r"\p{M}", "", text)


_YO_FOLD = str.maketrans({"ё": "е", "Ё": "Е"})


def lookup_key(word: str) -> str:
    """The dictionary lookup key of a surface form.

    Mirrors Laravel's ``App\\Support\\WordLookupKey::normalize`` — trim,
    lowercase, strip combining marks, strip zero-width format characters
    (``\\p{Cf}``: ZWSP, ZWJ, BOM, soft hyphen), trim again, then fold ё→е —
    so a ё token compares equal to its е-folded dictionary form. The key
    folds; the display text never does. PHP's WordTokenizer (via
    ``WordLookupKey``) and the browser wordTokenizer.mjs carry the same
    formula (TokenizerParityTest on the Laravel side pins the PHP↔JS pair;
    this module's pytest pins cover the python one).
    """
    key = word.strip().lower()
    key = regex.sub(r"\p{M}", "", key)
    key = regex.sub(r"\p{Cf}", "", key).strip()
    return key.translate(_YO_FOLD)


def _vowel_indices(word: str, vowels: frozenset[str]) -> list[int]:
    """Positions of the language's vowel letters in ``word``."""
    return [i for i, ch in enumerate(word) if ch in vowels]


def _convert_marker(silero_word: str, vowels: frozenset[str]) -> str | None:
    """Turn Silero's ``+`` marker (already \x01) into a combining acute.

    Returns None when the word carries no marker. On ё/Ё the marker is simply
    dropped — ё already encodes the stress.
    """
    if "\x01" not in silero_word:
        return None
    stripped = silero_word.replace("\x01", "")
    # The marker sat immediately before the stressed vowel; after removing the
    # markers, that vowel is the first one at or after the old marker index.
    idx = silero_word.index("\x01")
    target = next((v for v in _vowel_indices(stripped, vowels) if v >= idx), None)
    if target is None:
        return stripped
    if stripped[target] in "ёЁ":
        return stripped
    return stripped[: target + 1] + COMBINING_ACUTE + stripped[target + 1 :]


def _apply_acute_by_index(surface: str, vowel_index: int, vowels: frozenset[str]) -> str:
    """Put the acute after the n-th (0-based) vowel of ``surface``."""
    vowels_at = _vowel_indices(surface, vowels)
    if vowel_index >= len(vowels_at):
        return surface
    target = vowels_at[vowel_index]
    if surface[target] in "ёЁ":
        return surface
    return surface[: target + 1] + COMBINING_ACUTE + surface[target + 1 :]


def _dict_fallback(
    surface: str,
    candidates: list[str] | None,
    vowels: frozenset[str],
) -> str:
    """Mark ``surface`` using dictionary forms (acute-style or ё-style).

    All candidates must agree on the stressed vowel index; otherwise the word
    is ambiguous (a homograph the solver missed) and stays unmarked. A ё-style
    candidate (stress carried by ё itself, no acute) converts the matching
    vowel of ``surface`` to ё instead of adding an acute.
    """
    stress: dict[int, bool] = {}
    for cand in candidates or []:
        if COMBINING_ACUTE in cand:
            stressed_at = cand.index(COMBINING_ACUTE) - 1
            vowel_index = len(_vowel_indices(cand[: stressed_at + 1], vowels)) - 1
            stress[vowel_index] = stress.get(vowel_index, False)
        elif "ё" in cand or "Ё" in cand:
            yo_at = cand.index("ё") if "ё" in cand else cand.index("Ё")
            vowel_index = len(_vowel_indices(cand[: yo_at + 1], vowels)) - 1
            stress[vowel_index] = True
    if len(stress) != 1:
        return surface
    vowel_index, is_yo = next(iter(stress.items()))
    vowels_at = _vowel_indices(surface, vowels)
    if vowel_index >= len(vowels_at):
        return surface
    if is_yo:
        target = vowels_at[vowel_index]
        yo = "Ё" if surface[target].isupper() else "ё"
        return surface[:target] + yo + surface[target + 1 :]
    return _apply_acute_by_index(surface, vowel_index, vowels)


def _match_case(word: str, reference: str) -> str:
    if not word or not reference:
        return word
    if reference[:1].isupper() and word[:1].islower():
        return word[:1].upper() + word[1:]
    if reference[:1].islower() and word[:1].isupper():
        return word[:1].lower() + word[1:]
    return word


def mark_sentence(
    text: str,
    tokens: list[dict],
    accentor,
    vowels: frozenset[str],
) -> str:
    """Return ``text`` with Cyrillic stress marks applied.

    ``tokens`` are dicts with ``surface``/``start``/``end`` (spans into
    ``text``) and optional ``stressed`` (dictionary candidates). Non-token
    characters are preserved verbatim, so pre-marked source words keep their
    original marks unless this pass replaces them.
    """
    stripped = strip_marks(text)
    try:
        silero_out = accentor(stripped).replace("+", "\x01")
    except Exception:
        silero_out = stripped

    silero_words = WORD_RE.findall(silero_out)
    stripped_words = WORD_RE.findall(stripped)
    aligned = silero_words if len(silero_words) == len(stripped_words) else [None] * len(stripped_words)

    marked: dict[int, str] = {}
    for token, stripped_word, silero_word in zip(tokens, stripped_words, aligned):
        surface = token["surface"]
        if strip_marks(surface) != stripped_word:
            # Tokenization drift between caller and service — leave unmarked.
            continue
        converted = _convert_marker(silero_word, vowels) if silero_word is not None else None
        if converted is not None and lookup_key(converted) == lookup_key(surface):
            # Silero's letters win (keeps its е→ё conversions); match case.
            marked[token["start"]] = _match_case(converted, surface)
        elif len(_vowel_indices(surface, vowels)) > 1:
            marked[token["start"]] = _dict_fallback(surface, token.get("stressed"), vowels)
    return _replace_spans(text, tokens, marked)


def _replace_spans(text: str, tokens: list[dict], marked: dict[int, str]) -> str:
    """Rebuild ``text``, replacing token spans present in ``marked`` (by start)."""
    out: list[str] = []
    cursor = 0
    for token in tokens:
        start, end = token["start"], token["end"]
        out.append(text[cursor:start])
        out.append(marked.get(start, text[start:end]))
        cursor = end
    out.append(text[cursor:])
    return "".join(out)
