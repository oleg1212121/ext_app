<?php

namespace App\Classes;

use App\Models\Transcription;
use App\Models\Word;
use App\Models\WordAiFetch;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;

/**
 * The word popup's JSON payload — the ONE assembler both endpoints share:
 * GET /words/{word} (the popup's initial fetch) and POST /ai/word-enrich
 * (whose response the popup swaps in live, so its shape must never drift
 * from the initial fetch's). The word family resolves through
 * {@see WordFamily::resolve} — which also applies the lazy completeness
 * recalc — and the bound headword's succeeded ask-AI ledger row rides along
 * as `ai_asked` (popup-ai-enrich ticket 04): the toolbar's grey-icon flag.
 */
final class WordPopupPayload
{
    private const TRANSLATION_LIMIT = 100;

    public static function build(Word $linked, string $surface, Authenticatable $user): array
    {
        return self::fromFamily($linked, WordFamily::resolve($linked, $surface), $surface, $user);
    }

    /**
     * @return array{id: int, word: string, language_code: string|null, word_class: string|null, is_form: bool, form_of: list<string>, frequency: int|null, tags: list<string>, entries: list<array<string, mixed>>, ai_asked: bool}
     */
    public static function fromFamily(Word $linked, WordFamily $family, string $surface, Authenticatable $user): array
    {
        $headwords = $family->entries();

        $bound = $headwords->firstWhere('id', $linked->id) ?? $linked;
        $isForm = $surface !== '' && $surface !== $bound->l_word;

        $entries = $headwords
            ->map(fn (Word $entry): array => self::entry($entry, $user))
            ->all();

        return [
            'id' => $bound->id,
            'word' => $bound->word,
            'language_code' => $bound->language?->code,
            'word_class' => $bound->wordClass?->title,
            'is_form' => $isForm,
            'form_of' => $family->formOf(),
            'frequency' => self::frequencyRank($bound),
            'tags' => $bound->tags->pluck('name')->all(),
            'entries' => $entries,
            'ai_asked' => WordAiFetch::succeededFor((int) $bound->id),
        ];
    }

    /**
     * One popup section: a single part of speech with all of its satellites.
     * The headword travels along so the UI can label sections that belong to
     * a base word of the family rather than the popup's own headword.
     *
     * @return array{id: int, word: string, word_class: string|null, transcriptions: list<array{value: string, type: string|null}>, definitions: list<string>, translations: array, examples: list<string>, etymologies: list<string>}
     */
    private static function entry(Word $word, Authenticatable $user): array
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
            'translations' => self::translations($word, $user)->all(),
            'examples' => $word->examples->pluck('example')->all(),
            'etymologies' => $word->etymologies->pluck('etymology')->all(),
        ];
    }

    /**
     * Native language first, then the rest by language and word.
     *
     * @return Collection<int, array{id: int, word: string, language_code: string|null}>
     */
    private static function translations(Word $word, Authenticatable $user): Collection
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

    /**
     * The headword's frequency rank for the popup's frequency line (lower =
     * more common). Null when no frequency list carries the word — the line
     * hides for it.
     */
    private static function frequencyRank(Word $word): ?int
    {
        if ($word->frequency === null) {
            return null;
        }

        $rank = (int) round((float) $word->frequency);

        return $rank >= Word::FREQUENCY_UNRANKED ? null : $rank;
    }
}
