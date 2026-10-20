<?php

namespace App\Http\Controllers;

use App\Classes\WordAiEnrichmentService;
use App\Classes\WordFamiliarityService;
use App\Classes\WordFamily;
use App\Classes\WordPopupPayload;
use App\Classes\WordTranslationFetchService;
use App\Classes\WordTranslations\WordTranslationResolver;
use App\Http\Requests\RecordWordEventsRequest;
use App\Http\Requests\UpdateWordProgressRequest;
use App\Http\Requests\WordLookupRequest;
use App\Models\Language;
use App\Models\UserWord;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WordController extends Controller
{
    public function __construct(
        private readonly WordFamiliarityService $familiarity,
        private readonly WordAiEnrichmentService $enrichment,
    ) {}

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
     * The popup's on-open re-resolution (popup-ai-enrich ticket 07): a
     * missing-mode popup resolves its RAW clicked surface against the
     * clicked column's language BEFORE rendering the missing card — a word
     * a previous adopt-on-ask minted (persisted, but never linked into the
     * page's entity_words map; the scheduler that would is `entities:enrich`)
     * resolves to its id, the popup then fetches the full payload through
     * GET /words/{word} above, and an already-asked word opens with its
     * greyed icon instead of a re-ask that could only 409. Read-only by
     * contract (WordAiEnrichmentService::resolveSurface never mints): a
     * miss answers a null word_id and the honest missing card stays. The
     * response is the id ALONE — the payload shape stays owned by the
     * word-show endpoint, nothing is duplicated.
     */
    public function lookup(WordLookupRequest $request): JsonResponse
    {
        $language = Language::query()
            ->where('code', (string) $request->validated('language_code'))
            ->firstOrFail();

        $word = $this->enrichment->resolveSurface((string) $request->validated('surface'), $language);

        return response()->json(['data' => ['word_id' => $word?->id]]);
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
