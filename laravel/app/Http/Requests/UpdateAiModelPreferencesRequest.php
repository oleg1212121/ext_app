<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiModelPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'ai_model_id' => [
                'nullable',
                'integer',
                Rule::exists('ai_models', 'id')->where('is_enabled', true),
            ],
            'explanation_model_id' => [
                'nullable',
                'integer',
                Rule::exists('ai_models', 'id')->where('is_enabled', true),
            ],
        ];
    }
}
