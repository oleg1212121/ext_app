<?php

namespace App\Support;

/**
 * Detects an in-flight Pest run by scanning /proc command lines.
 *
 * The container image ships no procps (no ps/pgrep), and two concurrent test
 * runs corrupt each other: Pest's parallel workers on both sides share the
 * ext_app_test_test_{N} databases, so one side's drop-and-re-migrate pulls
 * tables out from under the other mid-test. The composer test scripts refuse
 * to start while this detector finds a live Pest process
 * (scripts/assert-no-inflight-test.php).
 */
class InflightTestRunDetector
{
    public function __construct(private readonly string $procRoot = '/proc') {}

    /**
     * @return array<int, string> pid => command line of every in-flight Pest process
     */
    public function detect(): array
    {
        $inflight = [];

        foreach (glob($this->procRoot.'/[0-9]*', GLOB_ONLYDIR) ?: [] as $dir) {
            // Kernel threads expose an empty cmdline; a pid can also vanish
            // between the glob and the read — both are "nothing running".
            $cmdline = @file_get_contents($dir.'/cmdline');

            if ($cmdline === false || $cmdline === '') {
                continue;
            }

            $command = trim(str_replace("\0", ' ', $cmdline));

            // Two invocation forms reach Pest: "php vendor/bin/pest ..."
            // (composer) and "/usr/local/bin/php vendor/pestphp/pest/bin/pest
            // ..." (artisan test's shebang exec — argv[0] resolved to the
            // absolute interpreter). Matching argv[0] as a php binary by
            // basename covers both without matching the sh -c wrappers,
            // whose command lines carry "pest" as payload text.
            $binary = strtok($command, ' ');

            if (preg_match('#(^|/)php(\d+(\.\d+)*)?$#', (string) $binary) === 1 && str_contains($command, 'pest')) {
                $inflight[(int) basename($dir)] = $command;
            }
        }

        ksort($inflight);

        return $inflight;
    }
}
