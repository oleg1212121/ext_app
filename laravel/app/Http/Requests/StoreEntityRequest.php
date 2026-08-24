<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreEntityRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:512'],
            'description' => ['nullable', 'string', 'max:2048'],
            'file' => ['nullable', 'file', 'mimes:txt', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'An entity name is required.',
            'name.max' => 'The name may not be longer than 512 characters.',
            'description.max' => 'The description may not be longer than 2048 characters.',
            'file.mimes' => 'The file must be a plain text file (.txt).',
            'file.max' => 'The file may not be larger than 10 MB.',
        ];
    }
}
