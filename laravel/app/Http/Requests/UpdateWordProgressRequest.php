<?php

namespace App\Http\Requests;

use App\Models\UserWord;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWordProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'familiarity' => ['required', 'integer', 'min:'.UserWord::FAMILIARITY_MIN, 'max:'.UserWord::FAMILIARITY_MAX],
        ];
    }
}
