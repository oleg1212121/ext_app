<?php

namespace App\Console\Commands;

use App\Classes\WikiBundle;
use App\Classes\WikiValidator;
use Illuminate\Console\Command;

/**
 * Validates the OKF wiki bundle: frontmatter conformance (errors), broken
 * cross-links (errors), and freshness/provenance signals (warnings).
 *
 * The run fails on errors AND on unacknowledged warnings — warnings not
 * matched by an entry in the bundle's validate-baseline.json. Fix new
 * warnings (bump the listing concept's generated.at) or acknowledge
 * known-benign ones in the baseline with owner+date; --prune drops
 * baseline entries that no longer match any current warning (refusing
 * under the CI mtime skip, where every mtime entry would read stale,
 * and writing nothing when the file would not change).
 */
class WikiValidate extends Command
{
    protected $signature = 'wiki:validate
        {--path= : Override the wiki bundle path}
        {--prune : Drop baseline entries that no longer match any current warning}';

    protected $description = 'Validate the OKF wiki bundle (conformance, links, staleness, acknowledged-warning gate)';

    public function handle(): int
    {
        $root = WikiBundle::resolvePath($this->option('path'));

        if ($root === null) {
            $this->error('Wiki bundle not found. Expected repo-root wiki/ or /var/repo/wiki.');

            return self::FAILURE;
        }

        $this->info("Validating wiki bundle at {$root}");

        $validator = (new WikiValidator($root))->validate();

        if ($this->option('prune')) {
            $this->pruneBaseline($validator);
        }

        foreach ($validator->unacknowledgedWarnings() as $warning) {
            $this->warn("  [warn] {$warning}");
        }

        foreach ($validator->staleBaselineEntries() as $entry) {
            $this->info("  [info] stale baseline entry: {$entry['warning']} (matches no current warning; run with --prune to drop)");
        }

        $acknowledgedCount = count($validator->acknowledgedWarnings());
        if ($acknowledgedCount > 0) {
            $this->info("  [info] {$acknowledgedCount} warning(s) acknowledged in {$validator->baselinePath()}");
        }

        foreach ($validator->errors() as $error) {
            $this->error("  [error] {$error}");
        }

        foreach ($validator->baselineErrors() as $error) {
            $this->error("  [error] {$error}");
        }

        $errorCount = count($validator->errors()) + count($validator->baselineErrors());
        $unacknowledgedCount = count($validator->unacknowledgedWarnings());

        if ($errorCount > 0 || $unacknowledgedCount > 0) {
            $this->error("Wiki validation failed: {$errorCount} error(s), {$unacknowledgedCount} unacknowledged warning(s).");
            $this->error("Fix unacknowledged warnings: bump the listing concept's generated.at, or acknowledge in the baseline with owner+date ({$validator->baselinePath()}).");

            return self::FAILURE;
        }

        $this->info('Wiki bundle is conformant. '.count($validator->warnings()).' warning(s), all acknowledged.');

        return self::SUCCESS;
    }

    private function pruneBaseline(WikiValidator $validator): void
    {
        $path = $validator->baselinePath();

        if ((bool) config('wiki.skip_mtime_staleness', false)) {
            $this->error('Refusing to prune: the mtime staleness check is skipped (WIKI_SKIP_MTIME_CHECKS), so every mtime baseline entry reads as stale and pruning would gut the baseline. Run locally with the check enabled.');

            return;
        }

        if ($validator->baselineErrors() !== []) {
            $this->error("Refusing to prune: {$path} is malformed — fix or delete it first.");

            return;
        }

        $kept = $validator->prunedBaselineEntries();
        $dropped = count($validator->baselineEntries()) - count($kept);
        $encoded = WikiValidator::encodeBaseline($kept);

        if (file_get_contents($path) === $encoded) {
            $this->info("Pruned baseline {$path}: nothing to drop (dropped {$dropped}, kept ".count($kept).') — file untouched.');

            return;
        }

        if (file_put_contents($path, $encoded) === false) {
            $this->error("Could not write baseline {$path}.");

            return;
        }

        $this->info("Pruned baseline {$path}: dropped {$dropped} stale entr".($dropped === 1 ? 'y' : 'ies').', kept '.count($kept).'.');
    }
}
