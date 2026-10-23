<?php

namespace App\Classes;

/**
 * Validates an OKF v0.2 knowledge bundle: frontmatter conformance, broken
 * cross-links, freshness (stale_after), and source provenance signals.
 *
 * Warnings can be acknowledged in a checked-in baseline (a
 * validate-baseline.json inside the bundle root): JSON `entries` of
 * {warning, owner, date, note} — each requiring a non-empty string
 * warning, owner and date, since the owner+date is what makes an
 * acknowledgement accountable — matched by exact message equality, so
 * known-benign signals (typically source-mtime drift) keep the run green
 * while any NEW warning fails it. A malformed baseline is reported as an
 * error — never silently ignored.
 */
class WikiValidator
{
    public const BASELINE_FILENAME = 'validate-baseline.json';

    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<int, string> */
    private array $warnings = [];

    /** @var array<int, string> */
    private array $baselineErrors = [];

    /** @var array<int, array<string, mixed>> valid baseline entries, in file order */
    private array $baselineEntries = [];

    /** @var array<int, array<string, mixed>> baseline entries matching a current warning (the prune keep-set) */
    private array $matchingBaselineEntries = [];

    /** @var array<int, array<string, mixed>> baseline entries matching no current warning (pruning candidates) */
    private array $staleBaselineEntries = [];

    /** @var array<int, string> */
    private array $acknowledged = [];

    /** @var array<int, string> */
    private array $unacknowledged = [];

    public function __construct(private readonly string $root) {}

    /**
     * @return array<int, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<int, string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Warnings acknowledged by a baseline entry (exact message equality).
     *
     * @return array<int, string>
     */
    public function acknowledgedWarnings(): array
    {
        return $this->acknowledged;
    }

    /**
     * Warnings with no matching baseline entry — the ones that fail the run.
     *
     * @return array<int, string>
     */
    public function unacknowledgedWarnings(): array
    {
        return $this->unacknowledged;
    }

    /**
     * Problems with the baseline file itself (invalid JSON, wrong shape,
     * entries without a usable warning message). Reported as errors.
     *
     * @return array<int, string>
     */
    public function baselineErrors(): array
    {
        return $this->baselineErrors;
    }

    /**
     * Path the baseline is read from (bundle root).
     */
    public function baselinePath(): string
    {
        return $this->root.'/'.self::BASELINE_FILENAME;
    }

    /**
     * All valid baseline entries, in file order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function baselineEntries(): array
    {
        return $this->baselineEntries;
    }

    /**
     * Baseline entries matching no current warning — pruning candidates.
     *
     * @return array<int, array<string, mixed>>
     */
    public function staleBaselineEntries(): array
    {
        return $this->staleBaselineEntries;
    }

    /**
     * Baseline entries to keep when pruning (those matching a current warning).
     *
     * @return array<int, array<string, mixed>>
     */
    public function prunedBaselineEntries(): array
    {
        return $this->matchingBaselineEntries;
    }

