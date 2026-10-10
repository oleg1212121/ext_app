<?php

namespace Database\Seeders;

use App\Models\Language;
use App\Models\UiString;
use App\Models\UiStringKey;
use App\Support\UiStrings;
use Illuminate\Database\Seeder;

class UiStringSeeder extends Seeder
{
    /**
     * Seed every UI string partial in ui-strings/*.php. Each partial returns
     * 'dotted.key' => ['en' => ..., 'ru' => ..., ...]. The language loop comes
     * from the interface-enabled registry, so adding an interface language is
     * a row insert: keys without a value for a language are skipped and the
     * English fallback chain covers them at render time (ADR 0023).
     * updateOrCreate keeps admin edits authoritative on re-run order (seeder
     * only refreshes text, admin may still edit afterwards).
     */
    public function run(): void
    {
        $languages = Language::query()
            ->where('is_interface_enabled', true)
            ->orderBy('sort_order')
            ->get()
            ->keyBy('code');

        $partials = glob(__DIR__.'/ui-strings/*.php');
        sort($partials);

        foreach ($partials as $partial) {
            $strings = require $partial;

            foreach ($strings as $key => $values) {
                $stringKey = UiStringKey::updateOrCreate(['key' => $key]);

                foreach ($languages as $code => $language) {
                    if (! isset($values[$code])) {
                        continue;
                    }

                    UiString::updateOrCreate(
                        ['ui_string_key_id' => $stringKey->id, 'language_id' => $language->id],
                        ['text' => $values[$code]],
                    );
                }
            }
        }

        UiStrings::flush();
    }
}
