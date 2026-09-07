<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEnRuEntityMatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'en_entity_id' => ['required', 'integer', 'exists:en_entities,id'],
            'ru_entity_id' => ['required', 'integer', 'exists:ru_entities,id'],
            'is_original_en' => ['required', 'boolean'],
            'chunk_size' => ['required', 'integer', 'min:25', 'max:100'],
            'max_n' => ['required', 'integer', 'min:1', 'max:8'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'en_entity_id.required' => 'Choose the English entity.',
            'en_entity_id.exists' => 'The chosen English entity does not exist.',
            'ru_entity_id.required' => 'Choose the Russian entity.',
            'ru_entity_id.exists' => 'The chosen Russian entity does not exist.',
            'chunk_size.min' => 'Chunk size must be at least 25.',
            'chunk_size.max' => 'Chunk size may not exceed 100.',
            'max_n.min' => 'Max sentence span must be at least 1.',
            'max_n.max' => 'Max sentence span may not exceed 8.',
        ];
    }
}
