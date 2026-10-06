<?php

namespace App\Classes\Enrichment;

use App\Models\Entity;
use App\Models\EntitySentence;
use Illuminate\Support\Carbon;

/**
 * The manifest of enrichers (ADR 0057): which analyses exist, which
 * languages they apply to, and — from the per-enricher completion stamps on
 * entities.enrichment_stamps — which of them an entity is currently stale
 * for. Adding an algorithm means adding one class here; no config, no flags.
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
     * The entity's language enrichers whose completion stamp is missing (a
     * newly registered enricher is automatically stale), was written by an
     * older algorithm version — a version bump re-stales every entity so
     * the sweep re-runs the analysis corpus-wide (ADR 0059) — or older than
     * the last sentence change. Entities of languages with no enrichers are
     * never stale — the sweep does not pick them up.
     *
     * @return list<Enricher>
     */
    public function staleFor(Entity $entity): array
    {
        $enrichers = $this->forLanguage($entity->language?->code ?? '');

        if ($enrichers === []) {
            return [];
        }

        return $this->filterStale(
            $enrichers,
            $entity->enrichment_stamps ?? [],
            EntitySentence::query()
                ->where('entity_id', $entity->id)
                ->max('updated_at'),
        );
    }

    /**
     * staleFor for many entities at once: one grouped max(updated_at) query
     * instead of one per entity, so the sweep's scan stays bounded no matter
     * how large the catalog grows (ADR 0043).
     *
     * @param  iterable<int, Entity>  $entities
     * @return array<int, list<Enricher>> entity id -> its stale enrichers
     */
    public function staleForMany(iterable $entities): array
    {
        $byId = [];

        foreach ($entities as $entity) {
            $byId[$entity->id] = $entity;
        }

        if ($byId === []) {
            return [];
        }

        $lastChanges = EntitySentence::query()
            ->whereIn('entity_id', array_keys($byId))
            ->groupBy('entity_id')
            ->selectRaw('entity_id, max(updated_at) as last_change')
            ->pluck('last_change', 'entity_id');

        $stale = [];

        foreach ($byId as $id => $entity) {
            $stale[$id] = $this->filterStale(
                $this->forLanguage($entity->language?->code ?? ''),
                $entity->enrichment_stamps ?? [],
                $lastChanges[$id] ?? null,
            );
        }

        return $stale;
    }

    /**
     * The staleness predicate behind staleFor/staleForMany: a missing stamp,
     * an older algorithm version on either side, or a sentence change after
     * the stamp makes the enricher stale.
     *
     * @param  list<Enricher>  $enrichers
     * @param  array<string, mixed>  $stamps
     * @param  string|null  $lastSentenceChange  raw aggregate value, not a cast datetime
     * @return list<Enricher>
     */
    private function filterStale(array $enrichers, array $stamps, ?string $lastSentenceChange): array
    {
        return array_values(array_filter(
            $enrichers,
            function (Enricher $enricher) use ($stamps, $lastSentenceChange): bool {
                $stamp = $stamps[$enricher->key()] ?? null;

                if ($stamp === null) {
                    return true;
                }

                // v1 stamps were bare ISO strings; the array shape carries
                // the algorithm version next to the completion time.
                $enrichedAt = is_array($stamp) ? ($stamp['at'] ?? null) : $stamp;

                if ($enrichedAt === null) {
                    return true;
                }

                if ((int) (is_array($stamp) ? ($stamp['v'] ?? 1) : 1) < $enricher->version()) {
                    return true;
                }

                // The python-side algorithm version the stamp was written
                // with (ADR 0067). Legacy stamps carry no pv — read as 0, so
                // every pre-parity stamp is stale exactly once and the sweep
                // rewrites it in the versioned shape.
                $pythonVersion = (int) (is_array($stamp) ? ($stamp['pv'] ?? 0) : 0);

                if ($pythonVersion < $enricher->pythonVersion()) {
                    return true;
                }

                // max('updated_at') is an aggregate — it comes back as a
                // raw string, not through the model's datetime cast.
                return $lastSentenceChange !== null
                    && Carbon::parse($lastSentenceChange)->gt(Carbon::parse($enrichedAt));
            },
        ));
    }
}
