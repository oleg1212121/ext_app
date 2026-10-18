<?php

// Extraction profiles for `goldendict:import --profile=<slug>` (ticket 05).
//
// A profile is a declarative override map layered OVER the parser's generic
// heuristics: XPath selectors name where a dictionary's articles keep their
// transcriptions, translation cells and definition blocks. Each selector
// kind is independent — when a selector matches nothing in an article, that
// extraction falls back to the generic heuristic for that article, and the
// entry is never dropped. Selector values are XPath 1.0 expression strings
// (or lists of them, queried in order).
//
// Semantics per key:
//   transcriptions — the text of each matched node becomes a transcription
//     (enclosing [brackets]//slashes/ trimmed; NOT gated on the IPA-marker
//     heuristic: the profile author vouches for these nodes, so non-IPA
//     transcription systems work).
//   translations   — the matched nodes' texts replace the heuristic block
//     segments as the source of translation candidates (the noise filters
//     still apply: commas/semicolons split, grammar tags and junk dropped).
//   definitions    — the matched nodes' texts are the definition segments
//     (instead of the heuristic nearest-block grouping).
//   forms          — the matched nodes' texts are inflected forms: each
//     node text splits on commas/semicolons/slashes into individual forms
//     ("am, is, are / was") that MERGE with the entry's alts (never replace
//     them) in the forms table.
//   pos            — the matched nodes' texts are word-class candidates: the
//     first text the POS dictionary can map ("transitive verb" → verb)
//     overrides the heuristic pos hints for the article; an unmappable text
//     is a miss and the heuristics decide.
//   strip          — the matched nodes are REMOVED from the article DOM
//     before any extraction (subtraction, not extraction: they vanish from
//     heuristic segments, IPA, pos hints, textContent and every other
//     selector's matches), and never count as a profile match or miss.
//
// Unknown slugs fail the command with the known list; articles where NO
// selector matched are counted ("Profile no-match articles") and sampled
// (capped) into the log so profile authors can see what they are missing.
// See wiki/playbooks/import-dictionary-data.md → "Extraction profiles".

return [

    'profiles' => [

        // Example shape (no real freemdict dictionary was available to author
        // a real profile against yet): transcription text in <span class="ts">,
        // translations in <span class="tr">, definition blocks in
        // <div class="sense">. Copy me, rename the slug, retarget the XPaths.
        // The concat(" ", normalize-space(@class), " ") idiom matches a class
        // as a whole token ("tr" matches class="bold tr" but not "tr2").
        'example-classes' => [
            'description' => 'Example: ts/tr/sense classed spans and divs — copy, rename and retarget for a real dictionary.',
            'transcriptions' => '//span[contains(concat(" ", normalize-space(@class), " "), " ts ")]',
            'translations' => '//span[contains(concat(" ", normalize-space(@class), " "), " tr ")]',
            'definitions' => '//div[contains(concat(" ", normalize-space(@class), " "), " sense ")]',
        ],

        // Oxford Russian Dictionary (En-Ru, MDX). Articles keep everything in
        // well-named classes: phoneticText divs/spans carry BrE/AmE IPA (the
        // sound-play glyphs live in <a> children, so selecting only the direct
        // text nodes yields clean transcription strings), partOfSpeech divs
        // carry the word class ("noun", "transitive verb", …),
        // targetLangTranslation spans carry the Russian equivalents,
        // sense-1/sense-2 divs are the numbered sense units, and
        // exampleGroup blocks hold the phrase-book examples — their English
        // source text fused with the Russian translation is sentence junk,
        // so they are stripped from the article before any extraction (the
        // translations selector's exampleGroup exclusion stays as defense in
        // depth).
        'oxford-en-ru' => [
            'description' => 'Oxford Russian Dictionary (En-Ru, MDX): phoneticText IPA, partOfSpeech word classes, targetLangTranslation equivalents, exampleGroup examples stripped, numbered sense blocks.',
            'transcriptions' => [
                '//div[contains(concat(" ", normalize-space(@class), " "), " phoneticText ")]/text()[normalize-space(.) != ","]',
                '//span[contains(concat(" ", normalize-space(@class), " "), " phoneticText ")]/text()[normalize-space(.) != ","]',
            ],
            'translations' => '//span[contains(concat(" ", normalize-space(@class), " "), " targetLangTranslation ")]'
                .'[not(ancestor::div[contains(concat(" ", normalize-space(@class), " "), " exampleGroup ")])]',
            'definitions' => [
                '//div[contains(concat(" ", normalize-space(@class), " "), " sense-1 ")]',
                '//div[contains(concat(" ", normalize-space(@class), " "), " sense-2 ")]',
            ],
            'pos' => '//div[contains(concat(" ", normalize-space(@class), " "), " partOfSpeech ")]',
            'strip' => '//div[contains(concat(" ", normalize-space(@class), " "), " exampleGroup ")]',
        ],

        // Mostitsky eXtro (Ru-En, DSL). pyglossary renders the DSL [trn]
        // equivalents as plain padding-left:1em paragraphs with no class of
        // their own — but they are the only such paragraphs whose text carries
        // no Cyrillic, so a translate() strip-all-Cyrillic pass that leaves
        // the text unchanged selects them (and only them; [com] commentary is
        // Russian). Configuring translations vouches for these nodes: articles
        // without one (cross-references, Ru-Ru-only definitions) get no
        // candidates instead of mined commentary. The [p] tags here are subject
        // domains (уфол., астрон.), not word classes — no pos selector. The
        // bold headword echo stays in the definitions by design: <b> carries
        // real content elsewhere in this dictionary, so a blanket strip is
        // unsafe, and span.sec ► source links, «см. тж» blocks and GoldenDict
        // artifacts take the noise with them.
        'mostitsky-ru-en' => [
            'description' => 'Mostitsky eXtro (Ru-En, DSL): Latin-only 1em paragraphs as [trn] equivalents, span.sec source links and see-also blocks stripped.',
            'translations' => '//p[@style="padding-left:1em;margin:0"]'
                .'[normalize-space(translate(normalize-space(.), "абвгдеёжзийклмнопрстуфхцчшщъыьэюяАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ", "")) = normalize-space(.)]',
            'strip' => '//span[contains(concat(" ", normalize-space(@class), " "), " sec ")]',
        ],

        // Test profile mirroring the oxford-en-ru shape on renamed classes
        // (partOfSpeech → word class, exampleBlock → stripped, tr spans →
        // translations, sense divs → definitions) for the regression tests.
        'test-pos-strip' => [
            'description' => 'Test: partOfSpeech word classes, exampleBlock stripped, tr/sense extraction — the oxford-en-ru shape on renamed classes.',
            'translations' => '//span[contains(concat(" ", normalize-space(@class), " "), " tr ")]',
            'definitions' => '//div[contains(concat(" ", normalize-space(@class), " "), " sense ")]',
            'pos' => '//div[contains(concat(" ", normalize-space(@class), " "), " partOfSpeech ")]',
            'strip' => '//div[contains(concat(" ", normalize-space(@class), " "), " exampleBlock ")]',
        ],

        // Test profile for the forms selector kind: fms spans carry
        // comma-separated form lists that merge with the entry's alts.
        'test-forms' => [
            'description' => 'Test: fms spans as comma-separated inflected forms merging with the alts, sense divs as definitions.',
            'forms' => '//span[contains(concat(" ", normalize-space(@class), " "), " fms ")]',
            'definitions' => '//div[contains(concat(" ", normalize-space(@class), " "), " sense ")]',
        ],

    ],

];
