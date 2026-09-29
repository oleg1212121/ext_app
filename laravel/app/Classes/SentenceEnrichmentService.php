<?php

namespace App\Classes;

use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\Form;
use App\Models\Word;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sentence enrichment: stress marks (ru/en), phrasal verbs (en) and heuristic
 * intonation, computed entirely locally by the python service (Silero Stress +
 * dictionary data passed through from Laravel — ADR 0052).
 *
 * Results are stored BESIDE content; the invariant that makes that mandatory:
 * mutating entity_sentences.content would bump sentences_updated_at (stale
 * word index, stale text hash) and flip entity matches back to pending.
 */
class SentenceEnrichmentService
{
    private const RETRY_DELAYS_MS = [500, 1_500, 3_000];

    /** Sentences per python /enrich call (matches ALIGN chunk sizing). */
    public const CHUNK_SIZE = 75;

    /** Languages the python service can enrich today. */
    public const ENRICHABLE_LANGUAGES = ['ru', 'en'];

    /** Phrasal-verb lexicon rows passed per request (python schema cap). */
    private const PHRASAL_LEXICON_LIMIT = 50_000;

    /** IPA transcription variants passed per token. */
    private const IPA_VARIANTS_LIMIT = 3;

    public function __construct(
        private readonly string $apiUrl,
        private readonly int $timeout,
        private readonly WordTokenizer $tokenizer,
    ) {}

    public static function create(): self
    {
        return new self(
            apiUrl: config('services.python.url', 'http://ext_python:8000'),
            timeout: (int) config('services.python.timeout', 30),
            tokenizer: app(WordTokenizer::class),
        );
    }

    /**
     * Enrichment is stale when it never ran or any sentence changed after it
     * (words_indexed_at pattern). Enrichment writes are quiet — they never
     * bump sentences_updated_at — so only real content edits flip this.
     */
    public function isStale(Entity $entity): bool
    {
        if ($entity->enriched_at === null) {
            return true;
        }

        $lastSentenceChange = EntitySentence::query()
            ->where('entity_id', $entity->id)
            ->max('updated_at');

        return $lastSentenceChange !== null && $lastSentenceChange > $entity->enriched_at;
    }

    public function markEnriched(Entity $entity): void
    {
        // Base-builder update: no model events, no updated_at.
        Entity::query()
            ->whereKey($entity->id)
            ->toBase()
            ->update(['enriched_at' => now()]);
    }

    /**
     * Enrich one chunk of sentences via the python service and persist the
     * results. Writes are deliberately quiet (no model events, no timestamps)
     * so enrichment itself never marks the entity stale again.
     *
     * @param  Collection<int, EntitySentence>  $sentences
     * @return int Number of sentences written.
     */
    public function enrichChunk(Entity $entity, Collection $sentences): int
    {
        $language = $entity->language?->code ?? '';
        $tokenized = [];

        foreach ($sentences as $sentence) {
            $tokenized[$sentence->id] = $this->tokenizer->tokenizeWithSpans($sentence->content);
        }

        $keys = collect($tokenized)->flatten(1)->pluck('surface')->map(fn (string $s) => $this->tokenizer->lookupKey($s))->unique()->values()->all();
        $hints = $this->dictionaryHints($entity, $language, $keys);

        $payload = [
            'language' => $language,
            'sentences' => [],
        ];

        if ($language === 'en') {
            $payload['phrasal_lexicon'] = $this->phrasalLexicon($entity);
        }

        $empty = [];

        foreach ($sentences as $sentence) {
            if (trim($sentence->content) === '') {
                // The python schema rejects empty text; empty sentences keep
                // null enrichment columns instead of failing the chunk.
                $empty[] = $sentence->id;

                continue;
            }

            $payload['sentences'][] = [
                'id' => $sentence->id,
                'text' => $sentence->content,
                'tokens' => array_map(
                    fn (array $t): array => $this->tokenPayload($t, $hints),
                    $tokenized[$sentence->id],
                ),
            ];
        }

        $results = $this->callEnrich($payload);

        foreach ($empty as $id) {
            $results[] = ['id' => $id, 'stressed' => null, 'phrasal_verbs' => null, 'intonation' => null];
        }

        $written = 0;

        DB::transaction(function () use ($results, &$written): void {
            foreach ($results as $result) {
                EntitySentence::query()
                    ->whereKey($result['id'])
                    ->toBase()
                    ->update([
                        'stressed_content' => $result['stressed'],
                        'phrasal_verbs' => $result['phrasal_verbs'] !== null
                            ? json_encode($result['phrasal_verbs'], JSON_UNESCAPED_UNICODE)
                            : null,
                        'intonation' => $result['intonation'] !== null
                            ? json_encode($result['intonation'], JSON_UNESCAPED_UNICODE)
                            : null,
                    ]);
                $written++;
            }
        });

        return $written;
    }

