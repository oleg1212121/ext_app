<?php

namespace App\Http\Controllers;

use App\Classes\EntityWordLinker;
use App\Classes\WordFamiliarityService;
use App\Http\Requests\RecordWordEventsRequest;
use App\Http\Requests\UpdateWordProgressRequest;
use App\Models\Transcription;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class WordController extends Controller
{
    private const TRANSLATION_LIMIT = 100;

    public function __construct(private readonly WordFamiliarityService $familiarity) {}

    /**
     * Dictionary details for the word popup: every part of speech recorded
     * under the headword (language + l_word) as its own entry — definitions,
     * transcriptions, native-first translations, examples and etymologies.
     * The linked word leads; siblings follow in class-priority order.
     */
    public function show(Request $request, Word $word): JsonResponse
    {
        $headwords = Word::query()
            ->where('language_id', $word->language_id)
            ->where('l_word', $word->l_word)
            ->with([
                'wordClass:id,slug,title',
                'language:id,code',
                'definitions:id,word_id,definition',
                'transcriptions:id,word_id,transcription,transcription_type_id',
                'transcriptions.transcriptionType:id,slug,title',
                'examples:id,word_id,example',
                'etymologies:id,word_id,etymology',
            ])
            ->get();

        $bound = $headwords->firstWhere('id', $word->id) ?? $word;

        $surface = mb_strtolower((string) $request->query('surface', ''));
        $isForm = $surface !== '' && $surface !== $bound->l_word;

        $entries = $headwords
            ->sortBy(fn (Word $entry): array => [
                $entry->id === $word->id ? 0 : 1,
                EntityWordLinker::classPriority($entry->wordClass?->slug ?? ''),
                $entry->wordClass?->title ?? '',
            ])
            ->values()
            ->map(fn (Word $entry): array => $this->entry($entry, $request->user()))
            ->all();

        return response()->json([
            'data' => [
                'id' => $bound->id,
                'word' => $bound->word,
                'language_code' => $bound->language?->code,
                'word_class' => $bound->wordClass?->title,
                'is_form' => $isForm,
                'entries' => $entries,
            ],
        ]);
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
     *
     * @return array{id: int, word_class: string|null, transcriptions: list<array{value: string, type: string|null}>, definitions: list<string>, translations: array, examples: list<string>, etymologies: list<string>}
     */
    private function entry(Word $word, Authenticatable $user): array
    {
        return [
            'id' => $word->id,
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
