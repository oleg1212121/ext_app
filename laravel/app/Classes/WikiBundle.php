<?php

namespace App\Classes;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Locates the OKF knowledge bundle (wiki/) and parses its documents.
 *
 * Frontmatter is parsed with a deliberately small YAML-subset parser: the
 * bundle is machine-maintained and only uses flat scalars, flow maps
 * ({ a: b }), flow lists ([ a, b ]) and block lists of maps (sources).
 * This keeps the app free of an extra YAML dependency.
 */
class WikiBundle
{
    public const RESERVED_FILENAMES = ['index.md', 'log.md'];

    /**
     * Resolve the wiki bundle root directory, or null when not found.
     */
    public static function resolvePath(?string $explicit = null): ?string
    {
        $candidates = array_filter([
            $explicit,
            getenv('WIKI_PATH') ?: null,
            // Host layout: <repo>/laravel + <repo>/wiki
            dirname(base_path()).DIRECTORY_SEPARATOR.'wiki',
            // Container layout: repo bind-mounted at /var/repo
            DIRECTORY_SEPARATOR.'var'.DIRECTORY_SEPARATOR.'repo'.DIRECTORY_SEPARATOR.'wiki',
        ]);

        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return rtrim(realpath($candidate) ?: $candidate, DIRECTORY_SEPARATOR);
            }
        }

        return null;
    }

    /**
     * All markdown files in the bundle, as bundle-relative paths (forward slashes).
     *
     * @return array<int, string>
     */
    public static function markdownFiles(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'md') {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        sort($files);

        return $files;
    }

    public static function isReserved(string $relativePath): bool
    {
        return in_array(basename($relativePath), self::RESERVED_FILENAMES, true);
    }

    /**
     * Split a document into parsed frontmatter and markdown body.
     *
     * @return array{frontmatter: array<string, mixed>, body: string}|null
     */
    public static function parse(string $content): ?array
    {
        if (! preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s', $content, $matches)) {
            return null;
        }

        return [
            'frontmatter' => self::parseFrontmatter($matches[1]),
            'body' => $matches[2],
        ];
    }

    /**
     * Minimal YAML-subset parser for OKF frontmatter.
     *
     * Supports: `key: value` scalars, `key:` followed by a block list of
     * `- key: value` maps (sources), flow maps `{ a: b, c: d }` and flow
     * lists `[ a, b ]` (nestable), and single/double quoted scalars.
     *
     * @return array<string, mixed>
     */
    public static function parseFrontmatter(string $yaml): array
    {
        $result = [];
        $currentListKey = null;

        foreach (preg_split('/\r?\n/', $yaml) ?: [] as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^\s*-\s+(.+)$/', $line, $m)) {
                if ($currentListKey === null) {
                    throw new RuntimeException("List item without a parent key: {$line}");
                }

                [$key, $value] = self::splitPair($m[1]);
                $result[$currentListKey][] = [$key => self::parseValue($value)];

                continue;
            }

            if (preg_match('/^\s+([^\s:][^:]*):(?:\s+(.*))?$/', $line, $m)) {
                if ($currentListKey === null || $result[$currentListKey] === []) {
                    throw new RuntimeException("Indented entry outside a list: {$line}");
                }

                $lastIndex = array_key_last($result[$currentListKey]);
                $result[$currentListKey][$lastIndex][$m[1]] = self::parseValue($m[2] ?? '');

                continue;
            }

            if (preg_match('/^([A-Za-z_][\w-]*):(?:\s+(.*))?$/', $line, $m)) {
                if (($m[2] ?? '') === '') {
                    $result[$m[1]] = [];
                    $currentListKey = $m[1];
                } else {
                    $result[$m[1]] = self::parseValue($m[2]);
                    $currentListKey = null;
                }

                continue;
            }

            throw new RuntimeException("Unparseable frontmatter line: {$line}");
        }

        return $result;
    }

    private static function parseValue(string $raw): mixed
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '{') && str_ends_with($raw, '}')) {
            $map = [];
            foreach (self::splitTopLevel(substr($raw, 1, -1)) as $pair) {
                [$key, $value] = self::splitPair($pair);
                $map[$key] = self::parseValue($value);
            }

            return $map;
        }

        if (str_starts_with($raw, '[') && str_ends_with($raw, ']')) {
            return array_map(
                fn (string $part) => self::parseValue($part),
                self::splitTopLevel(substr($raw, 1, -1))
            );
        }

        if (strlen($raw) >= 2
            && ((str_starts_with($raw, '"') && str_ends_with($raw, '"'))
                || (str_starts_with($raw, "'") && str_ends_with($raw, "'")))) {
            return substr($raw, 1, -1);
        }

        return $raw;
    }

    /**
     * Split on commas that are not inside braces, brackets or quotes.
     *
     * @return array<int, string>
     */
    private static function splitTopLevel(string $inner): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;

        foreach (str_split($inner) as $char) {
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return array_filter($parts, fn (string $part) => $part !== '');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitPair(string $pair): array
    {
        $position = strpos($pair, ':');

        if ($position === false) {
            throw new RuntimeException("Expected 'key: value', got: {$pair}");
        }

        return [trim(substr($pair, 0, $position)), trim(substr($pair, $position + 1))];
    }
}
