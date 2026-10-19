<?php

namespace App\Classes;

use App\Models\Definition;
use App\Models\Etymology;
use App\Models\Example;
use App\Models\Language;
use App\Models\Transcription;
use App\Models\TranscriptionType;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use App\Support\EnrichmentReply;
use App\Support\StagedTranslations;
use App\Support\StressMarks;
use App\Support\TranslationTargets;
use App\Support\WordLookupKey;
use Illuminate\Support\Facades\DB;

/**
 * The ask-AI word enrichment's persistence (popup-ai-enrich ticket 04): a
 * parsed reply merges into the word's content in ONE transaction — exact-text
 * deduped per table, every new row carrying `source = 'ai'` (null stays
 * imported). Translations stay link-only (ADR 0078): candidates resolve
 * against the native language's EXISTING words through the shared
 * TranslationTargets resolver and link as word_translations pairs; the
 * unmatched stage into the word's translations JSON. ZERO word rows are ever
 * created here — the method returns whether anything new landed at all, so
 * the endpoint can finalize the ledger as succeeded (lock) or a retryable
 * failure.
 */
class WordAiEnrichmentService
{
    /**
     * Parse a raw model reply and merge it. @see applyParsed().
     */
    public function apply(Word $word, string $raw, ?Language $nativeLanguage): bool
    {
        $parsed = EnrichmentReply::parse($raw);

        return $parsed === null ? false : $this->applyParsed($word, $parsed, $nativeLanguage);
    }

    /**
     * Merge a parsed reply (EnrichmentReply::parse output) into the word.
     *
     * @param  array<string, list<string>>  $parsed
     */
    public function applyParsed(Word $word, array $parsed, ?Language $nativeLanguage): bool
    {
        $usable = false;

        DB::transaction(function () use ($word, $parsed, $nativeLanguage, &$usable): void {
            foreach ($parsed['definitions'] as $text) {
                $exists = Definition::query()
                    ->where('word_id', $word->id)
                    ->where('definition', $text)
                    ->exists();

                if (! $exists) {
                    Definition::query()->create(['word_id' => $word->id, 'definition' => $text, 'source' => 'ai']);
                    $usable = true;
                }
            }

            // Transcriptions carry a NOT NULL per-language type FK: the
            // language's IPA row when it has one, else its first type — a
            // language with no types at all gets no AI transcriptions (the
            // taxonomy is seeded, never minted from an AI answer).
            $typeId = $this->transcriptionTypeId($word);

            if ($typeId !== null) {
                foreach ($parsed['transcriptions'] as $text) {
                    $exists = Transcription::query()
                        ->where('word_id', $word->id)
                        ->where('transcription', $text)
                        ->where('transcription_type_id', $typeId)
                        ->exists();

                    if (! $exists) {
                        Transcription::query()->create([
                            'word_id' => $word->id,
                            'transcription' => $text,
                            'transcription_type_id' => $typeId,
                            'source' => 'ai',
                        ]);
                        $usable = true;
                    }
                }
            }

            // The examples table enforces unique (example, word_id) —
            // firstOrCreate IS the dedup, and source='ai' lands on the
            // created row only.
            foreach ($parsed['examples'] as $text) {
                $created = Example::query()->firstOrCreate(
                    ['word_id' => $word->id, 'example' => $text],
                    ['source' => 'ai'],
                );

                if ($created->wasRecentlyCreated) {
                    $usable = true;
                }
            }

            foreach ($parsed['etymologies'] as $text) {
                $exists = Etymology::query()
                    ->where('word_id', $word->id)
                    ->where('etymology', $text)
                    ->exists();

                if (! $exists) {
                    Etymology::query()->create(['word_id' => $word->id, 'etymology' => $text, 'source' => 'ai']);
                    $usable = true;
                }
            }

            if ($nativeLanguage !== null && (int) $nativeLanguage->id !== (int) $word->language_id) {
                $usable = $this->linkOrStageTranslations($word, $parsed['translations'], $nativeLanguage) || $usable;
            }
        });

        return $usable;
    }

    /**
     * The word's language transcription type for AI rows: IPA first, else the
     * language's first type, else null (skip transcriptions entirely).
     */
    private function transcriptionTypeId(Word $word): ?int
    {
        return TranscriptionType::query()
            ->where('language_id', $word->language_id)
            ->orderByRaw('CASE WHEN slug = ? THEN 0 ELSE 1 END', ['ipa'])
            ->orderBy('id')
            ->value('id');
    }

    /**
     * The link-only translation pass, mirroring FetchWordTranslations:
     * candidates normalize (trim, stress marks stripped for the target
     * language's rule), the over-long and sentence-punctuated drop, then the
     * shared resolver matches them against the native language's EXISTING
     * rows — matches link, the rest stages. True only when something NEW
     * landed (a fresh link or a staged string the JSON did not carry yet).
     *
     * @param  list<string>  $candidates
     */
    private function linkOrStageTranslations(Word $word, array $candidates, Language $nativeLanguage): bool
    {
        $strip = StressMarks::stripsFor($nativeLanguage->code);
        $usableCandidates = [];

        foreach ($candidates as $candidate) {
            $text = trim($strip ? StressMarks::strip($candidate) : $candidate);

            if ($text === ''
                || mb_strlen($text) > 256
                || preg_match('/[.,;:]/', $text) === 1) {
                continue;
            }

            $usableCandidates[] = $text;
        }

        if ($usableCandidates === []) {
            return false;
        }

        $sourceSlug = $word->word_class_id !== null
            ? WordClass::query()->whereKey($word->word_class_id)->value('slug')
            : null;

        $resolved = TranslationTargets::forLanguage(
            $nativeLanguage->id,
            TranslationTargets::wantedClasses(
                $usableCandidates,
                WordClass::query()
                    ->where('language_id', $nativeLanguage->id)
                    ->orderBy('id')
                    ->pluck('id', 'slug')
                    ->all(),
                $sourceSlug !== null ? (string) $sourceSlug : null,
            ),
        );

        $usable = false;

        foreach ($resolved as $targetWordId) {
            if (WordTranslation::link((int) $word->id, $targetWordId)->wasRecentlyCreated) {
                $usable = true;
            }
        }

        $staged = [];
        foreach ($usableCandidates as $text) {
            $normal = WordLookupKey::normalize($text);

            if (! isset($resolved[$normal]) && ! isset($staged[$normal])) {
                $staged[$normal] = $text;
            }
        }

        if ($staged !== []) {
            $merged = StagedTranslations::merge($word->translations, array_values($staged));

            if ($merged !== $word->translations) {
                $word->forceFill(['translations' => $merged])->save();
                $usable = true;
            }
        }

        return $usable;
    }
}
