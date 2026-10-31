<?php

use App\Support\InflightTestRunDetector;

/*
 * The detector scans /proc directly (the container image has no procps), so
 * the fixtures fake a /proc tree: one directory per pid, cmdline files with
 * NUL-separated argv exactly like the kernel exposes them.
 */

function inflightProcFixture(string $dir): string
{
    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function inflightProcProcess(string $procRoot, int $pid, string ...$argv): void
{
    $dir = $procRoot.'/'.$pid;

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    file_put_contents($dir.'/cmdline', implode("\0", $argv)."\0");
}

it('detects live pest processes in both invocation forms', function () {
    $root = inflightProcFixture(sys_get_temp_dir().'/inflight-'.uniqid());
    // composer's form: relative php, vendor/bin/pest
    inflightProcProcess($root, 42, 'php', 'vendor/bin/pest', '--parallel', '--processes=6');
    // artisan test's form: shebang exec resolves argv[0] to the absolute php
    inflightProcProcess($root, 43, '/usr/local/bin/php', 'vendor/pestphp/pest/bin/pest', '--configuration=/var/www/phpunit.xml');

    try {
        expect((new InflightTestRunDetector($root))->detect())->toBe([
            42 => 'php vendor/bin/pest --parallel --processes=6',
            43 => '/usr/local/bin/php vendor/pestphp/pest/bin/pest --configuration=/var/www/phpunit.xml',
        ]);
    } finally {
        inflightProcCleanup($root);
    }
});

it('ignores non-test php processes, shell wrappers, and other users', function () {
    $root = inflightProcFixture(sys_get_temp_dir().'/inflight-'.uniqid());
    // The sh -c wrapper carries "pest" as payload text — must not match.
    inflightProcProcess($root, 10, 'sh', '-c', 'LARAVEL_PARALLEL_TESTING=1 php vendor/bin/pest --parallel');
    inflightProcProcess($root, 11, 'php', 'artisan', 'serve');
    inflightProcProcess($root, 12, 'php', 'scripts/assert-no-inflight-test.php');
    inflightProcProcess($root, 13, 'node', '/usr/bin/node', 'vite');
    inflightProcProcess($root, 14, 'php-fpm', 'worker');

    try {
        expect((new InflightTestRunDetector($root))->detect())->toBe([]);
    } finally {
        inflightProcCleanup($root);
    }
});

it('skips pid directories with no readable cmdline', function () {
    $root = inflightProcFixture(sys_get_temp_dir().'/inflight-'.uniqid());
    mkdir($root.'/99', 0777, true); // kernel-thread shape: cmdline exists but is empty
    file_put_contents($root.'/99/cmdline', '');
    inflightProcProcess($root, 100, 'php', 'vendor/bin/pest');

    try {
        expect((new InflightTestRunDetector($root))->detect())
            ->toBe([100 => 'php vendor/bin/pest'])
            ->and((new InflightTestRunDetector($root.'/does-not-exist'))->detect())->toBe([]);
    } finally {
        inflightProcCleanup($root);
    }
});

function inflightProcCleanup(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir.'/'.$entry;

        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $inner) {
                if ($inner !== '.' && $inner !== '..') {
                    @unlink($path.'/'.$inner);
                }
            }

            rmdir($path);
        } else {
            @unlink($path);
        }
    }

    rmdir($dir);
}
