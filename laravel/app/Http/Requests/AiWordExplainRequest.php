<?php

namespace App\Http\Requests;

use App\Classes\AIModelResolver;
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
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'meaning_match_id' => ['required', 'integer', 'exists:meaning_matches,id'],
            'side' => ['required', 'string', 'in:a,b'],
            'sentence_index' => ['required', 'integer', 'min:0'],
            'word_id' => ['required', 'integer', 'exists:words,id'],
            'surface' => ['required', 'string', 'max:255'],
            'model' => ['required', 'string', 'max:200', function ($attribute, $value, $fail) {
                $resolver = app(AIModelResolver::class);
                if (! $resolver->isValidModel($value)) {
                    $fail('The selected AI model is invalid.');
                }
            }],
        ];
    }
}
