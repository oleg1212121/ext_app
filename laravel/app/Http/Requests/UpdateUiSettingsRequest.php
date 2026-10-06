<?php

namespace App\Http\Requests;

use App\Classes\Enrichment\EnricherRegistry;
use App\Support\SavedUiSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateUiSettingsRequest extends FormRequest
{
    public function __construct(
        private readonly EnricherRegistry $enrichers,
    ) {}

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $rules = [
            'simulator' => ['nullable', 'array'],
            'simulator.font_size' => $this->integerRule(SavedUiSettings::SIMULATOR_FONT_SIZE),
            'simulator.show_text' => ['boolean'],
            'simulator.show_workplace' => ['boolean'],
            'simulator.show_question' => ['boolean'],
            'simulator.show_ai' => ['boolean'],
            // The user's customized task list (the format template lives in
            // prompt_templates); same limit as the AI endpoints' tasks rule.
            'simulator.question' => ['nullable', 'string', 'max:4000'],
            'simulator.ai_panel_width' => $this->integerRule(SavedUiSettings::SIMULATOR_AI_PANEL_WIDTH),
            'simulator.workplace_height' => $this->integerRule(SavedUiSettings::SIMULATOR_WORKPLACE_HEIGHT),
            'simulator.highlight_words' => ['boolean'],
            'reader' => ['nullable', 'array'],
            'reader.font_size' => $this->integerRule(SavedUiSettings::READER_FONT_SIZE),
            'reader.highlight' => ['boolean'],
            // Word popup section visibility (the profile's Popups tab): a key
            // absent from the saved map means visible, so only explicit
            // opt-outs travel.
            'popup' => ['nullable', 'array'],
            'popup.familiarity' => ['boolean'],
            'popup.progress_actions' => ['boolean'],
            'popup.form_of' => ['boolean'],
            'popup.word_family' => ['boolean'],
            'popup.frequency' => ['boolean'],
            'popup.transcriptions' => ['boolean'],
            'popup.definitions' => ['boolean'],
            'popup.translations' => ['boolean'],
            'popup.examples' => ['boolean'],
            'popup.etymologies' => ['boolean'],
            'popup.explanation' => ['boolean'],
        ];

        // The annotation display preferences are registry-owned (ADR 0067):
        // every annotation accepts its key on both surfaces.
        foreach ($this->enrichers->annotations() as $annotation) {
            $rules["simulator.{$annotation->settingKey}"] = ['boolean'];
            $rules["reader.{$annotation->settingKey}"] = ['boolean'];
        }

        return $rules;
    }

    /**
     * The validation side of a bounded integer setting; the clamping side
     * reads the same bounds at render time (SavedUiSettings::int).
     *
     * @param  array{min: int, max: int, default: int}  $bounds
     */
    private function integerRule(array $bounds): array
    {
        return ['integer', "min:{$bounds['min']}", "max:{$bounds['max']}"];
    }
}
