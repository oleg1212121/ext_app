<?php

namespace App\Http\Controllers;

use App\Classes\MeaningMatchPresenter;
use App\Models\EnRuEntityMatch;
use Illuminate\Contracts\View\View;

class AlignmentController extends Controller
{
    public function __construct(
        private readonly MeaningMatchPresenter $presenter,
    ) {}

    public function index(): View
    {
        $entityMatches = EnRuEntityMatch::query()
            ->with(['enEntity', 'ruEntity'])
            ->latest()
            ->paginate(15);

        return view('alignments.index', [
            'entityMatches' => $entityMatches,
        ]);
    }

    public function show(EnRuEntityMatch $entityMatch): View
    {
        $entityMatch->load(['enEntity', 'ruEntity']);

        $meaningMatches = $this->presenter
            ->meaningMatchesQuery($entityMatch)
            ->get();

        return view('alignments.show2', [
            'entityMatch' => $entityMatch,
            'rows' => $this->presenter->toDisplayRows($meaningMatches),
        ]);
    }
}
