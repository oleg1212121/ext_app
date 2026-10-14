<?php

namespace App\Classes;

use App\Models\Definition;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordClass;
use App\Support\WordLookupKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ingests the converted GoldenDict JSONL (one {"word", "alts", "html"}
 * record per line, produced by the python service's goldendict_convert.py)
 * into the unified dictionary tables: headwords into words, plain-text
 * article segments into definitions, IPA in brackets/slashes into
 * transcriptions, alternate headwords into forms.
 *
 * The extraction is a generic heuristic (block-level text segments, pos-ish
 * abbreviations, IPA-delimited runs); per-dictionary extraction profiles
 * that tune it are a later ticket. Raw article HTML never enters the
 * database — the JSONL stays on disk and can be re-imported anytime.
 */
class GoldenDictParser
{
    private const HEARTBEAT_EVERY = 100_000;

    private const DEFINITION_CAP = 500;

    private const TRANSCRIPTION_CAP = 100;

    private const FORM_CAP = 256;

    /** Block-level elements: text nodes group into one segment per block. */
    private const BLOCK_TAGS = [
        'p', 'div', 'li', 'td', 'th', 'tr', 'table', 'thead', 'tbody', 'tfoot',
        'ul', 'ol', 'dl', 'dd', 'dt', 'section', 'article', 'header', 'footer',
        'blockquote', 'pre', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'body',
    ];

    /** POS abbreviations dictionary articles use, mapped to class slugs. */
    private const POS_BY_ABBREVIATION = [
        'n' => 'noun', 'noun' => 'noun', 'сущ' => 'noun',
        'v' => 'verb', 'vb' => 'verb', 'verb' => 'verb', 'гл' => 'verb',
        'adj' => 'adjective', 'adjective' => 'adjective', 'прил' => 'adjective',
        'adv' => 'adverb', 'adverb' => 'adverb', 'нареч' => 'adverb',
        'prep' => 'preposition', 'preposition' => 'preposition', 'предл' => 'preposition',
        'conj' => 'conjunction', 'conjunction' => 'conjunction', 'союз' => 'conjunction',
        'pron' => 'pronoun', 'pronoun' => 'pronoun', 'мест' => 'pronoun',
        'num' => 'numeral', 'numeral' => 'numeral', 'числ' => 'numeral',
        'interj' => 'interjection', 'int' => 'interjection', 'interjection' => 'interjection', 'межд' => 'interjection',
        'part' => 'particle', 'particle' => 'particle', 'част' => 'particle',
        'art' => 'article', 'article' => 'article',
    ];

    /** One char from this set marks a delimited run as IPA, not prose. */
    private const IPA_MARKERS = 'əɪʊʃθðŋʒæɑɒʌɛɔɐøʏɽʂʈʋɥɦʝɫɭʎɱɲɸβɣχʁʔˈˌː';

    private int $languageId;

    private int $batchSize;

    private bool $dryRun;

    /** @var array<string, int> */
    private array $wordClassMap = [];

    /** @var array<string, int> */
    private array $transcriptionTypeMap = [];

    private array $stats = [
        'entries_read' => 0,
        'entries_skipped' => 0,
        'words_imported' => 0,
        'definitions_imported' => 0,
        'transcriptions_imported' => 0,
        'forms_imported' => 0,
        'lookups_created' => 0,
        'batches_flushed' => 0,
    ];

    public function __construct(string $lang, int $batchSize = 500, bool $dryRun = false)
    {
        $language = Language::query()->where('code', $lang)->first();
        if ($language === null) {
            throw new \InvalidArgumentException("Unsupported language: {$lang}");
        }

        $this->languageId = $language->id;
        $this->batchSize = max(1, $batchSize);
        $this->dryRun = $dryRun;
    }

    /**
     * @return array{entries_read: int, entries_skipped: int, words_imported: int, definitions_imported: int, transcriptions_imported: int, forms_imported: int, lookups_created: int, batches_flushed: int}
     */
    public function import(string $path, ?OutputInterface $output = null, ?int $maxEntries = null): array
    {
        if (! file_exists($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        $maxEntries = ($maxEntries !== null && $maxEntries > 0) ? $maxEntries : null;

        $this->loadLookupMaps();

        $progressBar = null;
        if ($output) {
            $progressBar = new ProgressBar($output);
            $progressBar->setFormat('[%current% entries] [%elapsed%] %message%');
            $progressBar->setMessage('Parsing...');
            $progressBar->start();
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$path}");
        }

        $batch = [];
        $startedAt = microtime(true);

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line);
                if (! is_object($decoded) || ! isset($decoded->word) || trim((string) $decoded->word) === '') {
                    $this->stats['entries_skipped']++;

                    continue;
                }

                $this->stats['entries_read']++;

                $record = $this->parseEntry($decoded);

                if ($this->dryRun) {
                    $this->tallyRecord($record);
                } else {
                    $batch[] = $record;
                    if (count($batch) >= $this->batchSize) {
                        $this->flushBatch($batch);
                        $batch = [];
                    }
                }

                if ($this->stats['entries_read'] % 1000 === 0) {
                    if ($progressBar) {
                        $progressBar->setProgress($this->stats['entries_read']);
                        $progressBar->setMessage("Words: {$this->stats['words_imported']}");
                    }

                    if (memory_get_usage(true) > 100 * 1024 * 1024 && $batch !== []) {
                        $this->flushBatch($batch);
                        $batch = [];
                    }
                }

                if ($this->stats['entries_read'] % self::HEARTBEAT_EVERY === 0) {
                    $this->heartbeat($startedAt, $progressBar);
                }

                if ($maxEntries !== null && $this->stats['entries_read'] >= $maxEntries) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        if (! $this->dryRun && $batch !== []) {
            $this->flushBatch($batch);
        }

        if ($progressBar) {
            $progressBar->finish();
            $output->writeln('');
        }

        if (! $this->dryRun) {
            // The dictionary just grew: previously unmatchable entity tokens
            // of this language get another linking chance.
            EntityWordLinker::clearUnmatchedForLanguage($this->languageId);
        }

        $this->heartbeat($startedAt, null, final: true);

        return $this->stats;
    }

    /**
     * @return array{word: string, l_word: string, pos: string, definitions: list<string>, transcriptions: list<string>, forms: list<string>}
     */
    public function parseEntry(object $entry): array
    {
        $word = trim((string) $entry->word);

        $forms = [];
        foreach ($entry->alts ?? [] as $alt) {
            if (is_string($alt) && trim($alt) !== '') {
                $forms[] = trim($alt);
            }
        }

        $html = is_string($entry->html ?? null) ? $entry->html : '';
        $article = $this->parseHtml($html);

        $posHints = $article['posHints'];
        $classSlug = str_contains($word, ' ')
            ? 'phrase'
            : ($posHints[0] ?? 'unknown');

        return [
            'word' => $word,
            'l_word' => WordLookupKey::normalize($word),
            'pos' => $classSlug,
            'definitions' => array_values(array_unique(array_map(
                fn (string $segment) => mb_substr($segment, 0, self::DEFINITION_CAP),
                $article['segments'],
            ))),
            'transcriptions' => $article['ipa'],
            'forms' => array_values(array_unique($forms)),
        ];
    }

    /**
     * @return array{segments: list<string>, ipa: list<string>, posHints: list<string>}
     */
    private function parseHtml(string $html): array
    {
        $empty = ['segments' => [], 'ipa' => [], 'posHints' => []];
        if (trim($html) === '') {
            return $empty;
        }

        $dom = new \DOMDocument;
        $useInternal = libxml_use_internal_errors(true);
        try {
            // The XML prolog forces UTF-8; loadHTML would assume Latin-1.
            $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($useInternal);
        }

        if (! $loaded) {
            return $empty;
        }

        foreach ($dom->childNodes as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                $dom->removeChild($child);
                break;
            }
        }

        $xpath = new \DOMXPath($dom);
        foreach (iterator_to_array($xpath->query('//script|//style') ?? []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        return [
            'segments' => $this->extractSegments($xpath),
            'ipa' => $this->extractIpa($dom->textContent),
            'posHints' => $this->extractPosHints($xpath),
        ];
    }

    /**
     * Plain-text article segments: text nodes grouped by their nearest
     * block-level ancestor, whitespace-normalized, empty groups dropped.
     *
     * @return list<string>
     */
    private function extractSegments(\DOMXPath $xpath): array
    {
        $byBlock = [];
        foreach ($xpath->query('//text()') as $textNode) {
            // Key by node path, not spl_object_id: PHP recycles wrapper
            // object handles mid-loop, which would merge unrelated blocks.
            $blockKey = $this->nearestBlock($textNode)->getNodePath();
            $byBlock[$blockKey] ??= '';
            $byBlock[$blockKey] .= $textNode->nodeValue ?? '';
        }

        $segments = [];
        foreach ($byBlock as $text) {
            $segment = trim((string) preg_replace('/\s+/u', ' ', $text));
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    private function nearestBlock(\DOMNode $node): \DOMNode
    {
        for ($current = $node; $current !== null; $current = $current->parentNode) {
            if (in_array($current->nodeName, self::BLOCK_TAGS, true)) {
                return $current;
            }
        }

        return $node->ownerDocument ?? $node;
    }

    /**
     * IPA runs in square brackets or slashes, brackets stripped, deduped,
     * capped to the column length. Prose in delimiters is filtered by the
     * IPA-marker and shape checks.
     *
     * @return list<string>
     */
    private function extractIpa(string $text): array
    {
        $values = [];

        if (preg_match_all('~(?<![\p{L}\p{N}/])/([^/\n]{1,100}?)/(?![\p{L}\p{N}/])~u', $text, $slashes) === 1) {
            foreach ($slashes[1] as $candidate) {
                if ($this->looksLikeIpa($candidate)) {
                    $values[] = $candidate;
                }
            }
        }

        if (preg_match_all('~\[([^\[\]\n]{1,100})\]~u', $text, $brackets) === 1) {
            foreach ($brackets[1] as $candidate) {
                if ($this->looksLikeIpa($candidate)) {
                    $values[] = $candidate;
                }
            }
        }

        return array_values(array_unique(array_map(
            fn (string $value) => mb_substr(trim($value), 0, self::TRANSCRIPTION_CAP),
            $values,
        )));
    }

    private function looksLikeIpa(string $candidate): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '') {
            return false;
        }

        // Ordinary prose never carries IPA-only letters or prosody marks.
        if (preg_match('/['.preg_quote(self::IPA_MARKERS, '/').']/u', $candidate) !== 1) {
            return false;
        }

        // Multi-word or punctuated runs are quoted usage, not transcription.
        if (preg_match('/[,;:!?]/', $candidate) === 1 || substr_count($candidate, ' ') > 1) {
            return false;
        }

        return true;
    }

    /**
     * POS hints: elements carrying a "pos" class, then abbr/i/em whose whole
     * text is a known POS abbreviation.
     *
     * @return list<string>
     */
    private function extractPosHints(\DOMXPath $xpath): array
    {
        $slugs = [];
        $queries = [
            '//*[contains(concat(" ", normalize-space(@class), " "), " pos ")]',
            '//abbr | //i | //em',
        ];

        foreach ($queries as $query) {
            foreach ($xpath->query($query) as $node) {
                $slug = $this->posSlugFor((string) $node->textContent);
                if ($slug !== null) {
                    $slugs[] = $slug;
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    private function posSlugFor(string $text): ?string
    {
        $normalized = mb_strtolower(trim($text));
        $normalized = trim($normalized, '.,;:()[]{}');
        if ($normalized === '' || mb_strlen($normalized) > 12) {
            return null;
        }

        return self::POS_BY_ABBREVIATION[$normalized] ?? null;
    }

    private function tallyRecord(array $record): void
    {
        $this->ensureWordClass($record['pos']);
        $this->stats['words_imported']++;
        $this->stats['definitions_imported'] += count($record['definitions']);
        if ($record['transcriptions'] !== []) {
            $this->ensureTranscriptionType('ipa');
            $this->stats['transcriptions_imported'] += count($record['transcriptions']);
        }
        $this->stats['forms_imported'] += count($record['forms']);
    }

    private function loadLookupMaps(): void
    {
        $this->wordClassMap = WordClass::query()
            ->where('language_id', $this->languageId)
            ->pluck('id', 'slug')
            ->toArray();

        $this->transcriptionTypeMap = TranscriptionType::query()
            ->where('language_id', $this->languageId)
            ->pluck('id', 'slug')
            ->toArray();
    }

    private function ensureWordClass(string $slug): int
    {
        if (! isset($this->wordClassMap[$slug])) {
            if ($this->dryRun) {
                // No id to hand out without writing; count it as would-be-created.
                $this->wordClassMap[$slug] = 0;
                $this->stats['lookups_created']++;

                return 0;
            }

            $class = WordClass::query()->firstOrCreate(
                ['language_id' => $this->languageId, 'slug' => $slug],
                ['title' => $slug],
            );
            $this->wordClassMap[$slug] = $class->id;
            $this->stats['lookups_created']++;
        }

        return $this->wordClassMap[$slug];
    }

    private function ensureTranscriptionType(string $slug): int
    {
        if (! isset($this->transcriptionTypeMap[$slug])) {
            if ($this->dryRun) {
                $this->transcriptionTypeMap[$slug] = 0;
                $this->stats['lookups_created']++;

                return 0;
            }

            $type = TranscriptionType::query()->firstOrCreate(
                ['language_id' => $this->languageId, 'slug' => $slug],
                ['title' => $slug],
            );
            $this->transcriptionTypeMap[$slug] = $type->id;
            $this->stats['lookups_created']++;
        }

        return $this->transcriptionTypeMap[$slug];
    }

    public function flushBatch(array $batch): void
    {
        DB::transaction(function () use ($batch) {
            $wordUpserts = [];
            foreach ($batch as $record) {
                $wordUpserts[] = [
                    'word' => $record['word'],
                    'l_word' => $record['l_word'],
                    'language_id' => $this->languageId,
                    'word_class_id' => $this->ensureWordClass($record['pos']),
                    'frequency' => Word::FREQUENCY_UNRANKED,
                ];
            }

            $this->uniqueByCompound($wordUpserts, ['word', 'language_id', 'word_class_id']);
            // On conflict refresh the lookup key only: a re-import must not
            // push the unranked sentinel onto words a frequency list ranked,
            // nor clobber translations staged for the bilingual mode.
            Word::upsert($wordUpserts, ['word', 'language_id', 'word_class_id'], ['l_word']);

            $this->stats['words_imported'] += count($wordUpserts);
            $this->stats['batches_flushed']++;

            $wordIds = Word::query()
                ->where('language_id', $this->languageId)
                ->whereIn('word', collect($batch)->pluck('word')->unique()->toArray())
                ->get(['id', 'word', 'word_class_id'])
                ->keyBy(fn ($w) => mb_strtolower($w->word).'|'.$w->word_class_id);

            $this->flushDefinitions($batch, $wordIds);
            $this->flushTranscriptions($batch, $wordIds);
            $this->flushForms($batch, $wordIds);
        });
    }

    private function flushDefinitions(array $batch, object $wordIds): void
    {
        $rows = [];
        foreach ($batch as $record) {
            $wordId = $this->lookupWordId($record, $wordIds);
            if ($wordId === null) {
                continue;
            }
            foreach ($record['definitions'] as $definition) {
                $rows[] = [
                    'definition' => $definition,
                    'word_id' => $wordId,
                ];
            }
        }
        if ($rows !== []) {
            $this->uniqueByCompound($rows, ['definition', 'word_id']);
            $this->stats['definitions_imported'] += $this->insertNewOnly(Definition::class, $rows, 'definition', 'word_id');
        }
    }

    private function flushTranscriptions(array $batch, object $wordIds): void
    {
        $rows = [];
        foreach ($batch as $record) {
            if ($record['transcriptions'] === []) {
                continue;
            }
            $wordId = $this->lookupWordId($record, $wordIds);
            if ($wordId === null) {
                continue;
            }
            $typeId = $this->ensureTranscriptionType('ipa');
            foreach ($record['transcriptions'] as $value) {
                $rows[] = [
                    'transcription' => $value,
                    'word_id' => $wordId,
                    'transcription_type_id' => $typeId,
                ];
            }
        }
        if ($rows !== []) {
            $this->uniqueByCompound($rows, ['transcription', 'word_id', 'transcription_type_id']);
            Transcription::upsert($rows, ['transcription', 'word_id', 'transcription_type_id']);
            $this->stats['transcriptions_imported'] += count($rows);
        }
    }

    private function flushForms(array $batch, object $wordIds): void
    {
        $rows = [];
        foreach ($batch as $record) {
            if ($record['forms'] === []) {
                continue;
            }
            $wordId = $this->lookupWordId($record, $wordIds);
            if ($wordId === null) {
                continue;
            }
            foreach ($record['forms'] as $form) {
                $form = mb_substr($form, 0, self::FORM_CAP);
                $rows[] = [
                    'form' => $form,
                    'l_word' => WordLookupKey::normalize($form),
                    'word_id' => $wordId,
                ];
            }
        }
        if ($rows !== []) {
            $this->uniqueByCompound($rows, ['form', 'word_id']);
            Form::upsert($rows, ['form', 'word_id']);
            $this->stats['forms_imported'] += count($rows);
        }
    }

    private function lookupWordId(array $record, object $wordIds): ?int
    {
        $key = mb_strtolower($record['word']).'|'.$this->wordClassMap[$record['pos']];

        return $wordIds[$key]->id ?? null;
    }

    /**
     * Insert only rows whose (word_id, text) pair is absent from the DB and
     * from the given batch: definitions carry no unique index, so re-runs
     * dedupe by text instead. Returns the number of rows inserted.
     */
    private function insertNewOnly(string $model, array $rows, string $textColumn, string $fkColumn): int
    {
        $wordIds = collect($rows)->pluck($fkColumn)->unique()->toArray();

        $existing = [];
        foreach (array_chunk($wordIds, 100) as $chunk) {
            foreach ($model::whereIn($fkColumn, $chunk)->get([$fkColumn, $textColumn]) as $row) {
                // A foreach, not an each(arrow fn): arrow functions capture
                // by value, so the write would never reach $existing.
                $existing[$row->{$fkColumn}.'|'.mb_strtolower($row->{$textColumn})] = true;
            }
        }

        $newRows = array_values(array_filter($rows, function ($row) use ($existing, $textColumn, $fkColumn) {
            $key = $row[$fkColumn].'|'.mb_strtolower($row[$textColumn]);

            return ! isset($existing[$key]);
        }));

        // Dedupe within the batch itself (uniqueByCompound keyed rows share keys).
        $seen = [];
        $newRows = array_values(array_filter($newRows, function ($row) use (&$seen, $textColumn, $fkColumn) {
            $key = $row[$fkColumn].'|'.mb_strtolower($row[$textColumn]);
            if (isset($seen[$key])) {
                return false;
            }

            return $seen[$key] = true;
        }));

        if ($newRows !== []) {
            $model::insert($newRows);
        }

        return count($newRows);
    }

    public function uniqueByCompound(array &$rows, array $keyColumns): void
    {
        $seen = [];
        $result = [];
        foreach ($rows as $row) {
            $key = implode('|', array_map(fn ($col) => (string) ($row[$col] ?? ''), $keyColumns));
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $row;
            }
        }
        $rows = $result;
    }

    private function heartbeat(float $startedAt, ?ProgressBar $progressBar, bool $final = false): void
    {
        $message = sprintf(
            '%s entries=%d skipped=%d words=%d rss=%sMB elapsed=%ss',
            $final ? 'GoldenDict import done:' : 'GoldenDict import heartbeat:',
            $this->stats['entries_read'],
            $this->stats['entries_skipped'],
            $this->stats['words_imported'],
            round(memory_get_usage(true) / 1024 / 1024, 1),
            round(microtime(true) - $startedAt, 1),
        );

        Log::info($message);

        if ($progressBar) {
            $progressBar->setMessage($message);
            $progressBar->display();
        }
    }
}
