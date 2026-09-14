<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:512'],
            'author' => ['nullable', 'string', 'max:512'],
            'description' => ['nullable', 'string', 'max:2048'],
            'original_language_id' => [
                'required',
                'integer',
                Rule::exists('languages', 'id')->where('is_enabled', true),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'A work title is required.',
            'title.max' => 'The title may not be longer than 512 characters.',
            'author.max' => 'The author may not be longer than 512 characters.',
            'description.max' => 'The description may not be longer than 2048 characters.',
            'original_language_id.required' => 'Pick the language the work was written in.',
            'original_language_id.exists' => 'Pick an enabled language.',
        ];
    }
}
