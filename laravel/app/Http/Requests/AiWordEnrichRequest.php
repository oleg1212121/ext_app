<?php

namespace App\Http\Requests;

use App\Support\WordLookupKey;
use Closure;
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
     * Two exclusive modes (popup-ai-enrich tickets 04 + 05): the known-word
     * path binds the popup's word id; the missing-word adoption path takes
     * the RAW clicked surface plus the clicked column's language code — the
     * lookup key is derived server-side (the title is never trusted), the
     * language must map to a languages row, and a surface whose key
     * normalizes to nothing (marks-only) is rejected before anything can
     * mint.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'word_id' => [
                'required_without_all:surface,language_code',
                'missing_with:surface,language_code',
                'integer',
                'exists:words,id',
            ],
            'surface' => [
                'required_without:word_id',
                'string',
                'max:256',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (WordLookupKey::normalize((string) $value) === '') {
                        $fail('The surface must contain at least one letter or number.');
                    }
                },
            ],
            'language_code' => [
                'required_without:word_id',
                'string',
                'exists:languages,code',
            ],
        ];
    }
}
