<?php

namespace App\Classes;

use App\Models\Definition;
use App\Models\Form;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
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
 * Bilingual mode (a target language) additionally reads translation
 * candidates out of each article (comma/semicolon-delimited segments with
 * transcription and grammar noise filtered — the bulk mirror of the
 * FetchWordTranslations usable-candidates rule), creates the missing
 * target-language words (source class slug mapped into the target language,
 * multi-word candidates under the phrase class, ADR 0059), stages the raw
 * strings into words.translations JSON like the Wiktionary import, and
 * writes canonical word_translations links (ADR 0020) directly — no
 * separate link pass needed for this path.
 *
 * The extraction is a generic heuristic (block-level text segments, pos-ish
 * abbreviations, IPA-delimited runs) that per-dictionary extraction profiles
 * (config/goldendict.php, selected with --profile=<slug>) layer over: XPath
 * selectors for transcription nodes, translation cells and definition blocks
 * override their heuristic counterpart per selector kind, and a selector
 * that matches nothing in an article falls back to the heuristic — entries
 * are never dropped. Raw article HTML never enters the database — the JSONL
 * stays on disk and can be re-imported anytime.
 */
class GoldenDictParser
{
    private const HEARTBEAT_EVERY = 100_000;

    private const DEFINITION_CAP = 500;

    private const TRANSCRIPTION_CAP = 100;

    private const FORM_CAP = 256;

    /** How many nothing-matched article samples to keep for profile authors. */
    private const PROFILE_MISS_SAMPLE_CAP = 5;

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
    private const IPA_MARKERS = 'əɪʊʃθðŋʒæɑɒʌɛɔʏɽʂʈʋɥɦʝɫɭʎɱɲɸβɣχʁʔˈˌː';

    /** Gender/number tags that trail dictionary translations ("Katze f"). */
    private const GENDER_TAGS = ['f', 'm', 'c', 'pl', 'sg'];

    /** Characters trimmed off the ends of a translation candidate. */
    private const CANDIDATE_TRIM = " \t\n\r\0\x0B\"'«»„“”";

    private int $languageId;

    private ?int $targetLanguageId = null;

    private int $batchSize;

    private bool $dryRun;

    /**
     * Active extraction profile: ['slug' => string] plus an XPath selector
     * list per key (transcriptions/translations/definitions), pre-validated
     * by the command. Null = the generic heuristics alone.
     */
    private ?array $profile;

    /** @var list<string> capped "word — snippet" samples of no-match articles */
    private array $profileMissSamples = [];

    /** @var array<string, int> */
    private array $wordClassMap = [];

    /** @var array<string, int> */
    private array $targetWordClassMap = [];

    /** @var array<string, true> target-word triples already tallied (dry run) */
    private array $dryTargetWordKeys = [];

    /**
     * @var array<string, array{word: string, word_class_id: int}> target-word
     *                                                             triples whose would-be-created count is still pending the batched
     *                                                             DB existence check (dry run)
     */
    private array $dryPendingTargetWords = [];

