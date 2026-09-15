<?php

namespace App\Http\Requests;

use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\UserWord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecordWordEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:200'],
            'events.*.row_key' => ['required', 'string', 'max:64', 'regex:/^(mm|es):\d+$/'],
            'events.*.kind' => ['required', 'string', Rule::in([UserWord::KIND_READ, UserWord::KIND_LOOKUP])],
            'events.*.word_ids' => ['required', 'array', 'min:1', 'max:200'],
            'events.*.word_ids.*' => ['required', 'integer', 'exists:words,id'],
        ];
    }

    /**
     * row_key ids must exist — invented keys must not be able to farm reads.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->events ?? [] as $index => $event) {
                if (! is_array($event) || ! isset($event['row_key'])) {
                    continue;
                }

                // Malformed keys are already reported by the regex rule; the
                // exist check below only applies to well-formed ones.
                if (! preg_match('/^(mm|es):(\d+)$/', (string) $event['row_key'], $matches)) {
                    continue;
                }

                [$prefix, $id] = [$matches[1], $matches[2]];

                if ($prefix === 'mm' && ! MeaningMatch::query()->whereKey($id)->exists()) {
                    $validator->errors()->add("events.$index.row_key", 'Invalid row_key.');
                }

                if ($prefix === 'es' && ! EntitySentence::query()->whereKey($id)->exists()) {
                    $validator->errors()->add("events.$index.row_key", 'Invalid row_key.');
                }
            }
        });
    }
}
