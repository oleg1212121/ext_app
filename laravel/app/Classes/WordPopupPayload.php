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
 * recalc — and the bound headword's ever-succeeded ask-AI ledger rides along
 * as `ai_asked` (popup-ai-enrich ticket 04): the toolbar flag that turns the
 * plain ask icon into the re-ask variant (ADR 0082 amendment).
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
     * @return array{id: int, word: string, word_class: string|null, transcriptions: list<array{value: string, type: string|null}>, definitions: list<string>, translations: array, staged_translations: list<string>, examples: list<string>, etymologies: list<string>}
     */
    private static function entry(Word $word, Authenticatable $user): array
    {
        $linked = self::translations($word, $user);

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
            'translations' => $linked->all(),
            'staged_translations' => self::stagedTranslations($word, $linked),
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
     * The entry's staged raw translation candidates — the `words.translations`
     * JSON the importers and translation fetches stage into (ADR 0085) —
     * surfaced language-less, as-is, under the linked translations. A staged
     * string that only restates a linked display word (case-insensitively)
     * is dropped, and so is a case twin of an earlier staged string (first
     * occurrence stands); stored order is kept, capped at the linked line's
     * limit. Empty when the word stages nothing — the raw line hides.
     *
     * @param  Collection<int, array{id: int, word: string, language_code: string|null}>  $linked
     * @return list<string>
     */
    private static function stagedTranslations(Word $word, Collection $linked): array
    {
        $linkedWords = $linked
            ->map(fn (array $translation): string => mb_strtolower($translation['word']))
            ->all();

        return collect($word->translations ?? [])
            ->filter(fn (mixed $candidate): bool => is_string($candidate) && $candidate !== '')
            ->reject(fn (string $candidate): bool => in_array(mb_strtolower($candidate), $linkedWords, true))
            ->unique(fn (string $candidate): string => mb_strtolower($candidate))
            ->values()
            ->take(self::TRANSLATION_LIMIT)
            ->all();
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
