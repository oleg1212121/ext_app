<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A scheduled rebalance only touches the first 500 entity/match lists per
// run; the next day's run continues from where it stopped (ids ascend).
Schedule::command('entity-orders:rebalance --limit=500')->daily();

Schedule::command('alignments:resume')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('crossword:refresh')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('words:accrue-entity-frequency')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('entities:refresh-text-hashes')
    ->everyFiveMinutes()
    ->withoutOverlapping();
