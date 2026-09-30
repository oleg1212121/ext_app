<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordClass;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportCmudictCommand extends Command
{
    protected $signature = 'dictionary:import-cmudict
        {file : Path to cmudict.dict (github.com/cmusphinx/cmudict, BSD licence)}
        {--lang=en : Language code the CMUdict entries belong to}
        {--batch-size=1000 : Distinct words resolved per DB flush}';

    protected $description = 'Import the CMU Pronouncing Dictionary as IPA transcriptions (ARPAbet converted, ADR 0053). Word rows already carrying a stress-marked IPA transcription are skipped, so a prior Kaikki import wins where it has stress data; rows with only unstressed IPA (turned /tɜːnd/) gain the CMUdict variants.';

    /** CMUdict vowels -> IPA (stress digits handled separately). */
    private const VOWELS = [
        'AA' => 'ɑ', 'AE' => 'æ', 'AH' => 'ʌ', 'AO' => 'ɔː', 'AW' => 'aʊ',
        'AY' => 'aɪ', 'EH' => 'ɛ', 'ER' => 'ɜː', 'EY' => 'eɪ', 'IH' => 'ɪ',
        'IY' => 'iː', 'OW' => 'oʊ', 'OY' => 'ɔɪ', 'UH' => 'ʊ', 'UW' => 'uː',
    ];

    private const CONSONANTS = [
        'B' => 'b', 'CH' => 'tʃ', 'D' => 'd', 'DH' => 'ð', 'F' => 'f', 'G' => 'ɡ',
        'HH' => 'h', 'JH' => 'dʒ', 'K' => 'k', 'L' => 'l', 'M' => 'm', 'N' => 'n',
        'NG' => 'ŋ', 'P' => 'p', 'R' => 'ɹ', 'S' => 's', 'SH' => 'ʃ', 'T' => 't',
        'TH' => 'θ', 'V' => 'v', 'W' => 'w', 'Y' => 'j', 'Z' => 'z', 'ZH' => 'ʒ',
    ];

    /**
     * Closed-class words are never prominently stressed in connected speech,
     * but CMUdict lists citation/strong forms for most of them ("of AH1 V",
     * "at AE1 T", "the(2) DH AH1"). Variants with a primary-stress digit are
     * dropped for these so they never carry a mark; unstressed variants
     * (/ðə/, /tə/) still import for completeness.
     */
    private const FUNCTION_WORDS = [
        'a', 'an', 'the', 'of', 'to', 'in', 'on', 'at', 'by', 'for', 'with',
        'from', 'as', 'into', 'onto', 'upon', 'about', 'over', 'under',
        'after', 'before', 'between', 'during', 'through', 'against',
        'without', 'within', 'and', 'or', 'but', 'if', 'nor', 'so', 'yet',
        'because', 'although', 'though', 'while', 'unless', 'since', 'until',
        'than', 'whether', 'is', 'are', 'was', 'were', 'be', 'been', 'being',
        'am', 'do', 'does', 'did', 'doing', 'have', 'has', 'had', 'having',
        'will', 'would', 'shall', 'should', 'can', 'could', 'may', 'might',
        'must', 'he', 'him', 'his', 'she', 'her', 'hers', 'it', 'its', 'they',
        'them', 'their', 'theirs', 'we', 'us', 'our', 'ours', 'you', 'your',
        'yours', 'i', 'me', 'my', 'mine', 'this', 'that', 'these', 'those',
        'there', 'here', 'not', 'no', 'nor', 'then', 'too', 'who', 'whom',
        'whose', 'which', 'what', 'when', 'where', 'why', 'how', 's', 't',
        'll', 're', 've', 'd', 'm',
    ];

    private int $langId;

    private int $ipaTypeId;

    private int $unknownClassId;

    public function handle(): int
    {
        $file = $this->argument('file');
        $batchSize = max(1, (int) $this->option('batch-size'));

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $language = Language::query()->where('code', $this->option('lang'))->first();
        if ($language === null) {
            $this->error('Unknown language: '.$this->option('lang'));

            return self::FAILURE;
        }

        $this->langId = $language->id;
        $this->ipaTypeId = TranscriptionType::query()->firstOrCreate(
            ['language_id' => $this->langId, 'slug' => 'ipa'],
            ['title' => 'IPA', 'description' => 'International Phonetic Alphabet'],
        )->id;

        $unknownClass = WordClass::query()
            ->where('language_id', $this->langId)
            ->where('slug', 'unknown')
            ->first();
        $this->unknownClassId = $unknownClass?->id
            ?? WordClass::query()->where('language_id', $this->langId)->orderBy('id')->value('id');
        if ($this->unknownClassId === null) {
            $this->error("No word classes for language '{$language->code}'. Seed them first.");

            return self::FAILURE;
        }

        $handle = fopen($file, 'r');
        if ($handle === false) {
            $this->error("Cannot open: {$file}");

            return self::FAILURE;
        }

        $this->info("Importing CMUdict from: {$file}");

        $stats = ['lines_read' => 0, 'entries' => 0, 'skipped_lines' => 0, 'words_skipped' => 0, 'words_created' => 0, 'transcriptions' => 0];
        $entries = [];

        while (($line = fgets($handle)) !== false) {
            $stats['lines_read']++;
            $line = trim($line);

            if ($line === '' || str_starts_with($line, ';;;')) {
                continue;
            }

            $parsed = $this->parseLine($line);
            if ($parsed === null) {
                $stats['skipped_lines']++;

                continue;
            }

            [$word, $ipa] = $parsed;
            if ($ipa === null) {
                continue;
            }

            if (! isset($entries[$word])) {
                $entries[$word] = [];
                $stats['entries']++;
            }
            if (! in_array($ipa, $entries[$word], true)) {
                $entries[$word][] = $ipa;
            }

            if (count($entries) >= $batchSize) {
                $this->flush($entries, $stats);
                $entries = [];
            }
        }
        fclose($handle);

        if ($entries !== []) {
            $this->flush($entries, $stats);
        }

        $this->newLine();
        $this->info('Import completed!');
        $this->table(['Metric', 'Count'], array_map(fn ($k, $v) => [str_replace('_', ' ', $k), $v], array_keys($stats), $stats));

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: ?string}|null [lowercase word, IPA] — IPA
     *                                           null means the line is valid but produces no usable transcription
     *                                           (e.g. a function word with only stressed variants).
     */
    private function parseLine(string $line): ?array
    {
        $parts = preg_split('/\s+/', $line);
        if (count($parts) < 2) {
            return null;
        }

        $headword = $parts[0];
        $headword = preg_replace('/\(\d+\)$/', '', $headword);
        $word = mb_strtolower($headword);

        if (! preg_match("/^[a-z'’][a-z'’-]*$/", $word)) {
            return null;
        }

        $phones = array_slice($parts, 1);
        $ipa = $this->toIpa($phones);
        if ($ipa === null) {
            return null;
        }

        // Function word with citation-form primary stress ("of AH1 V",
        // "the(2) DH AH1"): the whole variant is dropped so these words never
        // carry a mark. Unstressed variants (/ðə/, /tə/) still import.
        if (in_array($word, self::FUNCTION_WORDS, true)
            && collect($phones)->contains(fn (string $phone): bool => str_ends_with($phone, '1'))) {
            return [$word, null];
        }

        return [$word, $ipa];
    }

    /**
     * Convert CMUdict phones to Wiktionary-style IPA: ˈ before the first
     * primary-stress vowel, ˌ before the first secondary one, schwa for
     * unstressed AH, r-coloring for unstressed ER.
     */
    private function toIpa(array $phones): ?string
    {
        $out = '';
        $primaryDone = false;
        $secondaryDone = false;

        foreach ($phones as $phone) {
            if (! preg_match('/^([A-Z]+)([012])?$/', $phone, $m)) {
                return null;
            }

            $base = $m[1];
            $stress = $m[2] ?? null;

            if (isset(self::VOWELS[$base])) {
                if ($stress === null) {
                    return null; // cmudict vowels always carry a stress digit
                }

                $symbol = self::VOWELS[$base];
                if ($stress === '0' && $base === 'AH') {
                    $symbol = 'ə';
                } elseif ($stress === '0' && $base === 'ER') {
                    $symbol = 'ɚ';
                }

                if ($stress === '1' && ! $primaryDone) {
                    $out .= 'ˈ';
                    $primaryDone = true;
                } elseif ($stress === '2' && ! $secondaryDone) {
                    $out .= 'ˌ';
                    $secondaryDone = true;
                }

                $out .= $symbol;
            } elseif (isset(self::CONSONANTS[$base]) && $stress === null) {
                $out .= self::CONSONANTS[$base];
            } else {
                return null;
            }
        }

        return $out !== '' ? "/{$out}/" : null;
    }

    /**
     * Resolve one batch of word => IPA variants against the dictionary:
     * l_words with no word row get one (class "unknown"), rows already
     * carrying a ˈ-marked IPA transcription are left untouched (Kaikki wins
     * where it has stress data). Rows with only unstressed IPA still gain
     * the CMUdict variants — the hint query orders ˈ-bearing variants first.
     *
     * @param  array<string, list<string>>  $entries
     * @param  array<string, int>  $stats
     */
    private function flush(array $entries, array &$stats): void
    {
        $keys = array_keys($entries);

        $byLWord = Word::query()
            ->where('language_id', $this->langId)
            ->whereIn('l_word', $keys)
            ->orderBy('id')
            ->get(['id', 'l_word'])
            ->groupBy('l_word');

        $missing = array_diff($keys, $byLWord->keys()->all());

        if ($missing !== []) {
            $rows = array_map(fn (string $word) => [
                'word' => $word,
                'l_word' => $word,
                'language_id' => $this->langId,
                'word_class_id' => $this->unknownClassId,
            ], $missing);

            foreach (array_chunk($rows, 500) as $chunk) {
                Word::upsert($chunk, ['word', 'language_id', 'word_class_id']);
            }

            $stats['words_created'] += count($missing);

            $created = Word::query()
                ->where('language_id', $this->langId)
                ->whereIn('l_word', $missing)
                ->orderBy('id')
                ->get(['id', 'l_word'])
                ->groupBy('l_word');
            // Plain collect: Eloquent::merge would re-key by model getKey.
            $byLWord = collect(array_merge($byLWord->all(), $created->all()));
        }

        $candidateIds = $byLWord
            ->flatMap(fn ($group) => $group->pluck('id'))
            ->values();

        $ipaWordIds = DB::table('transcriptions')
            ->join('transcription_types', 'transcription_types.id', '=', 'transcriptions.transcription_type_id')
            ->whereIn('transcriptions.word_id', $candidateIds)
            ->where('transcription_types.slug', 'ipa')
            ->where('transcriptions.transcription', 'like', '%ˈ%')
            ->distinct()
            ->pluck('transcriptions.word_id')
            ->flip();

        $rows = [];

        foreach ($entries as $lWord => $variants) {
            $group = $byLWord[$lWord] ?? null;
            if ($group === null) {
                $stats['words_skipped']++;

                continue;
            }

            // A word can have several class rows (die noun/verb/adj); the
            // entity link may point at any of them, so every row without a
            // ˈ-marked transcription gets the variants.
            $targets = $group->pluck('id')->reject(fn (int $id) => $ipaWordIds->has($id));
            if ($targets->isEmpty()) {
                $stats['words_skipped']++;

                continue;
            }

            foreach ($targets as $wordId) {
                foreach ($variants as $ipa) {
                    $rows[] = [
                        'transcription' => mb_substr($ipa, 0, 100),
                        'word_id' => $wordId,
                        'transcription_type_id' => $this->ipaTypeId,
                    ];
                }
            }
        }

        // Triple-unique: identical (transcription, word, type) rows dedupe
        // so a re-run is a no-op.
        $unique = [];
        foreach ($rows as $row) {
            $key = $row['transcription'].'|'.$row['word_id'];
            $unique[$key] = $row;
        }

        if ($unique !== []) {
            Transcription::upsert(array_values($unique), ['transcription', 'word_id', 'transcription_type_id']);
            $stats['transcriptions'] += count($unique);
        }
    }
}
