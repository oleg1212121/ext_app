"""Enricher module registry: dispatch key -> module adapter.

The python mirror of Laravel's ``EnricherRegistry`` (ADR 0057): the /enrich
endpoint loops the request's enricher keys through this table instead of
per-key if-branches, and request validation derives its accepted keys from
:func:`keys`. Adding an analysis is one enrichment module plus one entry
here — no endpoint or schema edit.

Each entry is an :class:`EnricherModule` with a uniform two-phase shape:

- ``prepare(app_state, sentences, lexicon)`` runs ONCE per request: the
  model load and/or batched computation over the whole request (spaCy
  matching, ADR 0059). ``app_state`` is the FastAPI ``app.state`` the
  ``ModelCache`` hangs models on, ``sentences`` the request payload as plain
  ``{"text", "tokens"}`` dicts, ``lexicon`` the caller's headword set.
  A model/tooling failure raises :class:`EnricherUnavailable` — the endpoint
  maps it to HTTP 503 so a missing model fails loudly instead of writing
  empty enrichment that would get stamped as done (ADR 0052/0059).
- ``apply(text, tokens, prepared)`` produces one sentence's output.
  ``tokens`` are the sentence's dumped token dicts, ``prepared`` whatever
  ``prepare`` returned (ru_stress/be_stress: the Silero accentor — the
  package's per-language model; en_phrasal: an iterator over the batched
  per-sentence hit lists, paired in order; en_stress: nothing — pure string
  work, no model).
"""

from dataclasses import dataclass
from typing import Any, Callable

from ai.enrichment import be_stress, en_stress, phrasal, ru_stress
from ai.models_cache import ModelCache


class EnricherUnavailable(RuntimeError):
    """A prepare step could not load its model or tooling.

    The /enrich endpoint maps this to HTTP 503; any other exception keeps
    propagating (a bug is a 500, not a "model missing").
    """


@dataclass(frozen=True)
class EnricherModule:
    """One registered analysis (the counterpart of Laravel's ``Enricher``):

    ``key`` is the dispatch/stamp key both sides agree on, ``version`` the
    module's ``ALGORITHM_VERSION`` (Laravel's ADR 0067 parity check compares
    it with the enricher's declared ``pythonVersion()``), and ``prepare`` /
    ``apply`` the two-phase computation described in the module docstring.
    """

    key: str
    version: int
    prepare: Callable[[Any, list[dict], set[str]], Any]
    apply: Callable[[str, list[dict], Any], Any]


def _prepare_stress(lang: str):
    """The Silero accentor prepare step for one language (ru/be share it).

    Each Cyrillic stress adapter loads its language's accentor through the
    ModelCache; a load failure raises :class:`EnricherUnavailable` — the
    endpoint maps it to HTTP 503 so a missing model fails loudly instead of
    writing empty enrichment that would get stamped as done (ADR 0052/0059).
    """

    def prepare(app_state, sentences, lexicon):
        try:
            return ModelCache(app_state).stress_model(lang)
        except Exception as exc:
            raise EnricherUnavailable(f"Stress model not loaded: {exc}") from exc

    return prepare


_prepare_ru_stress = _prepare_stress("ru")
_prepare_be_stress = _prepare_stress("bel")


def _apply_stress(module):
    """The Silero accentor apply step for one language's module (ru/be share it).

    Pure per-sentence work — the language module's ``mark_sentence`` over the
    prepared accentor; no failure path of its own (the model-load failure the
    endpoint maps to HTTP 503 happens in ``prepare``).
    """

    def apply(text, tokens, accentor):
        return module.mark_sentence(text, tokens, accentor)

    return apply


_apply_ru_stress = _apply_stress(ru_stress)
_apply_be_stress = _apply_stress(be_stress)


def _prepare_en_stress(app_state, sentences, lexicon):
    return None  # No model involved — dictionary IPA plus pyphen (ADR 0053).


def _apply_en_stress(text, tokens, _prepared):
    return en_stress.mark_sentence(text, tokens)


def _prepare_en_phrasal(app_state, sentences, lexicon):
    try:
        return iter(phrasal.find_multiword_verbs(sentences, lexicon))
    except RuntimeError as exc:
        # phrasal._nlp() signals a missing spaCy package/model as RuntimeError.
        raise EnricherUnavailable(str(exc)) from exc


def _apply_en_phrasal(text, tokens, hits):
    return next(hits)


_MODULES: dict[str, EnricherModule] = {
    module.key: module
    for module in (
        EnricherModule(
            key="ru_stress",
            version=ru_stress.ALGORITHM_VERSION,
            prepare=_prepare_ru_stress,
            apply=_apply_ru_stress,
        ),
        EnricherModule(
            key="be_stress",
            version=be_stress.ALGORITHM_VERSION,
            prepare=_prepare_be_stress,
            apply=_apply_be_stress,
        ),
        EnricherModule(
            key="en_stress",
            version=en_stress.ALGORITHM_VERSION,
            prepare=_prepare_en_stress,
            apply=_apply_en_stress,
        ),
        EnricherModule(
            key="en_phrasal",
            version=phrasal.ALGORITHM_VERSION,
            prepare=_prepare_en_phrasal,
            apply=_apply_en_phrasal,
        ),
    )
}


def get(key: str) -> EnricherModule:
    """The adapter for ``key``.

    Unknown keys raise KeyError — request validation rejects them before
    dispatch (validation derives its accepted set from :func:`keys`), so a
    KeyError here is an invariant breach, not an input problem.
    """
    return _MODULES[key]


def keys() -> list[str]:
    """The registered dispatch keys — exactly the set request validation accepts."""
    return list(_MODULES)