    /**
     * @return list<array{id: int, stressed: ?string, phrasal_verbs: ?array, intonation: ?array}>
     */
    private function callEnrich(array $payload): array
    {
        $response = Http::timeout($this->timeout)
            ->retry(
                self::RETRY_DELAYS_MS,
                0,
                fn (Throwable $exception, PendingRequest $request): bool => $exception instanceof ConnectionException,
                false,
            )
            ->post("{$this->apiUrl}/enrich", $payload);

        if (! $response->successful()) {
            throw new \RuntimeException("Python enrichment service error: {$response->status()} - {$response->body()}");
        }

        $results = [];

        foreach ($response->json('results', []) as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $results[] = [
                'id' => (int) ($raw['id'] ?? 0),
                'stressed' => isset($raw['stressed']) ? (string) $raw['stressed'] : null,
                'phrasal_verbs' => is_array($raw['phrasal_verbs'] ?? null) ? $raw['phrasal_verbs'] : null,
                'intonation' => is_array($raw['intonation'] ?? null) ? $raw['intonation'] : null,
            ];
        }

        return $results;
    }

    /**
     * Attach dictionary hints to a token: word class, lemma, IPA variants (en)
     * and stressed-form candidates (ru).
     *
     * @param  array{surface: string, start: int, end: int}  $token
     */
    private function tokenPayload(array $token, array $hints): array
    {
        $key = $this->tokenizer->lookupKey($token['surface']);
        $hint = $hints['by_key'][$key] ?? null;

        return [
            'surface' => $token['surface'],
            'start' => $token['start'],
            'end' => $token['end'],
            'cls' => $hint['cls'] ?? null,
            'lemma' => $hint['lemma'] ?? null,
            'ipa' => $hint['ipa'] ?? null,
            'stressed' => $hint['stressed'] ?? null,
        ];
    }

