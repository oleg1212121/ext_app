<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STRIDE = 1024;

    public function up(): void
    {
        $this->repairDuplicateOrders('en_entity_sentences', 'en_entity_id');
        $this->repairDuplicateOrders('ru_entity_sentences', 'ru_entity_id');

        Schema::table('en_entity_sentences', function (Blueprint $table) {
            $table->unique(['en_entity_id', 'order']);
        });

        Schema::table('ru_entity_sentences', function (Blueprint $table) {
            $table->unique(['ru_entity_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::table('en_entity_sentences', function (Blueprint $table) {
            $table->dropUnique(['en_entity_id', 'order']);
        });

        Schema::table('ru_entity_sentences', function (Blueprint $table) {
            $table->dropUnique(['ru_entity_id', 'order']);
        });
    }

    /**
     * Sentence orders could collide (the alignment editor's drag-and-drop
     * placement could reuse a neighbouring order when the surrounding sparse
     * gap was exhausted), so renumber every affected entity's list
     * positionally — preserving relative order — before the unique indexes
     * below are created. Two-phase update (park at unique negatives first) so
     * re-runs can never collide mid-write.
     */
    private function repairDuplicateOrders(string $table, string $scopeColumn): void
    {
        $affectedScopes = DB::table($table)
            ->select($scopeColumn)
            ->groupBy($scopeColumn)
            ->havingRaw('count(*) > count(distinct "order")')
            ->pluck($scopeColumn);

        foreach ($affectedScopes as $scopeId) {
            $updates = DB::table($table)
                ->where($scopeColumn, $scopeId)
                ->orderBy('order')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn (int $id, int $index): array => ['id' => $id, 'order' => $index * self::STRIDE])
                ->all();

            foreach ($updates as $update) {
                DB::table($table)
                    ->where('id', $update['id'])
                    ->update(['order' => -$update['id'] - 1_000_000_000]);
            }

            foreach ($updates as $update) {
                DB::table($table)
                    ->where('id', $update['id'])
                    ->update(['order' => $update['order']]);
            }
        }
    }
};
