<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->foreignId('explanation_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
        });

        // Carry the simulator's saved picker choice over: ui_settings.simulator.model
        // ("provider:external_id") was the only per-user model store before this
        // migration. Unknown/stale choices migrate as null.
        DB::table('user_settings')
            ->whereNotNull('ui_settings')
            ->orderBy('id')
            ->each(function (stdClass $row): void {
                $ui = json_decode((string) $row->ui_settings, true);

                if (! is_array($ui)) {
                    return;
                }

                $saved = $ui['simulator']['model'] ?? null;

                if (is_array($ui['simulator'] ?? null)) {
                    unset($ui['simulator']['model']);
                }

                $update = ['ui_settings' => json_encode($ui)];

                if (is_string($saved) && str_contains($saved, ':')) {
                    [$providerKey, $externalId] = explode(':', $saved, 2);

                    $modelId = DB::table('ai_models')
                        ->join('ai_providers', 'ai_providers.id', '=', 'ai_models.ai_provider_id')
                        ->where('ai_providers.key', $providerKey)
                        ->where('ai_models.external_id', $externalId)
                        ->value('ai_models.id');

                    if ($modelId !== null) {
                        $update['ai_model_id'] = $modelId;
                    }
                }

                DB::table('user_settings')->where('id', $row->id)->update($update);
            });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('explanation_model_id');
            $table->dropConstrainedForeignId('ai_model_id');
        });
    }
};
