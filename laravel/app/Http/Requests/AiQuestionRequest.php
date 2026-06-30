<?php

namespace App\Http\Requests;

use App\Classes\AIModelResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AiQuestionRequest extends FormRequest
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
            'data' => ['nullable', 'string'],
            'question' => ['nullable', 'string', 'max:2000'],
            'model' => ['required', 'string', 'max:200', function ($attribute, $value, $fail) {
                $resolver = app(AIModelResolver::class);
                if (! $resolver->isValidModel($value)) {
                    $fail('The selected AI model is invalid.');
                }
            }],
        ];
    }
}
