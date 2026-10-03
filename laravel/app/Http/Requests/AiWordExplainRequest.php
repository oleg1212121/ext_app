<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AiWordExplainRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The clicked sentence is identified by its entity sentence id —
     * reading rows carry sentence ids on every side (ADR 0060).
     * The model is not client-supplied: the controller resolves the user's
     * stored explanation-model preference via
     * AIModelResolver::resolveExplanationModel().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'entity_sentence_id' => ['required', 'integer', 'exists:entity_sentences,id'],
            'word_id' => ['required', 'integer', 'exists:words,id'],
            'surface' => ['required', 'string', 'max:255'],
        ];
    }
}