    /** @var array<string, true> (source triple, candidate) pairs already tallied (dry run) */
    private array $dryLinkKeys = [];

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
        'translation_candidates' => 0,
        'target_words_created' => 0,
        'translations_linked' => 0,
        'profile_no_match_articles' => 0,
    ];

    public function __construct(string $lang, int $batchSize = 500, bool $dryRun = false, ?string $targetLang = null, ?array $profile = null)
    {
        $language = Language::query()->where('code', $lang)->first();
        if ($language === null) {
            throw new \InvalidArgumentException("Unsupported language: {$lang}");
        }

        $this->languageId = $language->id;

        if ($targetLang !== null) {
            $target = Language::query()->where('code', $targetLang)->first();
            if ($target === null) {
                throw new \InvalidArgumentException("Unsupported target language: {$targetLang}");
            }
            if ($target->id === $this->languageId) {
                throw new \InvalidArgumentException("The target language must differ from the source language ({$targetLang}).");
            }

            $this->targetLanguageId = $target->id;
        }

        $this->batchSize = max(1, $batchSize);
        $this->dryRun = $dryRun;
        $this->profile = $profile;
    }

    /**
     * @return array{entries_read: int, entries_skipped: int, words_imported: int, definitions_imported: int, transcriptions_imported: int, forms_imported: int, lookups_created: int, batches_flushed: int, translation_candidates: int, target_words_created: int, translations_linked: int, profile_no_match_articles: int}
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

        // A dry run's pending would-be-created target words resolve against
        // the DB before the stats are reported.
        if ($this->dryRun) {
            $this->tallyDryTargetWordCreations();
        }

        // Profile authors read this one: which articles nothing matched in.
        $this->logProfileMisses();

        if ($progressBar) {
            $progressBar->finish();
            $output->writeln('');
        }

        if (! $this->dryRun) {
            // The dictionary just grew: previously unmatchable entity tokens
            // of this language get another linking chance. In bilingual mode
            // the target language's dictionary grew too.
            EntityWordLinker::clearUnmatchedForLanguage($this->languageId);
            if ($this->targetLanguageId !== null) {
                EntityWordLinker::clearUnmatchedForLanguage($this->targetLanguageId);
            }
        }

        $this->heartbeat($startedAt, null, final: true);

        return $this->stats;
    }

    /**
     * @return array{word: string, l_word: string, pos: string, definitions: list<string>, transcriptions: list<string>, forms: list<string>, translations: list<string>}
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

        $segments = $article['segments'];
        $ipa = $article['ipa'];
        $translationSegments = $article['segments'];

        if ($article['profileTexts'] !== null) {
            $override = $this->applyProfile($article['profileTexts'], $word, trim($article['text']));
            $segments = $override['definitions'] ?? $segments;
            $ipa = $override['transcriptions'] ?? $ipa;
            $translationSegments = $override['translations'] ?? $translationSegments;
        }

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
                $segments,
            ))),
            'transcriptions' => $ipa,
            'forms' => array_values(array_unique($forms)),
            'translations' => $this->targetLanguageId !== null
                ? $this->extractTranslationCandidates($word, $translationSegments)
                : [],
        ];
    }

    /**
     * Translation candidates out of a bilingual article: each block-level
     * segment minus its transcription runs, grammar tokens and leading
     * headword, split on commas/semicolons into pieces that survive the
     * usable-candidates rule (the FetchWordTranslations mirror: no sentence
     * punctuation, at most 256 chars). A generic heuristic — per-dictionary
     * extraction profiles tune it later.
     *
     * @param  list<string>  $segments
     * @return list<string>
     */
    private function extractTranslationCandidates(string $headword, array $segments): array
    {
        $candidates = [];
        foreach ($segments as $segment) {
            $text = $this->stripDelimitedRuns($segment);
            $text = $this->stripGrammarTokens($text);
            $text = $this->stripLeadingHeadword($text, $headword);

            foreach (preg_split('/[;,]/u', $text) ?: [] as $piece) {
                $piece = trim($piece, self::CANDIDATE_TRIM);
                if ($piece === '') {
                    continue;
                }

                // A piece equal to the headword (case/normalization
                // insensitive) is the article naming itself, not a
                // translation — "Katze, cat" must not link cat to itself.
                if (WordLookupKey::normalize($piece) === WordLookupKey::normalize($headword)) {
                    continue;
                }

                if (mb_strlen($piece) > 256 || preg_match('/[.,;:]/', $piece) === 1) {
                    continue;
                }

                // Bare transcription or grammar runs, pure numbers or punctuation.
                if ($this->looksLikeIpa($piece) || $this->grammarTokenIsNoise($piece)) {
                    continue;
                }

                if (preg_match('/^[\p{N}\p{P}\s]+$/u', $piece) === 1) {
                    continue;
                }

                $candidates[] = $piece;
            }
        }

        return array_values(array_unique($candidates));
    }

    /**
     * Bracketed/slash-delimited runs carry transcriptions and grammar tags
     * ([kæt], [pl], /həˈləʊ/), never translations.
     */
    private function stripDelimitedRuns(string $text): string
    {
        $text = (string) preg_replace('~\[([^\[\]\n]{1,100})\]~u', ' ', $text);
        $text = (string) preg_replace('~(?<![\p{L}\p{N}/])/([^/\n]{1,100}?)/(?![\p{L}\p{N}/])~u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Standalone POS abbreviations and gender/number tags are grammar noise:
     * "cat n. Katze, Kater m" must yield "Katze" and "Kater".
     */
    private function stripGrammarTokens(string $text): string
    {
        $tokens = preg_split('/\s+/u', trim($text)) ?: [];

        return implode(' ', array_filter(
            $tokens,
            fn (string $token): bool => ! $this->grammarTokenIsNoise($token),
        ));
    }

    private function grammarTokenIsNoise(string $token): bool
    {
        if ($this->posSlugFor($token) !== null) {
            return true;
        }

        return in_array(trim($token, '.,;:()[]{}'), self::GENDER_TAGS, true);
    }

    /**
     * Dictionary articles open with their own headword; it is not a
     * translation of itself.
     */
    private function stripLeadingHeadword(string $text, string $headword): string
    {
        return trim((string) preg_replace(
            '/^\s*'.preg_quote($headword, '/').'\s+/ui',
            '',
            $text,
        ));
    }

    /**
     * @return array{segments: list<string>, ipa: list<string>, posHints: list<string>, profileTexts: array<string, list<string>>|null, text: string}
     */
    private function parseHtml(string $html): array
    {
        $empty = ['segments' => [], 'ipa' => [], 'posHints' => [], 'profileTexts' => null, 'text' => ''];
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

        $result = [
            'segments' => $this->extractSegments($xpath),
            'ipa' => $this->extractIpa($dom->textContent),
            'posHints' => $this->extractPosHints($xpath),
            'profileTexts' => null,
            'text' => '',
        ];

        if ($this->profile !== null) {
            // An article whose HTML is empty is a converter degenerate case,
            // not a selector signal — it never counts as a profile miss.
            $result['text'] = $dom->textContent;
            $result['profileTexts'] = [
                'transcriptions' => $this->profileMatches($xpath, 'transcriptions'),
                'translations' => $this->profileMatches($xpath, 'translations'),
                'definitions' => $this->profileMatches($xpath, 'definitions'),
            ];
        }

        return $result;
    }

    /**
     * Texts of the nodes a profile selector kind matches in one article —
     * empty when nothing matched (the per-selector fallback signal).
     *
     * @return list<string>
     */
    private function profileMatches(\DOMXPath $xpath, string $kind): array
    {
        $texts = [];
        foreach ($this->profile[$kind] ?? [] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes === false) {
                continue; // the command syntax-checks selectors; belt and braces
            }
            foreach ($nodes as $node) {
                $texts[] = (string) $node->textContent;
            }
        }

        return $texts;
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

    /**
     * Layer the profile over the heuristic results for one article: each
     * selector kind overrides its counterpart only when it matched something
     * usable in THIS article — a selector whose matched nodes hold only
     * whitespace/bracket text is a miss for that kind, just like a selector
     * that matched no nodes, and falls back to the heuristic (an extraction
     * is never silently emptied). Articles where NO selector matched are
     * counted and sampled (capped) for the profile author.
     *
     * @param  array<string, list<string>>  $texts
     * @return array{definitions?: list<string>, transcriptions?: list<string>, translations?: list<string>}
     */
    private function applyProfile(array $texts, string $word, string $articleText): array
    {
        $override = [];
        $matched = false;

        if ($texts['transcriptions'] !== []) {
            $values = $this->profileTranscriptions($texts['transcriptions']);
            if ($values !== []) {
                $matched = true;
                $override['transcriptions'] = $values;
            }
        }

        if ($texts['definitions'] !== []) {
            $segments = $this->profileSegments($texts['definitions']);
            if ($segments !== []) {
                $matched = true;
                $override['definitions'] = $segments;
            }
        }

        if ($texts['translations'] !== []) {
            $cells = array_values(array_filter(
                $texts['translations'],
                fn (string $text): bool => trim($text) !== '',
            ));
            if ($cells !== []) {
                // The matched texts replace the heuristic segments as the
                // source of translation candidates; the noise filters still
                // apply.
                $matched = true;
                $override['translations'] = $cells;
            }
        }

        if (! $matched) {
            $this->stats['profile_no_match_articles']++;
            if (count($this->profileMissSamples) < self::PROFILE_MISS_SAMPLE_CAP) {
                $this->profileMissSamples[] = $word.' — '.mb_substr($articleText, 0, 80);
            }
        }

        return $override;
    }

    /**
     * Profile-selected transcription texts: enclosing [brackets]//slashes/
     * trimmed, deduped, capped — but NOT gated on the IPA-marker heuristic:
     * the profile author vouches for these nodes, so non-IPA transcription
     * systems work.
     *
     * @param  list<string>  $texts
     * @return list<string>
     */
    private function profileTranscriptions(array $texts): array
    {
        $values = [];
        foreach ($texts as $text) {
            $value = trim((string) preg_replace('~^[\[/]+|[\]/]+$~u', '', trim($text)));
            if ($value !== '') {
                $values[] = mb_substr($value, 0, self::TRANSCRIPTION_CAP);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * Profile-selected definition texts, whitespace-normalized — the
     * parseEntry pass dedupes and caps them like heuristic segments.
     *
     * @param  list<string>  $texts
     * @return list<string>
     */
    private function profileSegments(array $texts): array
    {
        $segments = [];
        foreach ($texts as $text) {
            $segment = trim((string) preg_replace('/\s+/u', ' ', $text));
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }

        return $segments;
    }

    /**
     * One capped log notice for the run — articles where no profile selector
     * matched, with "word — snippet" samples — so a profile author sees what
     * they are missing without trawling the JSONL.
     */
    private function logProfileMisses(): void
    {
        $count = $this->stats['profile_no_match_articles'];
        if ($count === 0) {
            return;
        }

        Log::notice(sprintf(
            "Goldendict profile '%s': no selector matched in %d article%s. Samples (max %d): %s",
            $this->profile['slug'] ?? '?',
            $count,
            $count === 1 ? '' : 's',
            self::PROFILE_MISS_SAMPLE_CAP,
            implode('; ', $this->profileMissSamples),
        ));
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

        foreach ($record['translations'] as $candidate) {
            $this->stats['translation_candidates']++;
            $slug = $this->candidateClassSlug($candidate, $record['pos']);
            $classId = $this->ensureTargetWordClass($slug);

            $wordKey = mb_strtolower($candidate).'|'.$slug;
            if (! isset($this->dryTargetWordKeys[$wordKey])) {
                $this->dryTargetWordKeys[$wordKey] = true;
                // Counted when the batched DB existence check resolves: a
                // word the target language already has is an upsert, not a
                // would-be creation.
                $this->dryPendingTargetWords[$wordKey] = [
                    'word' => $candidate,
                    'word_class_id' => $classId,
                ];
                if (count($this->dryPendingTargetWords) >= $this->batchSize) {
                    $this->tallyDryTargetWordCreations();
                }
            }

            $linkKey = mb_strtolower($record['word']).'|'.$record['pos'].'=>'.$wordKey;
            if (! isset($this->dryLinkKeys[$linkKey])) {
                $this->dryLinkKeys[$linkKey] = true;
                $this->stats['translations_linked']++;
            }
        }
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

        // Preloaded like the source-side maps, so the dry run hands out real
        // ids for existing target classes (the existence checks key on them)
        // and lookups_created only counts classes that are genuinely missing.
        if ($this->targetLanguageId !== null) {
            $this->targetWordClassMap = WordClass::query()
                ->where('language_id', $this->targetLanguageId)
                ->pluck('id', 'slug')
                ->toArray();
        }
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

    /**
     * The target language's word class for a slug, auto-created after the
     * import's unseen-lookup convention (the bulk counterpart of
     * FetchWordTranslations' classContext + phraseClassId: a brand-new
     * target language must not lose every candidate to a missing class).
     */
    private function ensureTargetWordClass(string $slug): int
    {
        if (! isset($this->targetWordClassMap[$slug])) {
            if ($this->dryRun) {
                $this->targetWordClassMap[$slug] = 0;
                $this->stats['lookups_created']++;

                return 0;
            }

            $class = WordClass::query()->firstOrCreate(
                ['language_id' => $this->targetLanguageId, 'slug' => $slug],
                ['title' => $slug],
            );
            $this->targetWordClassMap[$slug] = $class->id;
            $this->stats['lookups_created']++;
        }

        return $this->targetWordClassMap[$slug];
    }

    /**
     * Class slug a translation candidate maps to: multi-word candidates are
     * phrases regardless of the source word's class (ADR 0059); the rest
     * carry the source word's class into the target language.
     */
    private function candidateClassSlug(string $candidate, string $sourcePos): string
    {
        return str_contains($candidate, ' ') ? 'phrase' : $sourcePos;
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
                    // Staged raw translation strings travel with the INSERT
                    // only: the update list below keeps an existing row's
                    // curated/merged JSON intact, and the staging merge pass
                    // unions re-imported candidates in.
                    'translations' => $record['translations'] !== []
                        ? json_encode($record['translations'])
                        : null,
                ];
            }

            $this->uniqueByCompound($wordUpserts, ['word', 'language_id', 'word_class_id']);
            // On conflict refresh the lookup key only: a re-import must not
            // push the unranked sentinel onto words a frequency list ranked,
            // nor clobber translations staged for the bilingual mode.
            Word::upsert($wordUpserts, ['word', 'language_id', 'word_class_id'], ['l_word']);

            $this->stats['words_imported'] += count($wordUpserts);
            $this->stats['batches_flushed']++;

            // The dry-run tally counts these per record (tallyRecord); the
            // real run counts them where they are staged.
            foreach ($batch as $record) {
                $this->stats['translation_candidates'] += count($record['translations']);
            }

            $wordIds = Word::query()
                ->where('language_id', $this->languageId)
                ->whereIn('word', collect($batch)->pluck('word')->unique()->toArray())
                ->get(['id', 'word', 'word_class_id'])
                ->keyBy(fn ($w) => mb_strtolower($w->word).'|'.$w->word_class_id);

            $this->flushDefinitions($batch, $wordIds);
            $this->flushTranscriptions($batch, $wordIds);
            $this->flushForms($batch, $wordIds);

            if ($this->targetLanguageId !== null) {
                $this->flushTranslationStaging($batch, $wordIds);
                $targetIds = $this->flushTargetWords($batch);
                $this->flushTranslationLinks($batch, $wordIds, $targetIds);
            }
        });
    }

    /**
     * Union the staged raw translation strings into words.translations JSON
     * (the flat, deduplicated Wiktionary shape). Rows the upsert just
     * inserted already carry their candidates; pre-existing rows keep their
     * JSON through the upsert, so it is merged here — never clobbered.
     */
    private function flushTranslationStaging(array $batch, object $wordIds): void
    {
        $byWordId = [];
        foreach ($batch as $record) {
            if ($record['translations'] === []) {
                continue;
            }
            $wordId = $this->lookupWordId($record, $wordIds);
            if ($wordId !== null) {
                $byWordId[$wordId] = array_values(array_unique(array_merge(
                    $byWordId[$wordId] ?? [],
                    $record['translations'],
                )));
            }
        }

        if ($byWordId === []) {
            return;
        }

        foreach (Word::query()->whereKey(array_keys($byWordId))->get(['id', 'translations']) as $word) {
            $existing = $word->translations ?? [];
            $merged = array_values(array_unique(array_merge($existing, $byWordId[$word->id])));
            if ($merged !== $existing) {
                $word->forceFill(['translations' => $merged])->save();
            }
        }
    }

    /**
     * Create the missing target-language words — the bulk mirror of the
     * FetchWordTranslations firstOrCreate-on-triple semantics — and return
     * their ids keyed by "lower(word)|classId" for the link phase.
     *
     * @return object keyed id map (Eloquent collection)
     */
    private function flushTargetWords(array $batch): object
    {
        $rows = [];
        foreach ($batch as $record) {
            foreach ($record['translations'] as $candidate) {
                $rows[] = [
                    'word' => $candidate,
                    'l_word' => WordLookupKey::normalize($candidate),
                    'language_id' => $this->targetLanguageId,
                    'word_class_id' => $this->ensureTargetWordClass(
                        $this->candidateClassSlug($candidate, $record['pos']),
                    ),
                    'frequency' => Word::FREQUENCY_UNRANKED,
                ];
            }
        }

        if ($rows === []) {
            return collect();
        }

        $this->uniqueByCompound($rows, ['word', 'language_id', 'word_class_id']);

        // Count only genuinely created rows (the flushTranslationLinks rule):
        // diff the batch's triples against the target language's existing
        // words BEFORE the upsert.
        $candidates = [];
        foreach ($rows as $row) {
            $candidates[mb_strtolower($row['word']).'|'.$row['word_class_id']] = [
                'word' => $row['word'],
                'word_class_id' => $row['word_class_id'],
            ];
        }
        $existing = $this->existingTargetWords($candidates);

        Word::upsert($rows, ['word', 'language_id', 'word_class_id'], ['l_word']);
        $this->stats['target_words_created'] += count(array_filter(
            $rows,
            fn (array $row): bool => ! isset($existing[mb_strtolower($row['word']).'|'.$row['word_class_id']]),
        ));

        return Word::query()
            ->where('language_id', $this->targetLanguageId)
            ->whereIn('word', collect($rows)->pluck('word')->unique()->toArray())
            ->get(['id', 'word', 'word_class_id'])
            ->keyBy(fn ($w) => mb_strtolower($w->word).'|'.$w->word_class_id);
    }

    /**
     * Which of the given target-word candidates already exist in the target
     * language — the existing-rows knowledge behind the new-only
     * target_words_created counting, shared by the real flush
     * (flushTargetWords) and the dry-run tally. Returns the
     * "lower(word)|classId" keys found in the DB among the candidates (a
     * dry-run class-id 0 placeholder means the class row itself is missing,
     * so no word can exist under it and it is never reported existing).
     *
     * @param  array<string, array{word: string, word_class_id: int}>  $candidates
     * @return array<string, true>
     */
    private function existingTargetWords(array $candidates): array
    {
        $wanted = [];
        foreach ($candidates as $candidate) {
            if ($candidate['word_class_id'] !== 0) {
                $wanted[$candidate['word']][] = $candidate['word_class_id'];
            }
        }

        if ($wanted === []) {
            return [];
        }

        $existing = [];
        foreach (Word::query()
            ->where('language_id', $this->targetLanguageId)
            ->whereIn('word', array_keys($wanted))
            ->get(['word', 'word_class_id']) as $row) {
            if (in_array($row->word_class_id, $wanted[$row->word], true)) {
                $existing[mb_strtolower($row->word).'|'.$row->word_class_id] = true;
            }
        }

        return $existing;
    }

    /**
     * Resolve the dry run's pending would-be-created target words through the
     * same existingTargetWords knowledge the real flush counts through: keys
     * the target language already has are would-be upserts, not creations.
     */
    private function tallyDryTargetWordCreations(): void
    {
        if ($this->dryPendingTargetWords === []) {
            return;
        }

        $existing = $this->existingTargetWords($this->dryPendingTargetWords);
        foreach ($this->dryPendingTargetWords as $pending) {
            if (! isset($existing[mb_strtolower($pending['word']).'|'.$pending['word_class_id']])) {
                $this->stats['target_words_created']++;
            }
        }

        $this->dryPendingTargetWords = [];
    }

    /**
     * Canonical word_translations links (word_a_id < word_b_id, ADR 0020)
     * via one batched upsert per flush — no per-row link() calls in the hot
     * loop (the LinkTranslationsCommand convention).
     */
    private function flushTranslationLinks(array $batch, object $wordIds, object $targetIds): void
    {
        $rows = [];
        $seen = [];
        foreach ($batch as $record) {
            if ($record['translations'] === []) {
                continue;
            }
            $sourceId = $this->lookupWordId($record, $wordIds);
            if ($sourceId === null) {
                continue;
            }

            foreach ($record['translations'] as $candidate) {
                $slug = $this->candidateClassSlug($candidate, $record['pos']);
                $targetId = $targetIds[mb_strtolower($candidate).'|'.$this->targetWordClassMap[$slug]]->id ?? null;
                if ($targetId === null) {
                    continue;
                }

                [$wordAId, $wordBId] = WordTranslation::canonicalize((int) $sourceId, (int) $targetId);
                $key = $wordAId.'|'.$wordBId;
                if (! isset($seen[$key])) {
                    $seen[$key] = true;
                    $rows[] = ['word_a_id' => $wordAId, 'word_b_id' => $wordBId];
                }
            }
        }

        if ($rows === []) {
            return;
        }

        // Count only genuinely new pairs, so an idempotent re-run reports
        // zero links staged.
        $existingPairs = [];
        foreach (WordTranslation::query()
            ->whereIn('word_a_id', array_values(array_unique(array_column($rows, 'word_a_id'))))
            ->get(['word_a_id', 'word_b_id']) as $link) {
            $existingPairs[$link->word_a_id.'|'.$link->word_b_id] = true;
        }

        $newRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => ! isset($existingPairs[$row['word_a_id'].'|'.$row['word_b_id']]),
        ));

        if ($newRows !== []) {
            WordTranslation::upsert($newRows, ['word_a_id', 'word_b_id']);
            $this->stats['translations_linked'] += count($newRows);
        }
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
            '%s entries=%d skipped=%d words=%d targets=%d links=%d rss=%sMB elapsed=%ss',
            $final ? 'GoldenDict import done:' : 'GoldenDict import heartbeat:',
            $this->stats['entries_read'],
            $this->stats['entries_skipped'],
            $this->stats['words_imported'],
            $this->stats['target_words_created'],
            $this->stats['translations_linked'],
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
