<?php

namespace App\Console\Commands;

use App\Classes\SentenceEnrichmentService;
use App\Jobs\EnrichEntitySentences;
use App\Models\Entity;
use Illuminate\Console\Command;

class EnrichEntitiesCommand extends Command
{
    protected $signature = 'entities:enrich
        {--limit=10 : Maximum stale entities to dispatch per run}
        {--dry-run : Report what would be dispatched without dispatching}';

    protected $description = 'Dispatch the sentence enrichment pipeline for entities that were never enriched or changed since enrichment (ADR 0052). Scheduled every five minutes.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $enrichment = SentenceEnrichmentService::create();

        // Entities the pipeline cannot enrich (other languages, no sentences)
        // are stamped done so they stop counting as stale.
        $notApplicable = Entity::query()
            ->where(fn ($query) => $query
                ->whereHas('language', fn ($q) => $q->whereNotIn('code', SentenceEnrichmentService::ENRICHABLE_LANGUAGES))
                ->orWhereDoesntHave('sentences'))
            ->whereNull('enriched_at')
            ->pluck('id');

        if ($notApplicable->isNotEmpty() && ! $dryRun) {
            Entity::query()->whereIn('id', $notApplicable)->update(['enriched_at' => now()]);
        }

        $stale = Entity::query()
            ->whereHas('language', fn ($q) => $q->whereIn('code', SentenceEnrichmentService::ENRICHABLE_LANGUAGES))
            ->with('language')
            ->orderBy('id')
            ->get()
            ->filter(fn (Entity $entity) => $enrichment->isStale($entity))
            ->take($limit);

        if ($stale->isEmpty()) {
            $this->info('No entities need enrichment.');

            return self::SUCCESS;
        }

        foreach ($stale as $entity) {
            if ($dryRun) {
                $this->line("Would enrich entity #{$entity->id} ({$entity->language?->code})");

                continue;
            }

            EnrichEntitySentences::begin($entity);
            $this->info("Dispatched enrichment for entity #{$entity->id} ({$entity->language?->code})");
        }

        return self::SUCCESS;
    }
}
