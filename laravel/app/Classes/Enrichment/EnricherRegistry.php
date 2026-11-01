<?php

namespace App\Classes\Enrichment;

use App\Models\Entity;
use App\Models\EntitySentence;
use Illuminate\Database\Eloquent\Builder;

/**
 * The manifest of enrichers (ADR 0057): which analyses exist, which
 * languages they apply to, and — from the per-sentence completion stamps on
 * entity_sentences.enrichment_stamps — which of them an entity's sentences
 * are currently stale for. Adding an algorithm means adding one class here;
 * no config, no flags.
 */
class EnricherRegistry
{
    /** @var list<Enricher> */
    private array $enrichers;

    /**
     * @param  list<Enricher>|null  $enrichers  test seam; defaults to the full manifest
     */
    public function __construct(?array $enrichers = null)
    {
        $this->enrichers = $enrichers ?? [
            new RussianStressEnricher,
            new BelarusianStressEnricher,
            new EnglishStressEnricher,
            new EnglishPhrasalVerbEnricher,
        ];
    }

    /**
     * The enrichers applying to one language code — "what should be included
     * in the enrichment process" for that language (empty for languages the
     * pipeline does not enrich).
     *
     * @return list<Enricher>
     */
    public function forLanguage(string $code): array
    {
        return array_values(array_filter(
            $this->enrichers,
            fn (Enricher $enricher): bool => in_array($code, $enricher->languages(), true),
        ));
    }

    /**
     * The enricher instances for the given keys, unknown keys filtered out
     * (a queued job referencing a since-removed enricher must not crash).
     *
     * @param  list<string>  $keys
     * @return list<Enricher>
     */
    public function forKeys(array $keys): array
    {
        return array_values(array_filter(
            $this->enrichers,
            fn (Enricher $enricher): bool => in_array($enricher->key(), $keys, true),
        ));
    }

    public function forKey(string $key): ?Enricher
    {
        foreach ($this->enrichers as $enricher) {
            if ($enricher->key() === $key) {
                return $enricher;
            }
        }

        return null;
    }

    /**
     * Every language code any enricher applies to.
     *
     * @return list<string>
     */
    public function languages(): array
    {
        $codes = [];

        foreach ($this->enrichers as $enricher) {
            foreach ($enricher->languages() as $code) {
                $codes[$code] = true;
            }
        }

        return array_keys($codes);
    }

    /**
     * The deduplicated display verticals (ADR 0067): one Annotation per
     * payload key even where two enrichers feed it (both stress analyses
     * share the stress marks annotation).
     *
     * @return list<Annotation>
     */
    public function annotations(): array
    {
        $byPayloadKey = [];

        foreach ($this->enrichers as $enricher) {
            $byPayloadKey[$enricher->annotation()->payloadKey] ??= $enricher->annotation();
        }

        return array_values($byPayloadKey);
    }

    /**
     * Every registered enricher key, manifest order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(fn (Enricher $enricher): string => $enricher->key(), $this->enrichers);
    }

    /**
     * The enrichers of the given set whose stamp ON THE SENTENCE is stale:
     * missing (a newly registered or retro-processed enricher), an older
     * algorithm version on either side — a bump on either side re-stales
     * every sentence stamped below it (ADR 0059, ADR 0067) — or the
     * sentence's own content changed after the stamp. Clock-skew safe by
     * construction: the stamp is written after the change it covers, so it
     * can only be equal-or-later than the sentence's updated_at.
     *
     * @param  list<Enricher>  $enrichers  the candidate set — the caller
     *                                     resolves the language's enrichers
     * @return list<string> stale enricher keys, candidate-set order
     */
    public function staleKeysForSentence(EntitySentence $sentence, array $enrichers): array
    {
        $stamps = $sentence->enrichment_stamps ?? [];

        $keys = [];

        foreach ($enrichers as $enricher) {
            $stamp = $stamps[$enricher->key()] ?? null;

            if (Stamp::stale($enricher, $stamp, $sentence->updated_at?->toISOString())) {
                $keys[] = $enricher->key();
            }
        }

        return $keys;
    }

    /**
     * The entity's sentences stale for any of the given enrichers (default:
     * every enricher of the entity's language), in id order — the
     * enrichment job's processing window. Languages with no enrichers match
     * nothing: their sentences are never stale.
     *
     * The per-key conditions mirror Stamp::stale exactly — including the
     * legacy tolerances (a bare-ISO stamp reads as version 1 with no python
     * version, a versioned stamp without pv reads as pv 0) — so SQL and the
     * PHP predicate cannot disagree. `at` is pinned to UTC explicitly so the
     * comparison against the (UTC wall-clock) updated_at column does not
     * depend on the database session timezone.
     *
     * @param  list<Enricher>|null  $enrichers
     * @return Builder<EntitySentence>
     */
    public function staleSentenceQuery(Entity $entity, ?array $enrichers = null)
    {
        $enrichers ??= $this->forLanguage($entity->language?->code ?? '');

        return EntitySentence::query()
            ->where('entity_id', $entity->id)
            ->when($enrichers !== [], fn ($query) => $query->where($this->sentenceStaleConditions($enrichers)))
            ->when($enrichers === [], fn ($query) => $query->whereRaw('false'))
            ->orderBy('id');
    }

