<?php

namespace App\Http\Controllers;

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

    /**
     * Dictionary details for the word popup: definitions, transcriptions,
     * native-first translations and examples.
     */
    public function show(Request $request, Word $word): JsonResponse
    {
        $word->load([
            'wordClass:id,slug,title',
            'language:id,code',
            'definitions:id,word_id,definition',
            'transcriptions:id,word_id,transcription,transcription_type_id',
            'transcriptions.transcriptionType:id,slug,title',
            'examples:id,word_id,example',
        ]);

        $surface = mb_strtolower((string) $request->query('surface', ''));
        $isForm = $surface !== '' && $surface !== $word->l_word;

        return response()->json([
            'data' => [
                'id' => $word->id,
                'word' => $word->word,
                'language_code' => $word->language?->code,
                'word_class' => $word->wordClass?->title,
                'is_form' => $isForm,
                'transcriptions' => $word->transcriptions
                    ->map(fn (Transcription $transcription): array => [
                        'value' => $transcription->transcription,
                        'type' => $transcription->transcriptionType?->title,
                    ])
                    ->values()
                    ->all(),
                'definitions' => $word->definitions->pluck('definition')->all(),
                'translations' => $this->translations($word, $request->user())->all(),
                'examples' => $word->examples->pluck('example')->all(),
            ],
        ]);
    }

    /**
     * "I know this word" — mark the word known for the current user.
     */
    public function markKnown(UpdateWordProgressRequest $request, Word $word): JsonResponse
    {
        UserWord::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'word_id' => $word->id],
            ['status' => UserWord::STATUS_KNOWN],
        );

        return response()->json(['data' => ['status' => UserWord::STATUS_KNOWN]]);
    }

    /**
     * Remove the user's progress mark; the word goes back to unknown.
     */
    public function resetProgress(Request $request, Word $word): JsonResponse
    {
        UserWord::query()
            ->where('user_id', $request->user()->id)
            ->where('word_id', $word->id)
            ->delete();

        return response()->json(['data' => ['status' => null]]);
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