    /**
     * Dictionary data for a chunk's token keys, resolved per token:
     * the entity's own word link (already class-prioritised) wins, then a
     * direct dictionary match ordered by class priority.
     *
     * @param  list<string>  $keys
     * @return array{by_key: array<string, array{cls: ?string, lemma: ?string, ipa: ?list<string>, stressed: ?list<string>}>}
     */
    private function dictionaryHints(Entity $entity, string $language, array $keys): array
    {
        if ($keys === []) {
            return ['by_key' => []];
        }

        $linkedWordIds = EntityWord::query()
            ->where('entity_id', $entity->id)
            ->whereIn('l_word', $keys)
            ->whereNotNull('word_id')
            ->pluck('word_id', 'l_word');

        $words = Word::query()
            ->where('language_id', $entity->language_id)
            ->whereIn('l_word', $keys)
            ->with('wordClass:id,slug')
            ->get();

        $bestByWord = [];

        foreach ($words->groupBy('l_word') as $lWord => $group) {
            $bestByWord[(string) $lWord] = $group
                ->sortBy(fn (Word $w) => [EntityWordLinker::classPriority($w->wordClass?->slug ?? ''), $w->id])
                ->first();
        }

        $wordsById = $words->keyBy('id');

        // A linked word's headword can differ from the token form ("gave" ->
        // "give" via the forms table), so the link target may sit outside the
        // l_word window; pull those in explicitly.
        $missingIds = $linkedWordIds
            ->filter(fn (?int $id): bool => $id !== null && ! $wordsById->has($id))
            ->unique()
            ->values();

        if ($missingIds->isNotEmpty()) {
            Word::query()
                ->with('wordClass:id,slug')
                ->whereIn('id', $missingIds)
                ->get()
                ->each(fn (Word $word) => $wordsById->put($word->id, $word));
        }

        $ipaByWordId = $language === 'en' ? $this->ipaByWordId($wordsById->keys()) : [];

        $stressedByKey = [];

        if ($language === 'ru') {
            // Inflected forms keep U+0301; headwords too (Wiktionary style).
            $forms = Form::query()
                ->whereIn('l_word', $keys)
                ->select('l_word', 'form')
                ->get();

            foreach ($forms as $form) {
                $stressedByKey[$form->l_word][] = $form->form;
            }

            foreach ($bestByWord as $lWord => $word) {
                if ($word->word !== null && ($this->hasStressMarks($word->word))) {
                    $stressedByKey[$lWord][] = $word->word;
                }
            }
        }

        $byKey = [];

        foreach ($keys as $key) {
            $wordId = $linkedWordIds[$key] ?? null;
            $word = $wordId !== null ? ($wordsById[$wordId] ?? null) : ($bestByWord[$key] ?? null);

            $byKey[$key] = [
                'cls' => $word?->wordClass?->slug,
                'lemma' => $word?->word,
                'ipa' => $word !== null ? ($ipaByWordId[$word->id] ?? null) : null,
                'stressed' => $stressedByKey[$key] ?? null,
            ];
        }

        return ['by_key' => $byKey];
    }

    /**
     * @return array<int, list<string>>
     */
    private function ipaByWordId(Collection $wordIds): array
    {
        if ($wordIds->isEmpty()) {
            return [];
        }

        // Stress-bearing variants first (Postgres sorts false before true) so
        // the per-word cap below can't cut off the only variant with a primary
        // stress mark; id order keeps the selection deterministic.
        $rows = DB::table('transcriptions')
            ->join('transcription_types', 'transcription_types.id', '=', 'transcriptions.transcription_type_id')
            ->whereIn('transcriptions.word_id', $wordIds)
            ->where('transcription_types.slug', 'ipa')
            ->orderByRaw('(transcriptions.transcription NOT LIKE ?)', ['%ˈ%'])
            ->orderBy('transcriptions.id')
            ->select('transcriptions.word_id', 'transcriptions.transcription')
            ->get();

        $byWordId = [];

        foreach ($rows as $row) {
            if (count($byWordId[$row->word_id] ?? []) >= self::IPA_VARIANTS_LIMIT) {
                continue;
            }

            $byWordId[$row->word_id][] = $row->transcription;
        }

        return $byWordId;
    }

    /**
     * Multi-word verb headwords for phrasal-verb matching ("give up", "kick
     * the bucket") — inert dictionary rows the single-token linker can never
     * reach (ADR 0052).
     *
     * @return list<string>
     */
    private function phrasalLexicon(Entity $entity): array
    {
        return Word::query()
            ->where('language_id', $entity->language_id)
            ->where('l_word', 'like', '% %')
            ->whereHas('wordClass', fn ($q) => $q->where('slug', 'verb'))
            ->orderBy('id')
            ->limit(self::PHRASAL_LEXICON_LIMIT)
            ->pluck('l_word')
            ->values()
            ->all();
    }

    private function hasStressMarks(string $word): bool
    {
        return preg_match('/\p{M}/u', $word) === 1 || mb_strpos($word, 'ё') !== false || mb_strpos($word, 'Ё') !== false;
    }
}