    /**
     * The union of the entity's sentences' stale enricher keys — "what does
     * this entity still need". Empty for a fully enriched entity and for
     * languages with no enrichers.
     *
     * @return list<string>
     */
    public function staleKeysFor(Entity $entity): array
    {
        return $this->staleKeysForMany([$entity])[$entity->id] ?? [];
    }

    /**
     * staleKeysFor for many entities at once: one grouped query with a
     * bool_or per enricher key, so the sweep's scan stays bounded no matter
     * how large the catalog grows (ADR 0043). Entities of languages with no
     * enrichers map to [] — never stale, never picked. An entity with no
     * sentences maps to [] too: staleness needs at least one stale sentence.
     *
     * @param  iterable<int, Entity>  $entities
     * @return array<int, list<string>> entity id -> its stale enricher keys
     */
    public function staleKeysForMany(iterable $entities): array
    {
        $byId = [];

        foreach ($entities as $entity) {
            $byId[$entity->id] = $entity;
        }

        if ($byId === []) {
            return [];
        }

        $enrichersByEntity = [];

        foreach ($byId as $id => $entity) {
            $enrichersByEntity[$id] = $this->forLanguage($entity->language?->code ?? '');
        }

        // One bool_or column per distinct enricher key in the batch — keys
        // are globally unique across the manifest, so the column name is the
        // safe decode address for every entity's row.
        $distinctEnrichers = [];

        foreach ($enrichersByEntity as $enrichers) {
            foreach ($enrichers as $enricher) {
                $distinctEnrichers[$enricher->key()] ??= $enricher;
            }
        }

        $columns = ['entity_id'];
        $bindings = [];

        foreach ($distinctEnrichers as $enricher) {
            [$clause, $clauseBindings] = $this->sentenceStaleClause($enricher);
            $columns[] = "bool_or({$clause}) as {$enricher->key()}";
            $bindings = array_merge($bindings, $clauseBindings);
        }

        $rows = EntitySentence::query()
            ->whereIn('entity_id', array_keys($byId))
            ->groupBy('entity_id')
            ->selectRaw(implode(', ', $columns), $bindings)
            ->get()
            ->keyBy('entity_id');

        $stale = [];

        foreach ($byId as $id => $entity) {
            $languageEnrichers = $enrichersByEntity[$id];
            $row = $rows->get($id);

            if ($languageEnrichers === [] || $row === null) {
                // A language with no enrichers is never stale; an entity
                // with no sentences has no stale sentence.
                $stale[$id] = [];
            } else {
                $stale[$id] = array_values(array_filter(
                    array_map(fn (Enricher $e) => $row->{$e->key()} ? $e->key() : null, $languageEnrichers),
                ));
            }
        }

        return $stale;
    }

    /**
     * The SQL mirror of Stamp::stale for one enricher, as a where-clause
     * closure over the entity_sentences query.
     *
     * @param  list<Enricher>  $enrichers
     * @return \Closure(\Illuminate\Database\Query\Builder): void
     */
    private function sentenceStaleConditions(array $enrichers): \Closure
    {
        return function ($query) use ($enrichers): void {
            foreach ($enrichers as $enricher) {
                [$clause, $bindings] = $this->sentenceStaleClause($enricher);
                $query->orWhereRaw($clause, $bindings);
            }
        };
    }

    /**
     * The raw per-key staleness clause and its bindings. Bindings order:
     * key (null check), key (typeof), key+key (v), key+key+version (v
     * compare), key+key (typeof), key+key (pv), key+key+pythonVersion (pv
     * compare), key (typeof), key+key (at), key (typeof), key+key (at
     * extract), at extract (timestamp compare).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function sentenceStaleClause(Enricher $enricher): array
    {
        $k = $enricher->key();
        $column = 'enrichment_stamps';

        return [
            <<<SQL
            (
                {$column} -> ? is null
                or case when jsonb_typeof({$column} -> ?) = 'object'
                    then coalesce(({$column} -> ? ->> 'v')::int, 1) else 1 end < ?
                or case when jsonb_typeof({$column} -> ?) = 'object'
                    then coalesce(({$column} -> ? ->> 'pv')::int, 0) else 0 end < ?
                or coalesce(
                    case when jsonb_typeof({$column} -> ?) = 'object'
                        then {$column} -> ? ->> 'at'
                        else {$column} -> ? #>> '{}' end, '') = ''
                or (
                    case when jsonb_typeof({$column} -> ?) = 'object'
                        then {$column} -> ? ->> 'at'
                        else {$column} -> ? #>> '{}' end
                )::timestamptz at time zone 'UTC' < updated_at
            )
            SQL,
            [
                $k,
                $k, $k, $enricher->version(),
                $k, $k, $enricher->pythonVersion(),
                $k, $k, $k,
                $k, $k, $k,
            ],
        ];
    }
}
