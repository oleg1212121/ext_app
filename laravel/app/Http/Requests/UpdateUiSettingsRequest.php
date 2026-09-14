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
            'simulator.model' => ['nullable', 'string', 'max:255'],
            'simulator.question' => ['nullable', 'string', 'max:8000'],
            'simulator.ai_panel_width' => ['integer', 'min:280', 'max:1200'],
            'simulator.workplace_height' => ['integer', 'min:80', 'max:800'],
            'simulator.highlight_words' => ['boolean'],
            'reader' => ['nullable', 'array'],
            'reader.font_size' => ['integer', 'min:16', 'max:38'],
            'reader.highlight' => ['boolean'],
        ];
    }
}
