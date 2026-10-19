<?php

namespace App\Http\Controllers;

use App\Classes\WordFamiliarityService;
use App\Classes\WordFamily;
use App\Classes\WordPopupPayload;
use App\Classes\WordTranslationFetchService;
use App\Classes\WordTranslations\WordTranslationResolver;
use App\Http\Requests\RecordWordEventsRequest;
use App\Http\Requests\UpdateWordProgressRequest;
use App\Models\UserWord;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WordController extends Controller
{
    public function __construct(private readonly WordFamiliarityService $familiarity) {}

    /**
     * Dictionary details for the word popup: the linked word's word family
     * (ADR 0045) — every part of speech recorded under the linked headword,
     * plus the headword groups of every base word the forms table maps the
     * surface token to, ordered most common headword first. Relay entries
     * ("past participle of the verb melt") are hidden when a base group
     * carries the real content. `tags` (the linked word's tag names) ride
     * along for the popup's "Save to Anki" card (additive). The payload
     * assembles in WordPopupPayload — the one shape the ask-AI enrich
     * response swaps in live must never drift from (shared with
     * POST /ai/word-enrich, popup-ai-enrich ticket 04).
     */
    public function show(Request $request, Word $word): JsonResponse
    {
        // Lowercase + strip combining marks (stress marks, U+0301): stressed
        // surfaces from the reader's stress toggle must compare against the
        // mark-free l_word keys (ADR 0052).
        $surface = preg_replace('/\p{M}/u', '', mb_strtolower((string) $request->query('surface', ''))) ?? '';

        $family = WordFamily::resolve($word, $surface);

        $this->maybeFetchTranslations($word, $family->entries(), $request->user());

        return response()->json([
            'data' => WordPopupPayload::fromFamily($word, $family, $surface, $request->user()),
        ]);
    }

    /**
     * When no part of speech of the popup's word family carries a single
     * translation, quietly queue a provider fetch (Yandex, then Google) into
     * the user's native language. The response stays exactly as it would
     * have been; fetched translations appear the next time the popup opens.
     * The fetch ledger dedupes repeats and permanently skips excluded words.
     */
    private function maybeFetchTranslations(Word $word, Collection $headwords, Authenticatable $user): void
    {
        $nativeLanguage = $user->nativeLanguage();

        if ($nativeLanguage === null
            || (int) $nativeLanguage->id === (int) $word->language_id
            || ! app(WordTranslationResolver::class)->hasProvider()) {
            return;
        }

        $hasTranslations = WordTranslation::query()
            ->where(function ($query) use ($headwords): void {
                $query
                    ->whereIn('word_a_id', $headwords->pluck('id'))
                    ->orWhereIn('word_b_id', $headwords->pluck('id'));
            })
            ->exists();

        if (! $hasTranslations) {
            app(WordTranslationFetchService::class)->dispatchIfEligible($word, $nativeLanguage);
        }
    }

    /**
     * Manual familiarity set from the popup ("I know this word" = 100).
     */
    public function setFamiliarity(UpdateWordProgressRequest $request, Word $word): JsonResponse
    {
        UserWord::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'word_id' => $word->id],
            ['familiarity' => $request->integer('familiarity')],
        );

        return response()->json(['data' => ['familiarity' => $request->integer('familiarity')]]);
    }

    /**
     * Remove the user's progress row; the word goes back to untouched.
     */
    public function resetProgress(Request $request, Word $word): JsonResponse
    {
        UserWord::query()
            ->where('user_id', $request->user()->id)
            ->where('word_id', $word->id)
            ->delete();

        return response()->json(['data' => ['familiarity' => null]]);
    }

    /**
     * Record read/lookup events (ledger-deduplicated) and return the
     * resulting familiarity per touched word.
     */
    public function recordEvents(RecordWordEventsRequest $request): JsonResponse
    {
        $familiarity = $this->familiarity->applyEvents(
            (int) $request->user()->id,
            $request->validated('events'),
        );

        return response()->json(['data' => ['familiarity' => $familiarity]]);
    }
}
