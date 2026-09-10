<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMeaningMatchRequest extends FormRequest
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
            'after_row_id' => [
                'nullable',
                'integer',
                Rule::exists('meaning_matches', 'id')->where(
                    'entity_match_id',
                    $this->route('entityMatch')->id,
                ),
            ],
        ];
    }
}
