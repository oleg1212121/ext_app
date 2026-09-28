<?php

namespace App\Http\Requests;

use App\Models\EntitySentence;
use App\Models\SentenceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEntitySentenceRequest extends FormRequest
{
    private const IMAGE_MIMES = 'jpg,jpeg,png,webp,gif';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The rules follow the sentence's current state (ADR 0050): an
     * illustration keeps its image — caption optional, type pinned, upload
     * optional (replace) — while a plain sentence keeps the text rules and
     * may only become an illustration together with an image file.
     */
    public function rules(): array
    {
        $hasImage = $this->sentence()?->isIllustration() ?? false;
        $becomesIllustration = $this->newTypeIsIllustration();

        $imageRules = ['file', 'image', 'mimes:'.self::IMAGE_MIMES, 'max:10240'];

        return [
            'content' => ($hasImage || $becomesIllustration)
                ? ['nullable', 'string', 'max:65535']
                : ['required', 'string', 'max:65535', $this->nonEmptyString()],
            'sentence_type_id' => $hasImage
                ? ['required', 'integer', Rule::in([SentenceType::illustrationId()])]
                : ['required', 'integer', Rule::exists('sentence_types', 'id')],
            'image' => ($hasImage || $becomesIllustration)
                ? [...($becomesIllustration && ! $hasImage ? ['required'] : ['nullable']), ...$imageRules]
                : ['nullable', ...$imageRules],
        ];
    }

    private function sentence(): ?EntitySentence
    {
        $id = $this->route('sentence');

        return $id === null ? null : EntitySentence::query()->find((int) $id);
    }

    private function newTypeIsIllustration(): bool
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
            'sentence_type_id.in' => 'An illustration sentence keeps its type.',
            'sentence_type_id.exists' => 'The selected sentence type is invalid.',
            'image.required' => 'Becoming an illustration needs an image file.',
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
