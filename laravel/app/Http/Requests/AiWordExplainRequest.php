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
     * The clicked sentence is identified either by a meaning-match row key
     * (bilingual rows: meaning_match_id + side + sentence_index) or directly
     * by the entity sentence (single-language reader rows: entity_sentence_id).
     * The model is not client-supplied: the controller resolves the user's
     * stored explanation-model preference via
     * AIModelResolver::resolveExplanationModel().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'meaning_match_id' => [
                'required_without:entity_sentence_id',
                'nullable',
                'integer',
                'exists:meaning_matches,id',
            ],
            'side' => ['nullable', 'string', 'in:a,b', 'required_without:entity_sentence_id'],
            'sentence_index' => ['nullable', 'integer', 'min:0', 'required_without:entity_sentence_id'],
            'entity_sentence_id' => [
                'required_without:meaning_match_id',
                'nullable',
                'integer',
                'exists:entity_sentences,id',
            ],
            'word_id' => ['required', 'integer', 'exists:words,id'],
            'surface' => ['required', 'string', 'max:255'],
        ];
    }
}
