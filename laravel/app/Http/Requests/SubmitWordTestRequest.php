<?php

namespace App\Http\Requests;

use App\Classes\WordTestService;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class SubmitWordTestRequest extends FormRequest
{
    /**
     * The cached sample the validated token resolves to; the token rule
     * resolves it once and later rules and the controller reuse it.
     *
     * @var array{language_id: int, buckets: array<int, array<int, int>>, all_word_ids: array<int, int>}|null
     */
    private ?array $sample = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string|Closure>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                $sample = app(WordTestService::class)->resolveSample((string) $value);

                if ($sample === null) {
                    $fail(__('wordtest.validation.expired'));

                    return;
                }

                $this->sample = $sample;
            }],
            'known' => ['present', 'array'],
            'known.*' => ['integer', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->sample !== null && ! in_array((int) $value, $this->sample['all_word_ids'], true)) {
                    $fail(__('wordtest.validation.foreign_word'));
                }
            }],
        ];
    }

    /**
     * The sample behind the submitted token (null when the token was stale).
     *
     * @return array{language_id: int, buckets: array<int, array<int, int>>, all_word_ids: array<int, int>}|null
     */
    public function sample(): ?array
    {
        return $this->sample;
    }
}
