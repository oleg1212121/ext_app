<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UnmatchedRequest extends FormRequest
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
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
