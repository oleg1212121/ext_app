<?php

namespace App\Console\Commands;

use App\Jobs\AlignEntitySentences;
use App\Models\EntityMatch;
use Illuminate\Console\Command;

class AlignmentsResumeCommand extends Command
{
    protected $signature = 'alignments:resume
        {--limit=10 : Maximum entity matches to pick per run}
        {--dry-run : Report what would be dispatched without dispatching}';

    protected $description = 'Pick pending entity matches and dispatch the self-restarting alignment pipeline, preserving human/landmark rows of matches that already have some (ADR 0051). Scheduled every five minutes.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $matches = EntityMatch::query()
            ->where('status', 'pending')
            // An approved entity freezes every alignment it takes part in
            // (ADR 0034); its matches stay pending until an admin intervenes.
            ->where(fn ($query) => $query
                ->whereHas('aEntity', fn ($q) => $q->where('is_approved', false))
                ->whereHas('bEntity', fn ($q) => $q->where('is_approved', false)))
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
            // A match that already has rows carries human edits and
            // landmarks through the re-run (ADR 0051); a row-less match is
            // fresh and takes the from-scratch path with its verify pass.
            $mode = $entityMatch->meaningMatches()->exists() ? 'landmarks preserved' : 'from scratch';

            if ($dryRun) {
                $this->line("Would resume entity match #{$entityMatch->id} (a_entity_id={$entityMatch->a_entity_id}, b_entity_id={$entityMatch->b_entity_id}, {$mode})");
                $dispatched++;

                continue;
            }

            $before = $entityMatch->status;

            try {
                AlignEntitySentences::begin($entityMatch->id);
            } catch (\Throwable $exception) {
                $this->error("Entity match #{$entityMatch->id} failed during begin: {$exception->getMessage()}");
                EntityMatch::whereKey($entityMatch->id)->update([
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);
                $failed++;

                continue;
            }

            $entityMatch->refresh();

            if ($entityMatch->status === 'aligning') {
                $this->info("Dispatched alignment for entity match #{$entityMatch->id} ({$mode})");
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
