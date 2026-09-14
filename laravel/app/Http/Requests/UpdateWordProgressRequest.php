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
            'status' => ['required', 'string', 'in:'.UserWord::STATUS_KNOWN],
        ];
    }
}
