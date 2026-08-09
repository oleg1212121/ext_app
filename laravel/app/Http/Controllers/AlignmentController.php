<?php

namespace App\Http\Controllers;

use App\Classes\AlignmentEditorApiPresenter;
use App\Models\EnRuEntityMatch;
use Inertia\Inertia;
use Inertia\Response;

class AlignmentController extends Controller
{
    public function __construct(
        private readonly AlignmentEditorApiPresenter $presenter,
    ) {}

    public function index(): Response
    {
        $entityMatches = EnRuEntityMatch::query()
            ->with(['enEntity', 'ruEntity'])
            ->latest()
            ->paginate(15);

        return Inertia::render('Alignments/Index', [
            'entityMatches' => $entityMatches->through(
                fn (EnRuEntityMatch $entityMatch): array => $this->presenter->matchPayload($entityMatch),
            )->items(),
            'meta' => [
                'current_page' => $entityMatches->currentPage(),
                'last_page' => $entityMatches->lastPage(),
                'total' => $entityMatches->total(),
                'per_page' => $entityMatches->perPage(),
            ],
        ]);
    }

    public function show(EnRuEntityMatch $entityMatch): Response
    {
        $entityMatch->load(['enEntity', 'ruEntity']);

        $payload = $this->presenter->rowsPagePayload($entityMatch, 1, 25);

        return Inertia::render('Alignments/Show', [
            'match' => $this->presenter->matchPayload($entityMatch),
            'rows' => $payload['rows'],
            'rows_meta' => $payload['meta'],
            'unmatched_en' => $this->presenter->unmatchedPayload($entityMatch, 'en', 1),
            'unmatched_ru' => $this->presenter->unmatchedPayload($entityMatch, 'ru', 1),
        ]);
    }
}
