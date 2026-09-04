<?php

return [
    // A fresh git checkout stamps every file with mtime = checkout time, so
    // the wiki validator's "source changed after generated.at" signal fires
    // on every CI run for every source. CI sets WIKI_SKIP_MTIME_CHECKS=1 to
    // mute that signal; local runs keep it.
    'skip_mtime_staleness' => env('WIKI_SKIP_MTIME_CHECKS', false),
];
