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
            'label' => ['nullable', 'string', 'max:256'],
            'description' => ['nullable', 'string', 'max:2048'],
            'file' => ['nullable', 'file', 'mimes:txt', 'max:10240'],
            'work_id' => ['required_without:new_work_title', 'nullable', 'integer', 'exists:works,id'],
            'new_work_title' => ['required_without:work_id', 'nullable', 'string', 'max:512'],
            'new_work_author' => ['nullable', 'string', 'max:512'],
            'new_work_original_language_id' => ['nullable', 'integer', 'exists:languages,id'],
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
            'work_id.required_without' => 'Pick an existing work or enter a title for a new one.',
            'new_work_title.required_without' => 'Pick an existing work or enter a title for a new one.',
            'new_work_title.max' => 'The work title may not be longer than 512 characters.',
        ];
    }
}
