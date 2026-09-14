<?php

namespace App\Console\Commands;

use App\Jobs\RefreshEntityWords;
use App\Models\Entity;
use Illuminate\Console\Command;

class RefreshEntityWordsCommand extends Command
{
    protected $signature = 'crossword:refresh
        {--entity=* : Entity ids to refresh (all stale or unlinked entities when omitted)}
        {--limit=100 : Maximum entities to dispatch per run}
        {--dry-run : Report what would be dispatched without dispatching}';

    protected $description = 'Find entities with a stale word list or unlinked words and dispatch background refresh jobs. Scheduled every five minutes.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $entities = $this->entities($limit);

        if ($entities->isEmpty()) {
            $this->info('No entities need a word-list refresh.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($entities as $entity) {
            if ($dryRun) {
                $this->line("Would refresh entity #{$entity->id} ({$entity->name})");
            } else {
                RefreshEntityWords::dispatch($entity->id);
            }

            $dispatched++;
        }

        $verb = $dryRun ? 'Would dispatch' : 'Dispatched';
        $this->info("{$verb} {$dispatched} word-list refresh jobs.");

        return self::SUCCESS;
    }

    private function entities(int $limit)
    {
        $ids = array_map(intval(...), (array) $this->option('entity'));

        $query = Entity::query()->select(['id', 'name']);

        if ($ids !== []) {
            return $query->whereIn('id', $ids)->orderBy('id')->limit($limit)->get();
        }

        return $query
            // Nothing to tokenize without sentences — skip entities whose
            // sentences are all gone so leftover rows cannot loop forever.
            ->whereExists(function ($q): void {
                $q->selectRaw(1)
                    ->from('entity_sentences')
                    ->whereColumn('entity_sentences.entity_id', 'entities.id');
            })
            ->where(function ($q): void {
                $q->where(function ($stale): void {
                    // Stale index: never built, or any sentence changed after
                    // the last build (mirrors EntityWordIndexer::isStale).
                    $stale->whereNull('entities.words_indexed_at')
                        ->orWhereExists(function ($q): void {
                            $q->selectRaw(1)
                                ->from('entity_sentences')
                                ->whereColumn('entity_sentences.entity_id', 'entities.id')
                                ->whereColumn('entity_sentences.updated_at', '>', 'entities.words_indexed_at');
                        });
                })
                    // Or the index is fresh but tokens still wait for a
                    // dictionary match — re-attempted every run so a new
                    // dictionary import takes effect without a manual
                    // crossword:link.
                    ->orWhereHas('entityWords', fn ($words) => $words->whereNull('word_id'));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
