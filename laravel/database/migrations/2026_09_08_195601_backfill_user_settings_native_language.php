<?php

use App\Models\Language;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $englishId = Language::query()->where('code', 'en')->value('id');

        if ($englishId === null) {
            return;
        }

        DB::table('user_settings')->insertUsing(
            ['user_id', 'native_language_id', 'created_at', 'updated_at'],
            DB::table('users')
                ->whereNotIn('id', DB::table('user_settings')->select('user_id'))
                ->selectRaw('id as user_id, ? as native_language_id, now() as created_at, now() as updated_at', [$englishId])
        );
    }

    public function down(): void
    {
        //
    }
};
