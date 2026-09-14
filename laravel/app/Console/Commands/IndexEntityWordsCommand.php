<?php

namespace App\Console\Commands;

use App\Classes\EntityWordIndexer;
use App\Models\Entity;
use App\Models\EntitySentence;
use Illuminate\Console\Command;

class IndexEntityWordsCommand extends Command
{
    protected $signature = 'crossword:index {--entity=* : Entity ids to index (all entities with sentences when omitted)}';

    protected $description = 'Build the entity word list (unique tokens with counts) from entity sentences';

    public function handle(EntityWordIndexer $indexer): int
    {
        $entities = $this->entities();

        if ($entities->isEmpty()) {
            $this->info('No entities with sentences to index.');

            return self::SUCCESS;
        }

        foreach ($entities as $entity) {
            $count = $indexer->index($entity);
            $this->info("Entity #{$entity->id} ({$entity->name}): {$count} unique words indexed.");
        }

        return self::SUCCESS;
    }

    private function entities()
    {
        $ids = array_map(intval(...), (array) $this->option('entity'));

        $query = Entity::query()->select(['id', 'name']);

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        } else {
            $query->whereIn('id', EntitySentence::query()->select('entity_id')->distinct());
        }

        return $query->orderBy('id')->get();
    }
}
