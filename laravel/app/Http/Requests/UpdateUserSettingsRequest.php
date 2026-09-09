<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'native_language_id' => [
                'nullable',
                'integer',
                Rule::exists('languages', 'id')->where('is_enabled', true),
            ],
        ];
    }
}
