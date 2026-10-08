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
 * Renders the alignment editor page. It lives on the work branch
 * (/works/{work}/alignments/{match}/edit, ADR 0072) — both of the pair's
 * entities belong to one work — and seeds each section's initial page from
 * the URL so a shared link opens exactly the pages the sender saw.
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

    public function show(Work $work, EntityMatch $entityMatch, AlignmentEditorPageRequest $request): Response
    {
        abort_unless($entityMatch->aEntity?->work_id === $work->id, 404);

        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        $entityMatch->load(['aEntity.language', 'bEntity.language', 'aEntity.work.originalLanguage']);

        $rows = $this->clampedPagePayload(
            fn (int $page) => $this->presenter->rowsPagePayload($entityMatch, $page, $request->rowsPerPage()),
            $request->rowsPage(),
        );

        return Inertia::render('Alignments/Show', [
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
