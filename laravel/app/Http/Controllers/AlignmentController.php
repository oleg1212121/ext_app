<?php

namespace App\Http\Controllers;

use App\Classes\AlignmentEditorApiPresenter;
use App\Classes\EntityAccessService;
use App\Models\EntityMatch;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the alignment editor page. The browse/create surface lives under
 * each work (/works/{work}/alignments, ADR 0036/0039); only the editor
 * route stays global.
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

    public function show(EntityMatch $entityMatch): Response
    {
        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        $entityMatch->load(['aEntity.language', 'bEntity.language', 'aEntity.work.originalLanguage']);

        $payload = $this->presenter->rowsPagePayload($entityMatch, 1, 25);

        return Inertia::render('Alignments/Show', [
            'match' => $this->presenter->matchPayload($entityMatch),
            'rows' => $payload['rows'],
            'rows_meta' => $payload['meta'],
            'sentences_before' => $payload['sentences_before'],
            'unmatched_a' => $this->presenter->unmatchedPayload($entityMatch, 'a', 1),
            'unmatched_b' => $this->presenter->unmatchedPayload($entityMatch, 'b', 1),
            'needs_review' => $this->presenter->needsReviewPagePayload($entityMatch, 1),
        ]);
    }
}
