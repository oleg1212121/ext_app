<?php

namespace App\Classes;

use App\Classes\Enrichment\Enricher;
use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\Enrichment\Stamp;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\Word;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sentence enrichment: the per-language analyses (stress marks ru/en, phrasal
 * verbs en — ADR 0052/0053) orchestrated as registered enrichers (ADR 0057).
 * All computation is local to the python service (Silero Stress + dictionary
 * data passed through from Laravel); the registry decides which enrichers an
 * entity's language gets.
 *
 * Results are stored BESIDE content; the invariant that makes that mandatory:
 * mutating entity_sentences.content would bump sentences_updated_at (stale
 * word index, stale text hash) and flip entity matches back to pending.
 */
class SentenceEnrichmentService
{
    /** Sentences per python /enrich call (matches ALIGN chunk sizing). */
    public const CHUNK_SIZE = 75;

    public function __construct(
        private readonly PythonClient $python,
        private readonly WordTokenizer $tokenizer,
        private readonly EnricherRegistry $registry,
    ) {}

    /**
     * The entity's language enrichers that currently need to run for at
     * least one of its sentences — the union of its sentences' stale
     * enricher keys (never enriched, a newly registered enricher, an older
     * algorithm version, or sentence changes since). Empty for a fully
     * enriched entity and for languages with no enrichers.
     *
     * @return list<Enricher>
     */
    public function staleEnrichers(Entity $entity): array
    {
        return $this->registry->forKeys($this->registry->staleKeysFor($entity));
    }

    public function isStale(Entity $entity): bool
    {
        return $this->staleEnrichers($entity) !== [];
    }

    /**
     * Stamp the given enrichers done on entities.enrichment_stamps (quiet
     * base-builder write, jsonb merge so concurrent stampers can't clobber
     * each other's keys). Each stamp carries the Laravel algorithm version
     * and the python-reported algorithm version (ADR 0059, ADR 0067); a
     * version the chunk run did not report falls back to the enricher's
     * declared python version. Null stamps every enricher of the entity's
     * language — the whole-run-done stamp; a language with no enrichers ends
     * as an empty map.
     *
     * This entity-level stamp is kept written through the per-sentence
     * staleness change but is no longer read for staleness — the sentence's
     * own stamps decide; its removal is the cleanup ticket's.
     *
     * @param  list<Enricher>|null  $enrichers
     * @param  array<string, int>  $reportedVersions  enricher key -> python-reported version
     */
    public function markEnriched(Entity $entity, ?array $enrichers = null, array $reportedVersions = []): void
    {
        $enrichers ??= $this->registry->forLanguage($entity->language?->code ?? '');

        $stamps = [];

        foreach ($enrichers as $enricher) {
            $stamps[$enricher->key()] = Stamp::build($enricher, $reportedVersions[$enricher->key()] ?? null);
        }

        $json = Stamp::encode($stamps);

        Entity::query()
            ->whereKey($entity->id)
            ->toBase()
            ->update([
                // Base-builder update: no model events, no updated_at.
                'enrichment_stamps' => DB::raw(
                    "coalesce(enrichment_stamps, '{}'::jsonb) || '{$json}'::jsonb",
                ),
            ]);
    }

