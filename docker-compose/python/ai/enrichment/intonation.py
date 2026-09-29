"""Sentence intonation annotation (heuristic, no model).

There is no good local text→prosody model; this is a deterministic
approximation, clearly labelled as such in the docs:

- nuclear stress: the last content word (noun / verb / adjective / adverb /
  proper noun / numeral), skipping English auxiliary and modal verbs;
  None when the sentence has no content word;
- terminal contour: rise for yes/no questions (ends with "?" and does not
  start with a wh-word), fall for everything else.
"""

# Both the Kaikki-style long slugs ("adjective") and the seeded short ones
# ("adj") occur on word classes; accept either.
CONTENT_CLASSES = {
    "noun", "verb", "adjective", "adverb", "adj", "adv",
    "proper noun", "proper_noun", "numeral", "num",
}

AUX_EN = {
    "be", "am", "is", "are", "was", "were", "been", "being",
    "have", "has", "had",
    "do", "does", "did",
    "will", "would", "shall", "should",
    "can", "could", "may", "might", "must",
}

WH_EN = {"who", "what", "where", "when", "why", "how", "which", "whose", "whom"}
WH_RU = {
    "кто", "что", "где", "куда", "откуда", "когда", "почему", "зачем",
    "сколько", "какой", "какая", "какое", "какие", "чей", "чья", "чьё",
    "который", "которая", "которое",
}


def annotate(tokens: list[dict], text: str, language: str) -> dict:
    """Return ``{"nuclear": {"start","end"} | None, "terminal": "rise"|"fall"}``."""
    nuclear = None
    for token in reversed(tokens):
        cls = token.get("cls") or ""
        surface = token["surface"].lower()
        if cls not in CONTENT_CLASSES:
            continue
        if language == "en" and cls == "verb" and surface in AUX_EN:
            continue
        nuclear = {"start": token["start"], "end": token["end"]}
        break

    wh = WH_EN if language == "en" else WH_RU
    ends_question = text.rstrip().endswith("?")
    starts_wh = bool(tokens) and tokens[0]["surface"].lower() in wh
    terminal = "rise" if ends_question and not starts_wh else "fall"
    return {"nuclear": nuclear, "terminal": terminal}
