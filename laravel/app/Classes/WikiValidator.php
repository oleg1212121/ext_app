<?php

namespace App\Classes;

/**
 * Validates an OKF v0.2 knowledge bundle: frontmatter conformance, broken
 * cross-links, freshness (stale_after), and source provenance signals.
 */
class WikiValidator
{
    /** @var array<int, string> */
    private array $errors = [];

    /** @var array<int, string> */
    private array $warnings = [];

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

    public function validate(): self
    {
        $this->errors = [];
        $this->warnings = [];

        if (! is_dir($this->root)) {
            $this->errors[] = "Wiki bundle not found at {$this->root}";

            return $this;
        }

        foreach (WikiBundle::markdownFiles($this->root) as $relativePath) {
            $this->validateFile($relativePath);
        }

        return $this;
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
