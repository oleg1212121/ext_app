<?php

namespace App\Console\Commands;

use App\Classes\WikiBundle;
use App\Classes\WikiValidator;
use Illuminate\Console\Command;

/**
 * Validates the OKF wiki bundle: frontmatter conformance (errors), broken
 * cross-links (errors), and freshness/provenance signals (warnings).
 */
class WikiValidate extends Command
{
    protected $signature = 'wiki:validate {--path= : Override the wiki bundle path}';

    protected $description = 'Validate the OKF wiki bundle (conformance, links, staleness)';

    public function handle(): int
    {
        $root = WikiBundle::resolvePath($this->option('path'));

        if ($root === null) {
            $this->error('Wiki bundle not found. Expected repo-root wiki/ or /var/repo/wiki.');

            return self::FAILURE;
        }

        $this->info("Validating wiki bundle at {$root}");

        $validator = (new WikiValidator($root))->validate();

        foreach ($validator->warnings() as $warning) {
            $this->warn("  [warn] {$warning}");
        }

        foreach ($validator->errors() as $error) {
            $this->error("  [error] {$error}");
        }

        $warningCount = count($validator->warnings());
        $errorCount = count($validator->errors());

        if ($errorCount > 0) {
            $this->error("Wiki validation failed: {$errorCount} error(s), {$warningCount} warning(s).");

            return self::FAILURE;
        }

        $this->info("Wiki bundle is conformant. {$warningCount} warning(s).");

        return self::SUCCESS;
    }
}