    /**
     * Serialize baseline entries to the canonical on-disk format.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    public static function encodeBaseline(array $entries): string
    {
        return json_encode(
            ['entries' => array_values($entries)],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ).PHP_EOL;
    }

    public function validate(): self
    {
        $this->errors = [];
        $this->warnings = [];
        $this->baselineErrors = [];
        $this->baselineEntries = [];
        $this->matchingBaselineEntries = [];
        $this->staleBaselineEntries = [];
        $this->acknowledged = [];
        $this->unacknowledged = [];

        if (! is_dir($this->root)) {
            $this->errors[] = "Wiki bundle not found at {$this->root}";

            return $this;
        }

        foreach (WikiBundle::markdownFiles($this->root) as $relativePath) {
            $this->validateFile($relativePath);
        }

        $this->loadBaseline();

        return $this;
    }

    private function loadBaseline(): void
    {
        $path = $this->baselinePath();

        if (! is_file($path)) {
            $this->unacknowledged = $this->warnings;

            return;
        }

        $raw = file_get_contents($path);
        $decoded = $raw === false ? null : json_decode($raw, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->baselineErrors[] = "{$path}: baseline is not valid JSON — ".json_last_error_msg();
            $this->unacknowledged = $this->warnings;

            return;
        }

        if (! is_array($decoded) || ! array_key_exists('entries', $decoded) || ! is_array($decoded['entries'])) {
            $this->baselineErrors[] = "{$path}: baseline must be a JSON object with an \"entries\" array";
            $this->unacknowledged = $this->warnings;

            return;
        }

        $acknowledgedMessages = [];
        foreach ($decoded['entries'] as $index => $entry) {
            if (! is_array($entry)
                || ! self::isNonEmptyString($entry['warning'] ?? null)
                || ! self::isNonEmptyString($entry['owner'] ?? null)
                || ! self::isNonEmptyString($entry['date'] ?? null)
            ) {
                $this->baselineErrors[] = "{$path}: entries[{$index}] must be an object with non-empty string \"warning\", \"owner\" and \"date\"";

                continue;
            }

            $this->baselineEntries[] = $entry;

            if (in_array($entry['warning'], $this->warnings, true)) {
                $this->matchingBaselineEntries[] = $entry;
                $acknowledgedMessages[$entry['warning']] = true;
            } else {
                $this->staleBaselineEntries[] = $entry;
            }
        }

        $this->acknowledged = array_values(array_filter(
            $this->warnings,
            fn (string $warning): bool => isset($acknowledgedMessages[$warning]),
        ));
        $this->unacknowledged = array_values(array_filter(
            $this->warnings,
            fn (string $warning): bool => ! isset($acknowledgedMessages[$warning]),
        ));
    }

    private static function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function validateFile(string $relativePath): void
    {
        $content = file_get_contents($this->root.'/'.$relativePath);
        $parsed = null;

        try {
            $parsed = $content === false ? null : WikiBundle::parse($content);
        } catch (\Throwable $e) {
            $this->errors[] = "{$relativePath}: frontmatter parse error — {$e->getMessage()}";

            return;
        }

        if (WikiBundle::isReserved($relativePath)) {
            $this->validateReservedFile($relativePath, $parsed);
        } else {
            $this->validateConcept($relativePath, $parsed);
        }

        $this->checkLinks($relativePath, $parsed['body'] ?? ($content ?: ''));
    }

    private function validateReservedFile(string $relativePath, ?array $parsed): void
    {
        if ($parsed === null) {
            return;
        }

        $basename = basename($relativePath);
        $allowed = ($relativePath === 'index.md' && $basename === 'index.md') ? ['okf_version'] : [];
        $extra = array_diff(array_keys($parsed['frontmatter']), $allowed);

        if ($extra !== []) {
            $this->warnings[] = "{$relativePath}: reserved file carries unexpected frontmatter keys: "
                .implode(', ', $extra);
        }
    }

    private function validateConcept(string $relativePath, ?array $parsed): void
    {
        if ($parsed === null) {
            $this->errors[] = "{$relativePath}: missing frontmatter block (required for concept documents)";

            return;
        }

        $frontmatter = $parsed['frontmatter'];

        $type = $frontmatter['type'] ?? null;
        if (! is_string($type) || trim($type) === '') {
            $this->errors[] = "{$relativePath}: frontmatter missing required non-empty 'type'";
        }

        $status = $frontmatter['status'] ?? 'stable';
        if (! in_array($status, ['draft', 'stable', 'deprecated'], true)) {
            $this->warnings[] = "{$relativePath}: unknown status '{$status}' (expected draft|stable|deprecated)";
        }

        $staleAfter = $frontmatter['stale_after'] ?? null;
        if ($staleAfter !== null) {
            $staleTs = strtotime((string) $staleAfter);
            if ($staleTs === false) {
                $this->warnings[] = "{$relativePath}: unparseable stale_after '{$staleAfter}'";
            } elseif (time() >= $staleTs) {
                $this->warnings[] = "{$relativePath}: content is stale (stale_after {$staleAfter})";
            }
        }

        $this->checkSources($relativePath, $frontmatter);
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    private function checkSources(string $relativePath, array $frontmatter): void
    {
        $generatedAt = $frontmatter['generated']['at'] ?? null;
        $generatedTs = is_string($generatedAt) ? strtotime($generatedAt) : null;
        $repoRoot = dirname($this->root);
        $skipMtimeStaleness = (bool) config('wiki.skip_mtime_staleness', false);

        foreach (($frontmatter['sources'] ?? []) as $index => $source) {
            $resource = $source['resource'] ?? null;

            if (! is_string($resource) || $resource === '') {
                $this->warnings[] = "{$relativePath}: sources[{$index}] is missing a resource";

                continue;
            }

            if (preg_match('#^https?://#', $resource) || str_contains($resource, ' ')) {
                continue; // external URL or scope descriptor — nothing to check on disk
            }

            $sourcePath = str_starts_with($resource, '/')
                ? $this->root.$resource
                : $repoRoot.'/'.$resource;

            if (! file_exists($sourcePath)) {
                $this->warnings[] = "{$relativePath}: source not found: {$resource}";

                continue;
            }

            if (! $skipMtimeStaleness && $generatedTs !== null && is_file($sourcePath) && filemtime($sourcePath) > $generatedTs) {
                $this->warnings[] = "{$relativePath}: source '{$resource}' changed after generated.at — possibly stale";
            }
        }
    }

    private function checkLinks(string $relativePath, string $body): void
    {
        $text = preg_replace('/^```.*?^```/ms', '', $body) ?? '';

        if (! preg_match_all('/!?\[[^\]]*\]\(([^)\s]+)(?:\s+"[^"]*")?\)/', $text, $matches)) {
            return;
        }

        foreach ($matches[1] as $target) {
            if (preg_match('#^(https?://|mailto:)#', $target)) {
                continue;
            }

            $target = preg_replace('/#.*$/', '', $target) ?? '';

            if ($target === '') {
                continue;
            }

            $fullPath = str_starts_with($target, '/')
                ? $this->normalizePath($this->root.$target)
                : $this->normalizePath(dirname($this->root.'/'.$relativePath).'/'.$target);

            if (! file_exists($fullPath)) {
                $this->errors[] = "{$relativePath}: broken link -> {$target}";
            }
        }
    }

    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }
}
