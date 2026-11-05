<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RecordWordOpensRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The popup-open penalty payload (word-popup-overhaul ticket 01): the
     * dictionary word ids the opened popup resolved to. A mapped open posts
     * its one id; a missing-mode popup posts the id its on-open lookup
     * resolved to (a word that stays missing costs nothing — there is no
     * id to post). Bounded like the events list it sits beside; every id
     * must exist — invented ids must not be able to move scores.
     *
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'word_ids' => ['required', 'array', 'min:1', 'max:200'],
            'word_ids.*' => ['required', 'integer', 'exists:words,id'],
        ];
    }
}
