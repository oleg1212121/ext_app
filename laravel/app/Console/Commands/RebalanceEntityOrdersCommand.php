<?php

namespace App\Console\Commands;

use App\Classes\SparseOrderService;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use Illuminate\Console\Command;

class RebalanceEntityOrdersCommand extends Command
{
    protected $signature = 'entity-orders:rebalance
                            {--entity-id= : Rebalance a single entity sentence list}
                            {--entity-match-id= : Rebalance one meaning-match list}
                            {--limit= : Maximum number of entity/entity-match lists to process}
                            {--dry-run : Report rows that would change without updating them}';

    protected $description = 'Rebalance sparse order values for entity sentences and meaning matches';

    public function __construct(
        private readonly SparseOrderService $sparseOrder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $dryRun = (bool) $this->option('dry-run');
        $results = [];

        if ($this->option('entity-id') !== null) {
            $results[] = $this->rebalanceEntity((int) $this->option('entity-id'), $dryRun);
        } elseif ($this->option('entity-match-id') === null) {
            $results = [
                ...$results,
                ...$this->rebalanceEntities($limit, $dryRun),
            ];
        }

        if ($this->option('entity-match-id') !== null) {
            $results[] = $this->rebalanceMeaningMatches((int) $this->option('entity-match-id'), $dryRun);
        } elseif ($this->option('entity-id') === null) {
            $results = [
                ...$results,
                ...$this->rebalanceAllMeaningMatches($limit, $dryRun),
            ];
        }

        $this->table(['Scope', 'ID', 'Rows changed'], $results);

        $changedRows = array_sum(array_map(fn (array $result): int => (int) $result['changed'], $results));
        $message = $dryRun ? 'Rows that would change' : 'Rows changed';
        $this->info("{$message}: {$changedRows}");

        return self::SUCCESS;
    }

    /**
     * @return list<array{scope: string, id: int, changed: int}>
     */
    private function rebalanceEntities(?int $limit, bool $dryRun): array
    {
        $results = [];

        foreach ($this->entityIds($limit) as $entityId) {
            $results[] = $this->rebalanceEntity($entityId, $dryRun);
        }

        return $results;
    }

    /**
     * @return list<int>
     */
    private function entityIds(?int $limit): array
    {
        return Entity::query()
            ->orderBy('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array{scope: string, id: int, changed: int}
     */
    private function rebalanceEntity(int $entityId, bool $dryRun): array
    {
        return [
            'scope' => 'entity_sentences',
            'id' => $entityId,
            'changed' => $this->sparseOrder->rebalanceAll(EntitySentence::class, 'entity_id', $entityId, $dryRun),
        ];
    }

    /**
     * @return list<array{scope: string, id: int, changed: int}>
     */
    private function rebalanceAllMeaningMatches(?int $limit, bool $dryRun): array
    {
        return EntityMatch::query()
            ->orderBy('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->pluck('id')
            ->map(fn ($entityMatchId): array => $this->rebalanceMeaningMatches((int) $entityMatchId, $dryRun))
            ->all();
    }

    /**
     * @return array{scope: string, id: int, changed: int}
     */
    private function rebalanceMeaningMatches(int $entityMatchId, bool $dryRun): array
    {
        return [
            'scope' => 'meaning_matches',
            'id' => $entityMatchId,
            'changed' => $this->sparseOrder->rebalanceAll(
                MeaningMatch::class,
                'entity_match_id',
                $entityMatchId,
                $dryRun,
            ),
        ];
    }
}
