"""Sentence enrichment: stress marks (ru/en), phrasal verbs (en), intonation.

All processing is local: Silero Stress (bundled dictionary + context homograph
solver) for Russian, the caller-supplied Wiktionary IPA for English, and
deterministic heuristics for phrasal verbs and intonation. No external calls.

Laravel owns the dictionary (it passes token spans, word classes, IPA variants
and stressed-form candidates in the request); this service owns model inference
only, and writes nothing anywhere.
"""
