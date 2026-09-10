<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreEntityMatchRequest extends FormRequest
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
            'first_entity_id' => ['required', 'integer', 'exists:entities,id'],
            'second_entity_id' => ['required', 'integer', 'exists:entities,id', 'different:first_entity_id'],
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
            'first_entity_id.required' => 'Choose the first entity.',
            'first_entity_id.exists' => 'The chosen first entity does not exist.',
            'second_entity_id.required' => 'Choose the second entity.',
            'second_entity_id.exists' => 'The chosen second entity does not exist.',
            'second_entity_id.different' => 'Choose two different entities.',
            'chunk_size.min' => 'Chunk size must be at least 25.',
            'chunk_size.max' => 'Chunk size may not exceed 100.',
            'max_n.min' => 'Max sentence span must be at least 1.',
            'max_n.max' => 'Max sentence span may not exceed 8.',
        ];
    }
}
