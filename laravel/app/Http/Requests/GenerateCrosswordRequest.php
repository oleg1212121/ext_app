<?php

namespace App\Http\Requests;

use App\Classes\CrosswordLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GenerateCrosswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['level' => (int) $this->input('level', 0)]);
    }

    /**
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'entity_id' => ['required', 'integer', 'exists:entities,id'],
            'level' => ['required', 'integer', 'min:0', 'max:'.(CrosswordLevel::count() - 1)],
        ];
    }
}
