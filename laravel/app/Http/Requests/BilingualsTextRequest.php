<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BilingualsTextRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('en_ru_entity_match_id') && $this->input('en_ru_entity_match_id') === '') {
            $this->merge(['en_ru_entity_match_id' => null]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'en_ru_entity_match_id' => ['sometimes', 'nullable', 'integer', 'exists:en_ru_entity_matches,id'],
            'filename' => ['required_without:en_ru_entity_match_id', 'string', 'max:500'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'filename.required_without' => 'Provide a text filename or choose an entity match.',
            'en_ru_entity_match_id.exists' => 'The selected entity match was not found.',
        ];
    }
}
