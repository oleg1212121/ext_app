<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddSentenceRequest extends FormRequest
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
        return [
            'side' => ['required', 'in:a,b'],
            'meaning_match_id' => [
                'required',
                'integer',
                Rule::exists('meaning_matches', 'id')->where(
                    'entity_match_id',
                    $this->route('entityMatch')->id,
                ),
            ],
            'content' => ['required', 'string', 'max:5000', $this->nonEmptyString()],
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
