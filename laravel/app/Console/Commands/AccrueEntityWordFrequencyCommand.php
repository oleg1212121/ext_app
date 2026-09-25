<?php

namespace App\Console\Commands;

use App\Classes\WordFrequencyAccrual;
use App\Models\Entity;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class AccrueEntityWordFrequencyCommand extends Command
{
    protected $signature = 'words:accrue-entity-frequency
        {--entity=* : Entity ids to process regardless of their marker}
        {--grace=15 : Minutes an entity must have been word-indexed before processing}
        {--limit=200 : Maximum entities to process per run}';

    protected $description = "Pull word frequency ranks toward each entity's own word ranking (2% of the current value, once per entity). Scheduled every five minutes.";

    public function handle(WordFrequencyAccrual $accrual): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $grace = max(0, (int) $this->option('grace'));
        $ids = array_map(intval(...), (array) $this->option('entity'));

        $query = Entity::query()->select(['id', 'name', 'language_id']);

        if ($ids !== []) {
            $entities = $query->whereIn('id', $ids)->orderBy('id')->limit($limit)->get();
        } else {
            $entities = $query
                ->whereNull('frequency_counted_at')
                ->whereNotNull('words_indexed_at')
                // Give the link pass a few sweeps to fill word_id before the
                // one-time correction burns the marker.
                ->where('words_indexed_at', '<=', Carbon::now()->subMinutes($grace))
                ->orderBy('id')
                ->limit($limit)
                ->get();
        }

        if ($entities->isEmpty()) {
            $this->info('No entities are waiting for a frequency correction.');

            return self::SUCCESS;
        }

        foreach ($entities as $entity) {
            $corrected = $accrual->accrue($entity);
            $this->line("Entity #{$entity->id} ({$entity->name}): {$corrected} word-list entries applied.");
        }

        $this->info('Corrected frequencies for '.$entities->count().' entities.');

        return self::SUCCESS;
    }
}
