<?php

namespace App\Http\Controllers;

use App\Classes\AlignmentEditorApiPresenter;
use App\Classes\EntityAccessService;
use App\Http\Requests\AlignmentEditorPageRequest;
use App\Models\EntityMatch;
use App\Models\Work;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the alignment's read-only detail page and its editor. Both live on
 * the work branch (/works/{work}/alignments/{match}[/edit], ADR 0072) — both
 * of the pair's entities belong to one work — and the editor seeds each
 * section's initial page from the URL so a shared link opens exactly the
 * pages the sender saw.
 */
class AlignmentController extends Controller
{
    public function __construct(
        private readonly AlignmentEditorApiPresenter $presenter,
    ) {}

    private function access(): EntityAccessService
    {
        return new EntityAccessService;
    }

    /**
     * The read-only detail page behind the alignment cards: the pair's
     * metadata, the Read / Simulator / Get PDF / Edit actions, and the
     * bilingual text as read-only side-by-side rows paginated on ?page= —
     * viewing only, the editor is reached through its Edit button.
     */
    public function show(Work $work, EntityMatch $entityMatch): Response
    {
        abort_unless($entityMatch->aEntity?->work_id === $work->id, 404);

        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        $entityMatch->load(['aEntity.language', 'bEntity.language', 'aEntity.work.originalLanguage']);

        $rows = $this->presenter->readOnlyRowsPagePayload($entityMatch);

        return Inertia::render('Library/ShowAlignment', [
            'work' => [
                'id' => $entityMatch->aEntity->work->id,
                'title' => $entityMatch->aEntity->work->title,
                'author' => $entityMatch->aEntity->work->author,
            ],
            'match' => $this->presenter->matchPayload($entityMatch),
            'rows' => $rows['rows'],
            'rows_meta' => $rows['meta'],
            'columns' => $rows['columns'],
        ]);
    }

    public function edit(Work $work, EntityMatch $entityMatch, AlignmentEditorPageRequest $request): Response
    {
        abort_unless($entityMatch->aEntity?->work_id === $work->id, 404);

        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        $entityMatch->load(['aEntity.language', 'bEntity.language', 'aEntity.work.originalLanguage']);

        $rows = $this->clampedPagePayload(
            fn (int $page) => $this->presenter->rowsPagePayload($entityMatch, $page, $request->rowsPerPage()),
            $request->rowsPage(),
        );

        return Inertia::render('Alignments/Edit', [
            'match' => $this->presenter->matchPayload($entityMatch),
            'rows' => $rows['rows'],
            'rows_meta' => $rows['meta'],
            'sentences_before' => $rows['sentences_before'],
            'unmatched_a' => $this->clampedPagePayload(
                fn (int $page) => $this->presenter->unmatchedPayload($entityMatch, 'a', $page),
                $request->unmatchedAPage(),
            ),
            'unmatched_b' => $this->clampedPagePayload(
                fn (int $page) => $this->presenter->unmatchedPayload($entityMatch, 'b', $page),
                $request->unmatchedBPage(),
            ),
            'needs_review' => $this->clampedPagePayload(
                fn (int $page) => $this->presenter->needsReviewPagePayload($entityMatch, $page),
                $request->reviewPage(),
            ),
        ]);
    }

    /**
     * Fetch a paged payload, landing a stale or hand-edited ?page= on the
     * nearest valid page (same spirit as ReaderController::paginateRows).
     *
     * @param  callable(int): array{meta: array{last_page: int}}  $fetch
     * @return array{meta: array{last_page: int}}
     */
    private function clampedPagePayload(callable $fetch, int $page): array
    {
        $payload = $fetch($page);

        $lastPage = max((int) $payload['meta']['last_page'], 1);

        return $page > $lastPage ? $fetch($lastPage) : $payload;
    }
}
