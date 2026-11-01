<?php

namespace App\Console\Commands;

use App\Jobs\RefreshEntityWords;
use App\Models\Entity;
use App\Models\EntitySentence;
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
                // Any stale SENTENCE: never stamped, or stamped before its
                // last content change — the indexer's own per-sentence check
                // via the shared EntitySentence::whereStaleForWordIndex
                // scope, so the sweep and the refresh agree on the work by
                // construction. (Never-built entities have null stamps and
                // qualify here.)
                $q->whereExists(
                    EntitySentence::query()
                        ->selectRaw(1)
                        ->whereColumn('entity_sentences.entity_id', 'entities.id')
                        ->whereStaleForWordIndex()
                )
                    // Or the index is fresh but tokens still wait for a
                    // dictionary match — re-attempted every run so a new
                    // dictionary import takes effect without a manual
                    // crossword:link. Tokens stamped unmatchable don't
                    // qualify; an import clears their stamps.
                    ->orWhereHas('entityWords', fn ($words) => $words
                        ->whereNull('word_id')
                        ->whereNull('unmatchable_at'));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
