"""Phrasal verb detection by dictionary n-gram matching.

The English dictionary already contains multi-word verb headwords imported
from Wiktionary ("give up", "cheer on", "kick the bucket" — currently inert
because the entity word-linker only ever sees single-word tokens). The caller
passes that lexicon plus per-token word classes and lemmas; a hit is a 3- or
2-token window whose lead token can be read as a verb and whose
``lead candidate + following surfaces`` (lowercased) are in the lexicon. The
lemma covers inflected leads ("gave up" matches the "give up" headword);
particles never inflect, so surfaces suffice for them. A lead is a candidate
when its class hint says verb — trying the linked lemma (or the surface)
— or when the caller supplies ``verb_lemmas``: verb-class headwords for the
surface that exist even where another class's page outranks the verb (the
stained-glass noun "came" hides the verb in "came forward"; ADR 0058). The
longest window wins, candidates are tried in order within a window length,
matches never overlap, and hits carry char spans into the original sentence
``content`` plus the lexicon ``phrase`` the match came through.
"""


def lead_candidates(lead: dict) -> list[str]:
    """Lowercased candidate headwords for a window's lead token.

    The class hint's lemma (or the surface) first, then any verb_lemmas the
    caller resolved for the surface.
    """
    candidates: list[str] = []
    if (lead.get("cls") or "") == "verb":
        candidates.append((lead.get("lemma") or lead["surface"]).lower())
    for lemma in lead.get("verb_lemmas") or []:
        key = lemma.lower()
        if key not in candidates:
            candidates.append(key)
    return candidates


def find_phrasal_verbs(tokens: list[dict], lexicon: set[str]) -> list[dict]:
    """Return phrasal-verb hits for a token list.

    ``tokens`` are dicts with ``surface``/``start``/``end``/``cls`` and an
    optional ``lemma`` and ``verb_lemmas`` on the lead token.
    """
    hits: list[dict] = []
    i = 0
    while i < len(tokens):
        lead = tokens[i]
        candidates = lead_candidates(lead)
        matched: int | None = None
        phrase: str | None = None
        # Longest window wins; candidate order breaks ties within a length.
        for n in (3, 2):
            if matched or i + n > len(tokens):
                continue
            for lead_key in candidates:
                candidate = " ".join(
                    [lead_key] + [t["surface"].lower() for t in tokens[i + 1 : i + n]]
                )
                if candidate in lexicon:
                    matched = n
                    phrase = candidate
                    break
        if matched:
            span = tokens[i : i + matched]
            hits.append(
                {
                    "verb": lead["surface"],
                    "particles": [t["surface"] for t in span[1:]],
                    "start": span[0]["start"],
                    "end": span[-1]["end"],
                    "phrase": phrase,
                }
            )
            i += matched
        else:
            i += 1
    return hits
