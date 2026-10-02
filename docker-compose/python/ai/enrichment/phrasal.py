"""Phrasal verb detection by dictionary n-gram matching.

The English dictionary already contains multi-word verb headwords imported
from Wiktionary ("give up", "cheer on", "kick the bucket" — currently inert
because the entity word-linker only ever sees single-word tokens). The caller
passes that lexicon plus per-token word classes and lemmas; a hit is a 3- or
2-token window whose lead token is verb-classified and whose
``lemma + following surfaces`` (lowercased) are in the lexicon. The lemma
covers inflected leads ("gave up" matches the "give up" headword); particles
never inflect, so surfaces suffice for them. Longest match wins, matches
never overlap, and hits carry char spans into the original sentence
``content`` plus the lexicon ``phrase`` the match came through.
"""


def find_phrasal_verbs(tokens: list[dict], lexicon: set[str]) -> list[dict]:
    """Return phrasal-verb hits for a token list.

    ``tokens`` are dicts with ``surface``/``start``/``end``/``cls`` and an
    optional ``lemma`` (dictionary headword) on the lead token.
    """
    hits: list[dict] = []
    i = 0
    while i < len(tokens):
        lead = tokens[i]
        if (lead.get("cls") or "") != "verb":
            i += 1
            continue
        lead_key = (lead.get("lemma") or lead["surface"]).lower()
        matched = None
        for n in (3, 2):
            if i + n <= len(tokens):
                phrase = " ".join([lead_key] + [t["surface"].lower() for t in tokens[i + 1 : i + n]])
                if phrase in lexicon:
                    matched = n
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
