<?php

namespace App\Console\Commands;

use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\SentenceEnrichmentService;
use App\Jobs\EnrichEntitySentences;
use App\Models\Entity;
use Illuminate\Console\Command;

class EnrichEntitiesCommand extends Command
{
    protected $signature = 'entities:enrich
        {--limit=10 : Maximum stale entities to dispatch per run}
        {--enricher= : Force one enricher key (e.g. en_phrasal) across its languages}
        {--dry-run : Report what would be dispatched without dispatching}';

    protected $description = 'Dispatch the sentence enrichment pipeline for the enrichers each entity is stale for (ADR 0057). Scheduled every five minutes.';

    public function handle(EnricherRegistry $registry, SentenceEnrichmentService $enrichment): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        if (($forced = $this->option('enricher')) !== null) {
            return $this->forceEnricher((string) $forced, $registry, $limit, $dryRun);
        }

        $stale = Entity::query()
            ->with('language')
            // SQL-side filter: a language no enricher applies to can never
            // be stale, so its entities are not even loaded.
            ->whereHas('language', fn ($query) => $query->whereIn('code', $registry->languages()))
            ->orderBy('id')
            ->get()
            ->filter(fn (Entity $entity) => $enrichment->staleEnrichers($entity) !== [])
            ->take($limit);

        if ($stale->isEmpty()) {
            $this->info('No entities need enrichment.');

            return self::SUCCESS;
        }

        foreach ($stale as $entity) {
            // Only the enrichers whose stamps are missing or stale run — a
            // newly registered enricher backfills itself here (ADR 0057).
            $keys = collect($enrichment->staleEnrichers($entity))
                ->map(fn ($enricher) => $enricher->key())
                ->values()
                ->all();

            if ($dryRun) {
                $this->line("Would enrich entity #{$entity->id} ({$entity->language?->code}): ".implode(', ', $keys));

                continue;
            }

            EnrichEntitySentences::beginEnrichers($entity, $keys);
            $this->info("Dispatched enrichment for entity #{$entity->id} ({$entity->language?->code}): ".implode(', ', $keys));
        }

        return self::SUCCESS;
    }

    private function forceEnricher(string $key, EnricherRegistry $registry, int $limit, bool $dryRun): int
    {
        $enricher = $registry->forKey($key);

        if ($enricher === null) {
            $this->error("Unknown enricher '{$key}'. Registered keys: ".implode(', ', $registry->keys()));

            return self::FAILURE;
        }

        $targets = Entity::query()
            ->with('language')
            ->whereHas('language', fn ($query) => $query->whereIn('code', $enricher->languages()))
            ->whereHas('sentences')
            ->orderBy('id')
            ->take($limit)
            ->get();

        if ($targets->isEmpty()) {
            $this->info("No entities for enricher '{$key}'.");

            return self::SUCCESS;
        }

        foreach ($targets as $entity) {
            if ($dryRun) {
                $this->line("Would enrich entity #{$entity->id} ({$entity->language?->code}): {$key}");

                continue;
            }

            EnrichEntitySentences::beginEnrichers($entity, [$key]);
            $this->info("Dispatched enrichment for entity #{$entity->id} ({$entity->language?->code}): {$key}");
        }

        return self::SUCCESS;
    }
}
