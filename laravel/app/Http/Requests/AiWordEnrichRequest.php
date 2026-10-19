<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AiWordEnrichRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The known-word path (popup-ai-enrich ticket 04): the popup's bound
     * word id. The missing-word adoption path ({surface, language_code},
     * ticket 05) extends the contract later.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'word_id' => ['required', 'integer', 'exists:words,id'],
        ];
    }
}
