<?php

namespace Database\Seeders;

use App\Models\PromptTemplate;
use App\Support\PromptTemplates;
use Illuminate\Database\Seeder;

class PromptTemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Register-only: existing rows are admin-owned (their text may have
        // been edited in the Filament "Prompt Templates" resource) and must
        // survive the per-deploy seed run. To restore a default, blank the
        // row's text — blank text falls back to the code constant.
        PromptTemplate::query()->firstOrCreate(
            ['key' => PromptTemplates::FORMAT_KEY],
            ['text' => PromptTemplates::FORMAT_FALLBACK],
        );

        PromptTemplate::query()->firstOrCreate(
            ['key' => PromptTemplates::TASKS_KEY],
            ['text' => PromptTemplates::TASKS_FALLBACK],
        );

        PromptTemplate::query()->firstOrCreate(
            ['key' => PromptTemplates::EXPLANATION_KEY],
            ['text' => PromptTemplates::EXPLANATION_FALLBACK],
        );
    }
}
