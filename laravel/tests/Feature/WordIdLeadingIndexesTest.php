<?php

use Illuminate\Support\Facades\Schema;

it('gives every word_id child table a word_id-leading index', function () {
    // Per-word joins, recounts and FK cascade deletes resolve child rows by
    // word_id; without a word_id-leading index each lookup/crawl seq-scans
    // (the root cause of the 17-minute wipe crawl on the 3M-row forms table).
    $expectedIndexes = [
        'forms' => 'idx_forms_word_id',
        'transcriptions' => 'idx_transcriptions_word_id',
        'examples' => 'idx_examples_word_id',
        'pronunciations' => 'idx_pronunciations_word_id',
        'user_word' => 'idx_user_word_word_id',
        'user_word_event' => 'idx_user_word_event_word_id',
    ];

    $missing = [];

    foreach ($expectedIndexes as $table => $index) {
        $indexes = collect(Schema::getIndexes($table));

        if (! $indexes->contains('name', $index)) {
            $missing[] = "{$table}: index {$index} absent";

            continue;
        }

        if ($indexes->firstWhere('name', $index)['columns'][0] !== 'word_id') {
            $missing[] = "{$table}: index {$index} does not lead with word_id";
        }
    }

    expect($missing)->toBe([]);
});
