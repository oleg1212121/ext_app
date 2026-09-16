<?php

namespace App\Classes;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Streams a monolithic wiktextract JSONL dump (optionally gzipped) once and
 * splits it into per-language extract files, keeping only raw lines whose
 * top-level lang_code matches one of the wanted codes.
 *
 * Memory stays flat: exactly one line is held in memory at any moment.
 * A substring pre-filter skips json_decode for lines that cannot belong to a
 * wanted language — the dominating cost on a mixed-language dump.
 */
class RawWiktextractExtractor
{
    private const HEARTBEAT_EVERY = 100_000;

    /**
     * @param  list<string>  $langCodes  language codes to keep (must be distinct)
     * @param  int|null  $maxLines  stop after reading this many raw lines (smoke runs)
     * @return array{lines_read: int, kept: array<string, int>, skipped: int, decoded: int, truncated: bool, files: array<string, string>}
     */
    public function extract(string $path, array $langCodes, ?OutputInterface $output = null, ?int $maxLines = null): array
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        if ($langCodes === []) {
            throw new \InvalidArgumentException('At least one language code is required.');
        }

        $base = $this->extractBasePath($path);

        $needleSets = [];
        $handles = [];
        $stats = [
            'lines_read' => 0,
            'kept' => array_fill_keys($langCodes, 0),
            'skipped' => 0,
            'decoded' => 0,
            'truncated' => false,
            'files' => [],
        ];

        foreach ($langCodes as $code) {
            // Kaikki emits Python json.dumps output: default separators use
            // ": " while wiktextract builds may use compact ":". Match both.
            $needleSets[$code] = [
                '"lang_code": "'.$code.'"',
                '"lang_code":"'.$code.'"',
            ];

            $outPath = $base.'.'.$code.'.jsonl';
            $handle = fopen($outPath, 'w');
            if ($handle === false) {
                foreach ($handles as $open) {
                    fclose($open);
                }

                throw new \RuntimeException("Cannot open extract file for writing: {$outPath}");
            }
            $handles[$code] = $handle;
            $stats['files'][$code] = $outPath;
        }

        $source = str_ends_with(strtolower($path), '.gz') ? 'compress.zlib://'.$path : $path;
        $input = fopen($source, 'r');
        if ($input === false) {
            foreach ($handles as $open) {
                fclose($open);
            }

            throw new \RuntimeException("Cannot open file: {$path}");
        }

        $progressBar = null;
        if ($output) {
            $progressBar = new ProgressBar($output);
            $progressBar->setFormat('[%current% lines] [%elapsed%] %message%');
            $progressBar->setMessage('Extracting...');
            $progressBar->start();
        }

        $startedAt = microtime(true);

        try {
            while (($line = fgets($input)) !== false) {
                if ($maxLines !== null && $stats['lines_read'] >= $maxLines) {
                    $stats['truncated'] = true;
                    break;
                }

                $stats['lines_read']++;

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $code = $this->matchLanguage($line, $langCodes, $needleSets);

                if ($code === null) {
                    $stats['skipped']++;

                    continue;
                }

                fwrite($handles[$code], $line."\n");
                $stats['kept'][$code]++;
                $stats['decoded']++;

                if ($stats['lines_read'] % self::HEARTBEAT_EVERY === 0) {
                    $this->heartbeat($stats, $startedAt, $progressBar);
                }
            }
        } finally {
            fclose($input);
            foreach ($handles as $open) {
                fclose($open);
            }
        }

        if ($progressBar) {
            $progressBar->finish();
            $output->writeln('');
        }

        $this->heartbeat($stats, $startedAt, null, final: true);

        return $stats;
    }

    /**
     * Cheap substring pre-filter; only a line passing it is decoded, and the
     * decoded top-level lang_code decides (guards against the needle matching
     * inside nested structures, e.g. a translation entry of another language).
     */
    private function matchLanguage(string $line, array $langCodes, array $needleSets): ?string
    {
        $decoded = null;

        foreach ($langCodes as $code) {
            foreach ($needleSets[$code] as $needle) {
                if (! str_contains($line, $needle)) {
                    continue;
                }

                $decoded ??= json_decode($line);
                $langCode = $decoded->lang_code ?? null;

                if (is_string($langCode) && in_array($langCode, $langCodes, true)) {
                    return $langCode;
                }

                // Needle hit was a nested false positive — keep scanning the
                // remaining needles before giving up on this line.
            }
        }

        return null;
    }

    /**
     * Strips the .gz/.jsonl suffixes so dumps extract next to themselves:
     * kaikki/raw-wiktextract-data.jsonl.gz -> kaikki/raw-wiktextract-data.en.jsonl
     */
    private function extractBasePath(string $path): string
    {
        $base = $path;
        if (str_ends_with(strtolower($base), '.gz')) {
            $base = substr($base, 0, -3);
        }
        if (str_ends_with(strtolower($base), '.jsonl')) {
            $base = substr($base, 0, -6);
        }

        return $base === $path ? $path.'.' : $base;
    }

    private function heartbeat(array $stats, float $startedAt, ?ProgressBar $progressBar, bool $final = false): void
    {
        $elapsed = round(microtime(true) - $startedAt, 1);
        $memory = round(memory_get_usage(true) / 1024 / 1024, 1);
        $kept = implode(', ', array_map(fn (string $code, int $n) => "{$code}: {$n}", array_keys($stats['kept']), $stats['kept']));

        $message = sprintf(
            '%s lines=%d kept=[%s] skipped=%d rss=%sMB elapsed=%ss',
            $final ? 'Extract done:' : 'Extract heartbeat:',
            $stats['lines_read'],
            $kept,
            $stats['skipped'],
            $memory,
            $elapsed,
        );

        Log::info($message);

        if ($progressBar) {
            $progressBar->setMessage($message);
            $progressBar->display();
        }
    }
}
