<?php

namespace Database\Seeders;

use App\Models\PromptTemplate;
use App\Support\PromptTemplates;
use Illuminate\Database\Seeder;

class PromptTemplateSeeder extends Seeder
{
    /**
     * Seed the prompt templates. updateOrCreate keeps admin edits
     * authoritative in normal operation (the seeder only refreshes text on an
     * explicit re-seed, admin may still edit afterwards) — the same contract
     * as UiStringSeeder.
     */
    public function run(): void
    {
        PromptTemplate::updateOrCreate(
            ['key' => PromptTemplates::FORMAT_KEY],
            ['text' => PromptTemplates::FORMAT_FALLBACK],
        );

        PromptTemplate::updateOrCreate(
            ['key' => PromptTemplates::TASKS_KEY],
            ['text' => PromptTemplates::TASKS_FALLBACK],
        );

        PromptTemplate::updateOrCreate(
            ['key' => PromptTemplates::EXPLANATION_KEY],
            ['text' => PromptTemplates::EXPLANATION_FALLBACK],
        );
    }
}
