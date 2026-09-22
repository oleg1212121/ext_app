<?php

namespace App\Http\Requests;

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
     * Get the validation rules that apply to the request. The model is not
     * client-supplied: the controller resolves the user's stored answer-model
     * preference via AIModelResolver::resolveAnswerModel().
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data' => ['nullable', 'string'],
            'question' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
