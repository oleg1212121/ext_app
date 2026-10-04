<?php

namespace App\Http\Requests;

use App\Models\EntitySentence;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderEntitySentenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'sentence_id' => ['required', 'integer'],
            'after_sentence_id' => ['nullable', 'integer', $this->anchorSentenceExists()],
        ];
    }

    /**
     * after_sentence_id 0 is the "at the beginning" convention, not a
     * sentence id; every other value must name a real sentence (a
     * wrong-entity id surfaces as a 404 from the placement service).
     */
    private function anchorSentenceExists(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === 0) {
                return; // null = append at the end; 0 = at the beginning
            }

            if (EntitySentence::query()->whereKey((int) $value)->doesntExist()) {
                $fail('The insert position sentence does not exist.');
            }
        };
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sentence_id.required' => 'A sentence id is required.',
            'after_sentence_id.integer' => 'The insert position is invalid.',
        ];
    }
}
