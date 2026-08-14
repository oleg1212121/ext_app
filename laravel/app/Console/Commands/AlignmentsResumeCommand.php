<?php

namespace App\Console\Commands;

use App\Jobs\AlignEntitySentences;
use App\Models\EnRuEntityMatch;
use Illuminate\Console\Command;

class AlignmentsResumeCommand extends Command
{
    protected $signature = 'alignments:resume
        {--limit=10 : Maximum entity matches to pick per run}
        {--dry-run : Report what would be dispatched without dispatching}';

    protected $description = 'Pick pending entity matches, verify them, and dispatch the self-restarting alignment pipeline. Scheduled every five minutes.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $matches = EnRuEntityMatch::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($matches->isEmpty()) {
            $this->info('No pending entity matches to resume.');

            return self::SUCCESS;
        }

        $dispatched = 0;
        $failed = 0;

        foreach ($matches as $entityMatch) {
            if ($dryRun) {
                $this->line("Would resume entity match #{$entityMatch->id} (en_entity_id={$entityMatch->en_entity_id}, ru_entity_id={$entityMatch->ru_entity_id})");
                $dispatched++;

                continue;
            }

            $before = $entityMatch->status;

            try {
                AlignEntitySentences::beginFromScratch($entityMatch->id);
            } catch (\Throwable $exception) {
                $this->error("Entity match #{$entityMatch->id} failed during begin: {$exception->getMessage()}");
                EnRuEntityMatch::whereKey($entityMatch->id)->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);
                $failed++;

                continue;
            }

            $entityMatch->refresh();

            if ($entityMatch->status === 'aligning') {
                $this->info("Dispatched alignment for entity match #{$entityMatch->id}");
                $dispatched++;
            } elseif ($entityMatch->status === 'failed') {
                $this->warn("Entity match #{$entityMatch->id} failed verify: {$entityMatch->error_message}");
                $failed++;
            } elseif ($entityMatch->status === 'completed') {
                $reason = $entityMatch->error_message ?? 'no sentences';
                $this->info("Entity match #{$entityMatch->id} completed without dispatch ({$reason})");
                $dispatched++;
            }
        }

        $this->info("Done. Dispatched: {$dispatched}. Failed verify: {$failed}.");

        return self::SUCCESS;
    }
}
