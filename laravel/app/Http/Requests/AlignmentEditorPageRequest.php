<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The editor page's URL-seeded pagination (ADR 0072): rows_page,
 * rows_per_page, unmatched_a_page, unmatched_b_page and review_page name the
 * page each section opens on, so a page view can be copied and shared.
 * Out-of-range pages are clamped by the controller, not rejected here.
 */
class AlignmentEditorPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'rows_page' => ['nullable', 'integer', 'min:1'],
            'rows_per_page' => ['nullable', 'integer', 'in:'.implode(',', RowsRequest::PER_PAGE_OPTIONS)],
            'unmatched_a_page' => ['nullable', 'integer', 'min:1'],
            'unmatched_b_page' => ['nullable', 'integer', 'min:1'],
            'review_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function rowsPage(): int
    {
        return (int) ($this->validated('rows_page') ?? 1);
    }

    public function rowsPerPage(): int
    {
        return (int) ($this->validated('rows_per_page') ?? 25);
    }

    public function unmatchedAPage(): int
    {
        return (int) ($this->validated('unmatched_a_page') ?? 1);
    }

    public function unmatchedBPage(): int
    {
        return (int) ($this->validated('unmatched_b_page') ?? 1);
    }

    public function reviewPage(): int
    {
        return (int) ($this->validated('review_page') ?? 1);
    }
}
