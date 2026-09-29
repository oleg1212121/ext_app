<?php

namespace App\Http\Controllers;

use App\Classes\WordFamiliarityService;
use App\Classes\WordFamily;
use App\Classes\WordTranslationFetchService;
use App\Classes\WordTranslations\WordTranslationResolver;
use App\Http\Requests\RecordWordEventsRequest;
use App\Http\Requests\UpdateWordProgressRequest;
use App\Models\Transcription;
use App\Models\UserWord;
use App\Models\Word;
use App\Models\WordTranslation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WordController extends Controller
{
    private const TRANSLATION_LIMIT = 100;

    public function __construct(private readonly WordFamiliarityService $familiarity) {}

    /**
     * Dictionary details for the word popup: the linked word's word family
     * (ADR 0045) — every part of speech recorded under the linked headword,
     * plus the headword groups of every base word the forms table maps the
     * surface token to, ordered most common headword first. Relay entries
     * ("past participle of the verb melt") are hidden when a base group
     * carries the real content.
     */
    public function show(Request $request, Word $word): JsonResponse
    {
        $surface = mb_strtolower((string) $request->query('surface', ''));

        $family = WordFamily::resolve($word, $surface);
        $headwords = $family->entries();

        $bound = $headwords->firstWhere('id', $word->id) ?? $word;
        $isForm = $surface !== '' && $surface !== $bound->l_word;

        $entries = $headwords
            ->map(fn (Word $entry): array => $this->entry($entry, $request->user()))
            ->all();

        $this->maybeFetchTranslations($word, $headwords, $request->user());

        return response()->json([
            'data' => [
                'id' => $bound->id,
                'word' => $bound->word,
                'language_code' => $bound->language?->code,
                'word_class' => $bound->wordClass?->title,
                'is_form' => $isForm,
                'form_of' => $family->formOf(),
                'frequency' => $this->frequencyRank($bound),
                'entries' => $entries,
            ],
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
     * The headword's frequency rank for the popup's frequency line (lower =
     * more common). Null when no frequency list carries the word — the line
     * hides for it.
     */
    private function frequencyRank(Word $word): ?int
    {
        if ($word->frequency === null) {
            return null;
        }

        $rank = (int) round((float) $word->frequency);

        return $rank >= Word::FREQUENCY_UNRANKED ? null : $rank;
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

    /**
     * One popup section: a single part of speech with all of its satellites.
     * The headword travels along so the UI can label sections that belong to
     * a base word of the family rather than the popup's own headword.
     *
     * @return array{id: int, word: string, word_class: string|null, transcriptions: list<array{value: string, type: string|null}>, definitions: list<string>, translations: array, examples: list<string>, etymologies: list<string>}
     */
    private function entry(Word $word, Authenticatable $user): array
    {
        return [
            'id' => $word->id,
            'word' => $word->word,
            'word_class' => $word->wordClass?->title,
            'transcriptions' => $word->transcriptions
                ->map(fn (Transcription $transcription): array => [
                    'value' => $transcription->transcription,
                    'type' => $transcription->transcriptionType?->title,
                ])
                ->values()
                ->all(),
            'definitions' => $word->definitions->pluck('definition')->all(),
            'translations' => $this->translations($word, $user)->all(),
            'examples' => $word->examples->pluck('example')->all(),
            'etymologies' => $word->etymologies->pluck('etymology')->all(),
        ];
    }

    /**
     * Native language first, then the rest by language and word.
     *
     * @return Collection<int, array{id: int, word: string, language_code: string|null}>
     */
    private function translations(Word $word, Authenticatable $user): Collection
    {
        $nativeLanguageId = $user->nativeLanguage()?->id;

        return $word->translationWords()
            ->load('language:id,code')
            ->sortByDesc(fn (Word $translation): int => (int) $nativeLanguageId !== null && (int) $translation->language_id === $nativeLanguageId)
            ->values()
            ->take(self::TRANSLATION_LIMIT)
            ->map(fn (Word $translation): array => [
                'id' => $translation->id,
                'word' => $translation->word,
                'language_code' => $translation->language?->code,
            ]);
    }
}
