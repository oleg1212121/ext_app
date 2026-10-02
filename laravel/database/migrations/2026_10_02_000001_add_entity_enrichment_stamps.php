<?php

use App\Models\Entity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;

return new class extends Migration
{
    /**
     * ADR 0057: the single enriched_at stamp becomes a per-enricher stamp map
     * (entities.enrichment_stamps), so a newly registered enricher makes only
     * itself stale instead of relying on the manual enriched_at reset (the
     * ADR 0053 switchover choreography).
     *
     * Backfill: an entity enriched under ADR 0052 has every enricher of its
     * language done; a never-enriched enrichable entity stays null (stale —
     * the sweep re-runs it); languages with no enrichers get an empty map so
     * they never count as stale.
     */
    public function up(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->jsonb('enrichment_stamps')
                ->nullable()
                ->after('frequency_counted_at')
                ->comment('Per-enricher completion stamps {enricher key: ISO timestamp}; null = never enriched, {} = no enricher applies (ADR 0057).');
        });

        $enrichersByLanguage = [
            'ru' => ['ru_stress'],
            'en' => ['en_stress', 'en_phrasal'],
        ];

        Entity::query()->with('language')->orderBy('id')->each(function (Entity $entity) use ($enrichersByLanguage): void {
            $keys = $enrichersByLanguage[$entity->language?->code ?? ''] ?? [];

            if ($keys === []) {
                // Not enrichable: an empty map stops it counting as stale.
                $stamps = '{}';
            } elseif ($entity->enriched_at === null) {
                return; // Stays null → stale; the sweep re-enriches.
            } else {
                // The model no longer casts the (still-present) column, so
                // the raw value arrives as a string.
                $stamps = json_encode(array_fill_keys($keys, Carbon::parse($entity->enriched_at)->toISOString()));
            }

            Entity::query()->whereKey($entity->id)->toBase()->update([
                'enrichment_stamps' => $stamps,
            ]);
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('enriched_at');
        });
    }

    public function down(): void
    {
        Schema::table('entities', function (Blueprint $table) {
            $table->timestamp('enriched_at')
                ->nullable()
                ->after('frequency_counted_at')
                ->comment('Sentence enrichment finished at; null or older than sentences_updated_at means stale (words_indexed_at pattern).');
        });

        Schema::table('entities', function (Blueprint $table) {
            $table->dropColumn('enrichment_stamps');
        });
    }
};
