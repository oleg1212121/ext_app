<?php

/**
 * Pre-flight guard for the suite-scale composer test scripts: refuse to
 * start while another Pest run is live. Two concurrent runs share the
 * ext_app_test_test_{N} worker databases and corrupt each other mid-test
 * (mass-failure waves — see wiki/playbooks/running-tests.md).
 *
 * Exits 1 with the offending pids when a run is in flight, 0 when clear.
 */

require __DIR__.'/../vendor/autoload.php';

use App\Support\InflightTestRunDetector;

$inflight = (new InflightTestRunDetector)->detect();

if ($inflight === []) {
    exit(0);
}

fwrite(STDERR, "Refusing to start: another test run is already in flight\n");

foreach ($inflight as $pid => $command) {
    fwrite(STDERR, "  {$pid}: {$command}\n");
}

fwrite(STDERR, <<<'TXT'

Wait for it to finish, or kill it with the /proc loop in
wiki/playbooks/running-tests.md ("In-flight runs" — the container has no ps).
TXT);

exit(1);
