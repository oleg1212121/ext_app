<?php

it('runs every test process with the memory budget the TIA coverage merge needs', function () {
    // PHPUnit applies phpunit.xml <ini> entries via ini_set() when the config
    // loads — in the parent pest process AND every paratest worker — so that
    // pin outranks `php -d` flags and PHP_INI_SCAN_DIR ini files, and it is
    // the single effective source of the test-process memory limit. It must
    // cover two jobs: the full sequential suite's peak memory (Filament pages
    // exhaust the 128M CLI default), and Pest TIA's CoverageMerger, which
    // runs in the parent pest process and OOMs below 2G on ~1000-test fresh
    // graph recordings (512M was not enough).
    $limit = ini_get('memory_limit');

    // '-1' is unlimited, which trivially satisfies any budget.
    $bytes = $limit === '-1' ? PHP_INT_MAX : shorthandMemoryToBytes($limit);

    expect($bytes)->toBeGreaterThanOrEqual(
        2 * 1024 ** 3,
        "effective memory_limit is '{$limit}' — the phpunit.xml <ini> pin must stay >= 2G",
    );
});

function shorthandMemoryToBytes(string $value): int
{
    return match (strtoupper(substr($value, -1))) {
        'G' => (int) $value * 1024 ** 3,
        'M' => (int) $value * 1024 ** 2,
        'K' => (int) $value * 1024,
        default => (int) $value,
    };
}
