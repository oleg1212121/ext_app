<?php

namespace App\Console\Commands;

use App\Classes\SparseOrderService;
use App\Models\EnEntity;
use App\Models\EnEntitySentence;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use App\Models\RuEntity;
use App\Models\RuEntitySentence;
use Illuminate\Console\Command;

class RebalanceEntityOrdersCommand extends Command
{
    protected $signature = 'entity-orders:rebalance
                            {--lang=all : Sentence language to rebalance (en, ru, or all)}
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
        $lang = (string) $this->option('lang');

        if (! in_array($lang, ['en', 'ru', 'all'], true)) {
            $this->error('The --lang option must be en, ru, or all.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $dryRun = (bool) $this->option('dry-run');
        $results = [];

        if ($this->option('entity-id') !== null) {
            if ($lang === 'all') {
                $this->error('Use --lang=en or --lang=ru with --entity-id.');

                return self::FAILURE;
            }

            $results[] = $this->rebalanceEntity($lang, (int) $this->option('entity-id'), $dryRun);
        } elseif ($this->option('entity-match-id') === null) {
            $results = [
                ...$results,
                ...$this->rebalanceEntities($lang, $limit, $dryRun),
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
    private function rebalanceEntities(string $lang, ?int $limit, bool $dryRun): array
    {
        $results = [];

        if (in_array($lang, ['en', 'all'], true)) {
            foreach ($this->entityIds(EnEntity::class, $limit) as $entityId) {
                $results[] = $this->rebalanceEntity('en', $entityId, $dryRun);
            }
        }

        if (in_array($lang, ['ru', 'all'], true)) {
            foreach ($this->entityIds(RuEntity::class, $limit) as $entityId) {
                $results[] = $this->rebalanceEntity('ru', $entityId, $dryRun);
            }
        }

        return $results;
    }

    /**
     * @param  class-string<EnEntity|RuEntity>  $modelClass
     * @return list<int>
     */
    private function entityIds(string $modelClass, ?int $limit): array
    {
        return $modelClass::query()
            ->orderBy('id')
            ->when($limit !== null, fn ($query) => $query->limit($limit))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array{scope: string, id: int, changed: int}
     */
    private function rebalanceEntity(string $lang, int $entityId, bool $dryRun): array
    {
        $modelClass = $lang === 'en' ? EnEntitySentence::class : RuEntitySentence::class;
        $scopeColumn = $lang === 'en' ? 'en_entity_id' : 'ru_entity_id';

        return [
            'scope' => "{$lang}_entity_sentences",
            'id' => $entityId,
            'changed' => $this->sparseOrder->rebalanceAll($modelClass, $scopeColumn, $entityId, $dryRun),
        ];
    }

    /**
     * @return list<array{scope: string, id: int, changed: int}>
     */
    private function rebalanceAllMeaningMatches(?int $limit, bool $dryRun): array
    {
        return EnRuEntityMatch::query()
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
            'scope' => 'en_ru_meaning_matches',
            'id' => $entityMatchId,
            'changed' => $this->sparseOrder->rebalanceAll(
                EnRuMeaningMatch::class,
                'en_ru_entity_match_id',
                $entityMatchId,
                $dryRun,
            ),
        ];
    }
}
