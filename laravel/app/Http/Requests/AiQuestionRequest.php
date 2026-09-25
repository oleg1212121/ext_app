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
     * preference via AIModelResolver::resolveAnswerModel(). The client sends
     * only the user's task list plus the current column language codes — the
     * controller joins them with the admin's format template.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data' => ['nullable', 'string'],
            'tasks' => ['nullable', 'string', 'max:4000'],
            'base' => ['nullable', 'string', 'max:8'],
            'learning' => ['nullable', 'string', 'max:8'],
        ];
    }
}
