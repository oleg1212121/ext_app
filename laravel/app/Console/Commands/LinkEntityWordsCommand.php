<?php

namespace App\Console\Commands;

use App\Classes\EntityWordLinker;
use App\Models\Entity;
use Illuminate\Console\Command;

class LinkEntityWordsCommand extends Command
{
    protected $signature = 'crossword:link {--entity=* : Entity ids to link (all entities with unlinked words when omitted)}';

    protected $description = 'Link unlinked entity words to dictionary words by (language, lowercase form). Run after wiktionary:import';

    public function handle(EntityWordLinker $linker): int
    {
        $entities = $this->entities();

        if ($entities->isEmpty()) {
            $this->info('No unlinked entity words.');

            return self::SUCCESS;
        }

        foreach ($entities as $entity) {
            $stats = $linker->link($entity);
            $this->info("Entity #{$entity->id} ({$entity->name}): {$stats['linked']} linked, {$stats['unmatched']} without a dictionary match.");
        }

        return self::SUCCESS;
    }

    private function entities()
    {
        $ids = array_map(intval(...), (array) $this->option('entity'));

        $query = Entity::query()
            ->select(['id', 'name', 'language_id'])
            ->whereHas('entityWords', fn ($q) => $q->whereNull('word_id'));

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return $query->orderBy('id')->get();
    }
}
