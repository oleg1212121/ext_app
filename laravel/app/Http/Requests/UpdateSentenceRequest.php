<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSentenceRequest extends FormRequest
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
            'lang' => ['required', 'in:en,ru'],
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
