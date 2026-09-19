<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReaderPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Deliberately rule-free: ?page comes from shareable URLs and stale
     * bookmarks, so junk values normalize instead of failing validation —
     * page() coerces anything non-numeric or sub-1 back to page 1 and the
     * controller clamps the ceiling to the last page. Same tolerant spirit
     * as the controller's font-size normalization.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function page(): int
    {
        return max(1, (int) $this->input('page', 1));
    }
}
