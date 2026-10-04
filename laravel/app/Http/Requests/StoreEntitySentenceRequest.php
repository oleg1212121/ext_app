<?php

namespace App\Http\Requests;

use App\Models\EntitySentence;
use App\Models\SentenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEntitySentenceRequest extends FormRequest
{
    private const IMAGE_MIMES = 'jpg,jpeg,png,webp,gif';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Illustration sentences (ADR 0050) carry the image file and may leave
     * the caption empty; every other type keeps the plain-sentence rules.
     */
    public function rules(): array
    {
        $isIllustration = $this->isIllustrationType();

        return [
            'content' => $isIllustration
                ? ['nullable', 'string', 'max:65535']
                : ['required', 'string', 'max:65535', $this->nonEmptyString()],
            'sentence_type_id' => ['required', 'integer', Rule::exists('sentence_types', 'id')],
            'image' => $isIllustration
                ? ['required', 'file', 'image', 'mimes:'.self::IMAGE_MIMES, 'max:10240']
                : ['nullable', 'file', 'image', 'mimes:'.self::IMAGE_MIMES, 'max:10240'],
            'after_sentence_id' => ['nullable', 'integer', $this->anchorSentenceExists()],
        ];
    }

    /**
     * after_sentence_id 0 is the "at the beginning" convention, not a
     * sentence id; every other value must name a real sentence (a
     * wrong-entity id surfaces as a 404 from the placement service).
     */
    private function anchorSentenceExists(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === 0) {
                return; // null = append at the end; 0 = at the beginning
            }

            if (EntitySentence::query()->whereKey((int) $value)->doesntExist()) {
                $fail('The insert position sentence does not exist.');
            }
        };
    }

    public function isIllustrationType(): bool
    {
        $illustrationId = SentenceType::illustrationId();

        return $illustrationId !== null
            && (int) $this->input('sentence_type_id') === $illustrationId;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'The sentence content is required.',
            'content.max' => 'The sentence may not be longer than 65535 characters.',
            'sentence_type_id.required' => 'A sentence type is required.',
            'sentence_type_id.exists' => 'The selected sentence type is invalid.',
            'image.required' => 'An illustration needs an image file.',
            'image.image' => 'The uploaded file is not a valid image.',
            'image.mimes' => 'The image must be a jpg, png, webp or gif file.',
            'image.max' => 'The image may not be larger than 10 MB.',
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