    /**
     * Stamp the given sentences done for exactly the enrichers processed
     * for each of them, on entity_sentences.enrichment_stamps — the same
     * {key: {v, pv, at}} contract as the entity-level stamps (Stamp). Quiet
     * base-builder writes (no model events, no updated_at — the staleness
     * compare against the sentence's own content change must stay valid)
     * merged via jsonb || so concurrent stampers cannot clobber each other's
     * keys. Sentences whose target set is empty are left untouched.
     *
     * @param  Collection<int, EntitySentence>  $sentences
     * @param  array<int, list<Enricher>>  $perSentenceEnrichers  sentence id -> processed enrichers
     * @param  array<string, int>  $reportedVersions  enricher key -> python-reported version
     */
    public function markSentencesEnriched(Collection $sentences, array $perSentenceEnrichers, array $reportedVersions = []): void
    {
        $at = now()->toISOString();

        // Sentences sharing a target set share their stamp json — one quiet
        // write per distinct map instead of one per sentence.
        $byJson = [];

        foreach ($sentences as $sentence) {
            $enrichers = $perSentenceEnrichers[$sentence->id] ?? [];

            $stamps = [];

            foreach ($enrichers as $enricher) {
                $stamps[$enricher->key()] = Stamp::build($enricher, $reportedVersions[$enricher->key()] ?? null, $at);
            }

            if ($stamps === []) {
                continue;
            }

            $byJson[Stamp::encode($stamps)][] = $sentence->id;
        }

        foreach ($byJson as $json => $ids) {
            EntitySentence::query()
                ->whereIn('id', $ids)
                ->toBase()
                ->update([
                    // Base-builder update: no model events, no updated_at.
                    'enrichment_stamps' => DB::raw(
                        "coalesce(enrichment_stamps, '{}'::jsonb) || '{$json}'::jsonb",
                    ),
                ]);
        }
    }

