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

    ],

];
