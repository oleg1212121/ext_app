<?php

namespace App\Http\Requests;

use App\Models\EntitySentence;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSentenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * An illustration sentence's text is its caption (ADR 0050), so it may
     * be emptied here; plain sentences keep the non-empty rule.
     */
    public function rules(): array
    {
        $sentenceId = $this->route('sentence');
        $isIllustration = $sentenceId !== null && EntitySentence::query()
            ->whereKey((int) $sentenceId)
            ->whereNotNull('image_path')
            ->exists();

        return [
            'side' => ['required', 'in:a,b'],
            'content' => $isIllustration
                ? ['nullable', 'string', 'max:5000']
                : ['required', 'string', 'max:5000', $this->nonEmptyString()],
        ];
    }

    protected function nonEmptyString(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (trim((string) $value) === '') {
                $fail('The content must not be empty.');
            }
        };
    }
}
