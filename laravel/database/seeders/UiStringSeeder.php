<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\UiString;
use App\Models\UiStringKey;
use Illuminate\Database\Seeder;

class UiStringSeeder extends Seeder
{
    /**
     * Seed every UI string partial in ui-strings/*.php. Each partial returns
     * 'dotted.key' => ['en' => ..., 'ru' => ...]. updateOrCreate keeps admin
     * edits authoritative on re-run order (seeder only refreshes text, admin
     * may still edit afterwards).
     */
    public function run(): void
    {
        $languages = Language::query()->whereIn('code', ['en', 'ru'])->get()->keyBy('code');

        $partials = glob(__DIR__.'/ui-strings/*.php');
        sort($partials);

        foreach ($partials as $partial) {
            $strings = require $partial;

            foreach ($strings as $key => $values) {
                $stringKey = UiStringKey::updateOrCreate(['key' => $key]);

                foreach (['en', 'ru'] as $code) {
                    $language = $languages->get($code);
                    if ($language === null || ! isset($values[$code])) {
                        continue;
                    }

                    UiString::updateOrCreate(
                        ['ui_string_key_id' => $stringKey->id, 'language_id' => $language->id],
                        ['text' => $values[$code]],
                    );
                }
            }
        }
    }
}
