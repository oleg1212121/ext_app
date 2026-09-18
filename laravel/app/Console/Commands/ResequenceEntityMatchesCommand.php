<?php

namespace App\Console\Commands;

use App\Classes\SentenceAlignmentService;
use App\Models\EntityMatch;
use Illuminate\Console\Command;

class ResequenceEntityMatchesCommand extends Command
{
    protected $signature = 'alignments:resequence {entityMatch : The entity match ID to resequence}';

    protected $description = 'Renumber meaning matches by document position (repairs display order after re-align rounds)';

    public function handle(): int
    {
        $entityMatch = EntityMatch::query()->find((int) $this->argument('entityMatch'));

        if ($entityMatch === null) {
            $this->error("Entity match {$this->argument('entityMatch')} not found.");

            return self::FAILURE;
        }

        $changed = SentenceAlignmentService::create()
            ->resequenceMatchesByDocumentPosition($entityMatch);

        $this->info("Resequenced {$changed} meaning match row(s) for entity match {$entityMatch->id}.");

        return self::SUCCESS;
    }
}
