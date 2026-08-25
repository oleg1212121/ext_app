<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEntitySentenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $entity = $this->route('entity');

        return [
            'content' => ['required', 'string', 'max:65535', $this->nonEmptyString()],
            'sentence_type_id' => ['required', 'integer', Rule::exists('sentence_types', 'id')],
            'after_sentence_id' => ['nullable', 'integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'The sentence content is required.',
            'content.max' => 'The sentence may not be longer than 65535 characters.',
            'sentence_type_id.required' => 'A sentence type is required.',
            'sentence_type_id.exists' => 'The selected sentence type is invalid.',
        ];
    }

    protected function nonEmptyString(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (trim((string) $value) === '') {
                $fail('The content must not be empty.');
            }
        };
    }
}