    /**
     * Enrich one chunk of sentences via the python service and persist the
     * results of exactly the given enrichers (defaults to every enricher of
     * the entity's language). Writes are deliberately quiet (no model
     * events, no timestamps) so enrichment itself never marks the entity
     * stale again.
     *
     * $sentenceEnrichers narrows what is persisted PER SENTENCE (the job's
     * per-sentence staleness): the payload dispatches the union ($enrichers)
     * — python computes every requested enricher for every sentence — but a
     * sentence stale for only some of them keeps its other annotations and
     * gets stamped only for the enrichers actually persisted for it.
     * Persist-what-you-stamp: an enricher neither written nor stamped for a
     * sentence cannot fake freshness for stored results it did not produce.
     *
     * @param  Collection<int, EntitySentence>  $sentences
     * @param  list<Enricher>|null  $enrichers
     * @param  array<int, list<Enricher>>  $sentenceEnrichers  sentence id -> the enrichers to persist for it; missing id -> all
     * @return array{written: int, versions: array<string, int>} sentences
     *                                                           written and the python-reported algorithm versions — the
     *                                                           stamp's input (ADR 0067)
     */
    public function enrichChunk(Entity $entity, Collection $sentences, ?array $enrichers = null, array $sentenceEnrichers = []): array
    {
        $enrichers ??= $this->registry->forLanguage($entity->language?->code ?? '');

        if ($enrichers === []) {
            return ['written' => 0, 'versions' => []];
        }

        $language = $entity->language?->code ?? '';
        $tokenized = [];

        foreach ($sentences as $sentence) {
            $tokenized[$sentence->id] = $this->tokenizer->tokenizeWithSpans($sentence->content);
        }

        $keys = collect($tokenized)->flatten(1)->pluck('surface')->map(fn (string $s) => $this->tokenizer->lookupKey($s))->unique()->values()->all();
        $resolved = $this->resolveBase($entity, $keys);

        $hintFields = [];
        $hintFieldNames = [];

        foreach ($enrichers as $enricher) {
            foreach ($enricher->tokenHints($entity, $keys, $resolved) as $key => $fields) {
                $hintFields[$key] = array_merge($hintFields[$key] ?? [], $fields);

                foreach (array_keys($fields) as $name) {
                    $hintFieldNames[$name] = true;
                }
            }
        }

        $payload = [
            'language' => $language,
            'enrichers' => array_map(fn (Enricher $enricher): string => $enricher->key(), $enrichers),
            'sentences' => [],
        ];

        foreach ($enrichers as $enricher) {
            $payload += $enricher->requestExtras($entity);
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
                    fn (array $t): array => $this->tokenPayload($t, $resolved, $hintFields, array_keys($hintFieldNames)),
                    $tokenized[$sentence->id],
                ),
            ];
        }

        $response = $this->python->enrich($payload);
        $results = $response['results'];
        $versions = $response['versions'];
        $this->checkVersionParity($entity, $enrichers, $versions);

        foreach ($empty as $id) {
            $results[] = ['id' => $id, 'output' => []];
        }

        $written = 0;

        DB::transaction(function () use ($results, $enrichers, $sentenceEnrichers, &$written): void {
            foreach ($results as $result) {
                $targets = $sentenceEnrichers[$result['id']] ?? $enrichers;
                $row = [];

                foreach ($targets as $enricher) {
                    $row[$enricher->annotation()->column] = $enricher->toStorage($result['output'][$enricher->key()] ?? null);
                }

                EntitySentence::query()
                    ->whereKey($result['id'])
                    ->toBase()
                    ->update($row);
                $written++;
            }
        });

        return ['written' => $written, 'versions' => $versions];
    }

    /**
     * The version contract (ADR 0067): the python service is expected to
     * report each dispatched enricher's algorithm version, and every
     * reported value must match the enricher's declared python version. A
     * mismatch only warns — the stamp records what actually ran, so
     * staleFor re-stales the entity against the declared version and the
     * sweep re-runs the analysis once the two sides agree.
     *
     * @param  list<Enricher>  $enrichers
     * @param  array<string, int>  $reported
     */
    private function checkVersionParity(Entity $entity, array $enrichers, array $reported): void
    {
        foreach ($enrichers as $enricher) {
            $version = $reported[$enricher->key()] ?? null;

            if ($version !== $enricher->pythonVersion()) {
                Log::warning('Enrichment algorithm version mismatch: the python service reports a version the enricher does not expect — the stamp records what ran, and the sweep will re-run the analysis (ADR 0067).', [
                    'entity_id' => $entity->id,
                    'enricher' => $enricher->key(),
                    'expected' => $enricher->pythonVersion(),
                    'reported' => $version,
                ]);
            }
        }
    }

    /**
     * Attach dictionary hints to a token: the shared base resolution (word
     * class, lemma) plus whatever fields the active enrichers contributed —
     * a field no active enricher contributes is not sent (ADR 0067).
     *
     * @param  array{surface: string, start: int, end: int}  $token
     * @param  array<string, array{cls: ?string, lemma: ?string, headword: ?string, word_id: ?int}>  $resolved
     * @param  array<string, array<string, mixed>>  $hintFields
     * @param  list<string>  $hintFieldNames
     */
    private function tokenPayload(array $token, array $resolved, array $hintFields, array $hintFieldNames): array
    {
        $key = $this->tokenizer->lookupKey($token['surface']);
        $hint = array_merge($resolved[$key] ?? [], $hintFields[$key] ?? []);

        $payload = [
            'surface' => $token['surface'],
            'start' => $token['start'],
            'end' => $token['end'],
            'cls' => $hint['cls'] ?? null,
            'lemma' => $hint['lemma'] ?? null,
        ];

        foreach ($hintFieldNames as $name) {
            $payload[$name] = $hint[$name] ?? null;
        }

        return $payload;
    }

    /**
     * Dictionary data for a chunk's token keys, resolved per token:
     * the entity's own word link (already class-prioritised) wins, then a
     * direct dictionary match ordered by class priority.
     *
     * @param  list<string>  $keys
     * @return array<string, array{cls: ?string, lemma: ?string, headword: ?string, word_id: ?int}>
     */
    private function resolveBase(Entity $entity, array $keys): array
    {
        if ($keys === []) {
            return [];
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

        $byKey = [];

        foreach ($keys as $key) {
            $wordId = $linkedWordIds[$key] ?? null;
            $word = $wordId !== null ? ($wordsById[$wordId] ?? null) : ($bestByWord[$key] ?? null);
            $best = $bestByWord[$key] ?? null;

            $byKey[$key] = [
                'cls' => $word?->wordClass?->slug,
                'lemma' => $word?->word,
                // The direct dictionary match's own headword — the Russian
                // stress candidates key off it, not the (possibly linked,
                // form-resolved) word.
                'headword' => $best?->word,
                'word_id' => $word?->id,
            ];
        }

        return $byKey;
    }
}
