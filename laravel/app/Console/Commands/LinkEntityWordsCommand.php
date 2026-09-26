<?php

namespace App\Console\Commands;

use App\Classes\EntityWordLinker;
use App\Models\Entity;
use Illuminate\Console\Command;

class LinkEntityWordsCommand extends Command
{
    protected $signature = 'crossword:link
        {--entity=* : Entity ids to link (all entities with unlinked words when omitted)}
        {--retry-unmatched : Clear the unmatchable stamps of the selected entities first}';

    protected $description = 'Link unlinked entity words to dictionary words by (language, lowercase form). Run after wiktionary:import';

    public function handle(EntityWordLinker $linker): int
    {
        $ids = array_map(intval(...), (array) $this->option('entity'));
        $retry = (bool) $this->option('retry-unmatched');

        // Retry mode re-attempts stamped tokens too, so the selection must
        // include entities whose unlinked words are all stamped.
        $entities = $this->entities($ids, $retry);

        if ($retry) {
            $cleared = EntityWordLinker::clearUnmatchedForEntities(
                $ids !== [] ? $ids : $entities->pluck('id')->all(),
            );
            $this->info("Cleared {$cleared} unmatchable stamp(s).");
        }

        if ($entities->isEmpty()) {
            $this->info('No unlinked entity words.');

            return self::SUCCESS;
        }

        foreach ($entities as $entity) {
            $stats = $linker->link($entity);
            $budgetNote = $stats['budget_exhausted'] ? ' (run budget reached — rerun to continue)' : '';
            $this->info("Entity #{$entity->id} ({$entity->name}): {$stats['linked']} linked, {$stats['unmatched']} without a dictionary match.{$budgetNote}");
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<int>  $ids
     */
    private function entities(array $ids, bool $includeStamped): iterable
    {
        $query = Entity::query()->select(['id', 'name', 'language_id']);

        if ($ids !== []) {
            return $query->whereIn('id', $ids)->orderBy('id')->get();
        }

        return $query
            ->whereHas('entityWords', fn ($q) => $q
                ->whereNull('word_id')
                ->when(! $includeStamped, fn ($q) => $q->whereNull('unmatchable_at')))
            ->orderBy('id')
            ->get();
    }
}
