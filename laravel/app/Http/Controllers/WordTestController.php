<?php

namespace App\Http\Controllers;

use App\Classes\WordTestService;
use App\Http\Requests\SubmitWordTestRequest;
use App\Http\Requests\WordTestIndexRequest;
use App\Models\Language;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class WordTestController extends Controller
{
    public function __construct(private readonly WordTestService $wordTest) {}

    public function index(WordTestIndexRequest $request): Response
    {
        $languages = Language::query()
            ->enabled()
            ->orderBy('sort_order')
            ->get(['id', 'code', 'name']);

        // The test defaults to English; ?lang= switches (validated enabled).
        $language = $languages->firstWhere('code', $request->input('lang', 'en'))
            ?? $languages->first();

        $sample = $language === null ? null : $this->wordTest->sample($language);

        return Inertia::render('WordTest/WordTest', [
            'languages' => $languages
                ->map(fn (Language $language) => ['code' => $language->code, 'name' => $language->name])
                ->values(),
            'language' => $language?->code,
            // null when the language has no ranked word list yet (ADR 0070
            // band ties aside, a language without imports has no inventory).
            'sample' => $sample,
        ]);
    }

    public function submit(SubmitWordTestRequest $request): JsonResponse
    {
        $known = array_map('intval', $request->array('known'));
        $score = $this->wordTest->score($request->sample(), $known);
        $marked = $this->wordTest->mark((int) $request->user()->id, $request->sample()['language_id'], $score);

        return response()->json(['data' => [
            'score' => $score,
            'marked' => $marked,
        ]]);
    }
}
