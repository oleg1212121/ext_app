<?php

namespace App\Http\Requests;

use App\Support\WordLookupKey;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WordLookupRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The popup's on-open re-resolution payload (popup-ai-enrich ticket
     * 07): the RAW clicked surface plus the clicked column's language code,
     * mirroring the enrich request's adoption-mode rules (AiWordEnrichRequest
     * minus the known-word mode — a lookup has no word id to bind). The key
     * is derived server-side (the title is never trusted), the language must
     * map to a languages row, and a surface whose key normalizes to nothing
     * (marks-only) is rejected before the read can run.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'surface' => [
                'required',
                'string',
                'max:256',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (WordLookupKey::normalize((string) $value) === '') {
                        $fail('The surface must contain at least one letter or number.');
                    }
                },
            ],
            'language_code' => [
                'required',
                'string',
                'exists:languages,code',
            ],
        ];
    }
}
