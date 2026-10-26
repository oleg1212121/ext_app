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

        // Adaptive retakes: the stored placement level skips the bands
        // below it (all six bands for a first test, level 0).
        $level = $request->user()->vocabulary_level;

        $sample = $language === null ? null : $this->wordTest->sample($language, $level);

        return Inertia::render('WordTest/WordTest', [
            'languages' => $languages
                ->map(fn (Language $language) => ['code' => $language->code, 'name' => $language->name])
                ->values(),
            'language' => $language?->code,
            // The stored level — the page shows it once taken and labels the
            // retake's coverage from sample.groups.
            'level' => $level,
            // null when the language has no CEFR-tagged headwords (unseeded
            // tags or a language without the lists) — no test to take.
            'sample' => $sample,
        ]);
    }

    public function submit(SubmitWordTestRequest $request): JsonResponse
    {
        $known = array_map('intval', $request->array('known'));
        $score = $this->wordTest->score($request->sample(), $known);
        $marked = $this->wordTest->mark((int) $request->user()->id, $request->sample()['language_id'], $score);
        $level = $this->wordTest->persistLevel($request->user(), $score);

        return response()->json(['data' => [
            // This run's computed level; the page's primary number is the
            // stored `level` (post-max), marked is secondary (unchanged).
            'score' => $score,
            'level' => $level,
            'marked' => $marked,
        ]]);
    }
}
