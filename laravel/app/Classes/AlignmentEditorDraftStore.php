<?php

namespace App\Classes;

use Illuminate\Support\Facades\Session;

class AlignmentEditorDraftStore
{
    public function sessionKey(int $entityMatchId, int $userId): string
    {
        return "alignment_editor_draft.{$userId}.{$entityMatchId}";
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(int $entityMatchId, int $userId): ?array
    {
        $draft = Session::get($this->sessionKey($entityMatchId, $userId));

        return is_array($draft) ? $draft : null;
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function put(int $entityMatchId, int $userId, array $draft): void
    {
        Session::put($this->sessionKey($entityMatchId, $userId), $draft);
    }

    public function forget(int $entityMatchId, int $userId): void
    {
        Session::forget($this->sessionKey($entityMatchId, $userId));
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{rows: list<array<string, mixed>>, total: int, last_page: int}
     */
    public function paginateMeaningRows(array $draft, int $page, int $perPage): array
    {
        $rows = $draft['meaning_rows'] ?? [];
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'rows' => array_slice($rows, $offset, $perPage),
            'total' => $total,
            'last_page' => $lastPage,
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array{rows: list<array<string, mixed>>, total: int, last_page: int}
     */
    public function paginateUnmatched(array $draft, string $lang, int $page, int $perPage): array
    {
        $key = $lang === 'en' ? 'unmatched_en' : 'unmatched_ru';
        $rows = $draft[$key] ?? [];
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'rows' => array_slice($rows, $offset, $perPage),
            'total' => $total,
            'last_page' => $lastPage,
        ];
    }
}
