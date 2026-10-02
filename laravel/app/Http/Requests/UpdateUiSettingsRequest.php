<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUiSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'simulator' => ['nullable', 'array'],
            'simulator.font_size' => ['integer', 'min:12', 'max:48'],
            'simulator.show_text' => ['boolean'],
            'simulator.show_workplace' => ['boolean'],
            'simulator.show_question' => ['boolean'],
            'simulator.show_ai' => ['boolean'],
            // The user's customized task list (the format template lives in
            // prompt_templates); same limit as the AI endpoints' tasks rule.
            'simulator.question' => ['nullable', 'string', 'max:4000'],
            'simulator.ai_panel_width' => ['integer', 'min:280', 'max:1200'],
            'simulator.workplace_height' => ['integer', 'min:80', 'max:800'],
            'simulator.highlight_words' => ['boolean'],
            'simulator.stress_marks' => ['boolean'],
            'simulator.phrasal_verbs' => ['boolean'],
            'reader' => ['nullable', 'array'],
            'reader.font_size' => ['integer', 'min:16', 'max:38'],
            'reader.highlight' => ['boolean'],
            'reader.stress_marks' => ['boolean'],
            'reader.phrasal_verbs' => ['boolean'],
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
    }
}
