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
            'interface_language_id' => [
                'nullable',
                'integer',
                Rule::exists('languages', 'id')->where('is_interface_enabled', true),
            ],
            // Required — unlike native/interface: the learning language is the
            // one language the user studies, so an empty submit is a field
            // error, not a silent clear. Enabled languages only.
            'learning_language_id' => [
                'required',
                'integer',
                Rule::exists('languages', 'id')->where('is_enabled', true),
            ],
        ];
    }
}
