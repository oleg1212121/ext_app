"""Multi-word verb detection by spaCy dependency parsing (ADR 0059).

spaCy parses the raw sentence text; the matcher walks every VERB token
(AUX excluded, so "could have given" can never match) and combines the
verb lemma with its adverbial-particle (``prt``) and preposition
(``prep``) children:

- particles alone are evidence: "gave up", "looked it up" hit even when
  the dictionary has no such headword (the parser guarantees the verb +
  particle structure the old n-gram windows could only guess at) — unless
  a goal/path preposition right after the particle marks the directional
  reading ("swung over toward Max");
- prepositional verbs ("depend on") and phrasal-prepositional verbs
  ("came up with") are dictionary-gated: ``lemma + particles + prep``
  must be in the curated lexicon the caller passes — the parser alone
  cannot tell the verb sense of "sat in the car" from "sit in".

One hit per verb token; the most specific candidate wins (lexicon combo
over parser-only particles). Hits carry char spans into the original
sentence text — spaCy offsets are char offsets into the same text
Laravel stored, so they are used directly — plus the ``phrase`` the
match came through (the lexicon headword, or the normalized lemma
phrase for parser-only particle hits). The caller's ``verb_lemmas``
hints (the dictionary's verb headwords per surface, ADR 0058) ride
along as extra lemma candidates for the lexicon checks.

The model loads lazily and a missing spaCy/model raises RuntimeError so
the endpoint fails loudly instead of writing empty enrichment (ADR 0059).
"""

MODEL_NAME = "en_core_web_md"

# Reported on every /enrich response and stamped by Laravel; bump on any
# matcher-behavior change and mirror it in
# EnglishPhrasalVerbEnricher::pythonVersion() (ADR 0067). v3 = the
# directional-adverb guard (ADR 0059).
ALGORITHM_VERSION = 3

# A particle immediately followed by one of these goal/path prepositions
# marks the directional reading of the adverb ("swung over toward Max",
# "followed Max down to the basement") — not a particle of an idiomatic
# multi-word verb. Locative prepositions are deliberately absent ("looked
# it up on the network" is a genuine hit), and infinitival "to" is tagged
# PART, not ADP, so "looked it up to check" stays a hit too. Lexicon
# combos ("come up to") are tried before this guard and stay unaffected.
DIRECTIONAL_PREPS = frozenset(
    {"to", "toward", "towards", "into", "onto", "through", "across", "past"}
)

_NLP = None


def _nlp():
    """Lazy singleton: uvicorn --reload must restart without re-loading."""
    global _NLP

    if _NLP is None:
        try:
            import spacy
        except ImportError as exc:
            raise RuntimeError(
                "spaCy is not installed — en_phrasal enrichment is "
                "unavailable (rebuild the python image: requirements.txt "
                "installs spacy and the model wheel)"
            ) from exc
        try:
            _NLP = spacy.load(MODEL_NAME, exclude=["ner"])
        except OSError as exc:
            raise RuntimeError(
                f"spaCy model {MODEL_NAME} is not installed — en_phrasal "
                "enrichment is unavailable (rebuild the python image)"
            ) from exc

    return _NLP


def _lemma_candidates(spacy_lemma: str, hints: dict | None) -> list[str]:
    """Lowercased lemma candidates for a verb token: the spaCy lemma
    first, then the caller's verb_lemmas (dictionary headwords that exist
    even where the parser's lemma misses a dictionary entry)."""
    candidates = [spacy_lemma.lower()]
    for lemma in (hints or {}).get("verb_lemmas") or []:
        key = lemma.lower()
        if key not in candidates:
            candidates.append(key)
    return candidates


def _build_hit(verb, matched: list, phrase: str) -> dict:
    last = max(matched, key=lambda t: t.i)
    return {
        "verb": verb.text,
        "particles": [t.text for t in sorted(matched, key=lambda t: t.i)],
        "start": verb.idx,
        "end": last.idx + len(last),
        "phrase": phrase,
    }


def _following_token(doc, token):
    """The token right after ``token`` in document order, or None."""
    return doc[token.i + 1] if token.i + 1 < len(doc) else None


def _verb_hit(verb, hint: dict | None, lexicon: set[str]) -> dict | None:
    """The hit for one VERB token, or None.

    Candidate order: the lexicon-gated phrasal-prepositional combo
    ("come up with"), then the parser-evidence particle hit ("gave up"),
    then the lexicon-gated prepositional verb ("depend on"). A verb with
    particles skips bare-prep candidates — the prep of "looked it up on
    the network" belongs to a following phrase, not the verb.
    """
    prt = sorted((c for c in verb.children if c.dep_ == "prt"), key=lambda t: t.i)
    prep = sorted((c for c in verb.children if c.dep_ == "prep"), key=lambda t: t.i)

    if not prt and not prep:
        return None

    lemmas = _lemma_candidates(verb.lemma_, hint)

    for prep_token in prep:
        if not prt:
            break
        tail = [t.text.lower() for t in prt] + [prep_token.text.lower()]
        for lemma in lemmas:
            phrase = " ".join([lemma] + tail)
            if phrase in lexicon:
                return _build_hit(verb, prt + [prep_token], phrase)

    if prt:
        # The directional reading — a goal/path preposition right after the
        # particle reads the particle as a path adverb, not as part of a
        # multi-word verb ("swung over toward Max"; ADR 0059).
        following = _following_token(verb.doc, prt[-1])
        if (
            following is not None
            and following.pos_ == "ADP"
            and following.text.lower() in DIRECTIONAL_PREPS
        ):
            return None

        # Parser evidence alone; the phrase normalizes the spaCy lemma.
        phrase = " ".join([lemmas[0]] + [t.text.lower() for t in prt])
        return _build_hit(verb, prt, phrase)

    for prep_token in prep:
        for lemma in lemmas:
            phrase = f"{lemma} {prep_token.text.lower()}"
            if phrase in lexicon:
                return _build_hit(verb, [prep_token], phrase)

    return None


def _hint_at(tokens: list[dict], char_offset: int) -> dict | None:
    """The caller's token hint whose span covers a spaCy verb token."""
    for token in tokens:
        if token.get("start", 0) <= char_offset < token.get("end", 0):
            return token

    return None


def find_multiword_verbs(sentences: list[dict], lexicon: set[str]) -> list[list[dict]]:
    """Return multi-word-verb hits per sentence, aligned to the input.

    ``sentences`` are dicts with ``text`` (the raw sentence, which spaCy
    parses and offsets into) and ``tokens`` (the caller's token dicts,
    consulted only for their ``verb_lemmas`` hints).
    """
    nlp = _nlp()
    texts = [s["text"] for s in sentences]
    results: list[list[dict]] = []

    for sentence, doc in zip(sentences, nlp.pipe(texts, batch_size=32)):
        tokens = sentence.get("tokens") or []
        hits: list[dict] = []

        for verb in doc:
            if verb.pos_ != "VERB":
                continue
            hit = _verb_hit(verb, _hint_at(tokens, verb.idx), lexicon)
            if hit is not None:
                hits.append(hit)

        results.append(hits)

    return results
