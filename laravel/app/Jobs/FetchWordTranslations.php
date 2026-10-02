<?php

namespace App\Jobs;

use App\Classes\WordTranslations\WordTranslationResolver;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use App\Models\WordTranslationFetch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Queue(QueueLane::DEFAULT)]
class FetchWordTranslations implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 3;

    public function __construct(
        public readonly int $wordId,
        public readonly int $targetLanguageId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->wordId}:{$this->targetLanguageId}";
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(WordTranslationResolver $resolver): void
    {
        $word = Word::query()->with('language')->find($this->wordId);
        $target = Language::query()->find($this->targetLanguageId);

        if ($word === null || $target === null) {
            Log::info('FetchWordTranslations skipped: word or target language missing', [
                'word_id' => $this->wordId,
                'target_language_id' => $this->targetLanguageId,
            ]);

            return;
        }

        $record = WordTranslationFetch::query()
            ->firstOrCreate(['word_id' => $word->id, 'target_language_id' => $target->id]);

        // Queue-level retries of a failed attempt must proceed, so only the
        // terminal states stop the job here; new dispatches are gated by the
        // service's cooldown and attempt-cap rules instead.
        if (! $record->wasRecentlyCreated
            && in_array($record->status, [WordTranslationFetch::STATUS_SUCCEEDED, WordTranslationFetch::STATUS_EMPTY], true)) {
            return;
        }

        $record->forceFill([
            'status' => WordTranslationFetch::STATUS_PENDING,
            'attempts' => $record->attempts + 1,
            'last_attempted_at' => now(),
        ])->save();

        try {
            $result = $resolver->fetch($word->l_word ?: $word->word, $word->language->code, $target->code);
            $candidates = $this->usableCandidates($result['candidates']);

            if ($candidates === []) {
                // Every provider answered "no translation": the exclusion.
                $record->forceFill([
                    'status' => WordTranslationFetch::STATUS_EMPTY,
                    'provider' => $result['provider'],
                ])->save();

                return;
            }

            $classes = $this->classContext($target, $word);
            $linked = 0;

            foreach ($candidates as $candidate) {
                // Multi-word candidates are phrases, not inflected parts of
                // speech: storing them under the provider pos is what once
                // created junk verb rows like "do it" that polluted the
                // phrasal-verb lexicon (ADR 0059).
                $classId = str_contains($candidate['text'], ' ')
                    ? $this->phraseClassId($target, $classes)
                    : $this->classIdFor($candidate['pos'], $classes);

                if ($classId === null) {
                    continue;
                }

                $targetWord = Word::query()->firstOrCreate(
                    [
                        'word' => $candidate['text'],
                        'language_id' => $target->id,
                        'word_class_id' => $classId,
                    ],
                    ['l_word' => mb_strtolower($candidate['text'])],
                );

                WordTranslation::link((int) $word->id, (int) $targetWord->id);
                $linked++;
            }

            $record->forceFill([
                'status' => WordTranslationFetch::STATUS_SUCCEEDED,
                'provider' => $result['provider'],
            ])->save();

            Log::info('FetchWordTranslations completed', [
                'word_id' => $word->id,
                'target_language_id' => $target->id,
                'provider' => $result['provider'],
                'linked' => $linked,
            ]);
        } catch (Throwable $exception) {
            $record->forceFill(['status' => WordTranslationFetch::STATUS_FAILED])->save();

            throw $exception;
        }
    }

    /**
     * Drop candidates that could not become dictionary rows: overlong text
     * or sentence punctuation (a provider occasionally answers with a full
     * clause).
     *
     * @param  list<array{text: string, pos: string|null}>  $candidates
     * @return list<array{text: string, pos: string|null}>
     */
    private function usableCandidates(array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            fn (array $candidate): bool => mb_strlen($candidate['text']) <= 256
                && preg_match('/[.,;:]/', $candidate['text']) !== 1,
        ));
    }

    /**
     * Class-resolution context for created target words: the target
     * language's classes by slug, the source word's own class mapped into
     * the target language (slugs are shared across languages), and the
     * target language's first class as the last resort.
     *
     * @return array{bySlug: array<string, int>, fromSource: int|null, fallback: int|null}
     */
    private function classContext(Language $target, Word $word): array
    {
        $bySlug = WordClass::query()
            ->where('language_id', $target->id)
            ->orderBy('id')
            ->pluck('id', 'slug')
            ->toArray();

        $sourceSlug = WordClass::query()->whereKey($word->word_class_id)->value('slug');

        return [
            'bySlug' => $bySlug,
            'fromSource' => $sourceSlug !== null ? ($bySlug[$sourceSlug] ?? null) : null,
            'fallback' => $bySlug === [] ? null : (int) reset($bySlug),
        ];
    }

    /**
     * @param  array{bySlug: array<string, int>, fromSource: int|null, fallback: int|null}  $classes
     */
    private function classIdFor(?string $pos, array $classes): ?int
    {
        if ($pos !== null && isset($classes['bySlug'][$pos])) {
            return (int) $classes['bySlug'][$pos];
        }

        return $classes['fromSource'] ?? $classes['fallback'];
    }

    /**
     * The target language's phrase class, auto-created after the import's
     * unseen-class convention when the language has none yet; null only
     * when even the language row is gone.
     *
     * @param  array{bySlug: array<string, int>, fromSource: int|null, fallback: int|null}  $classes
     */
    private function phraseClassId(Language $target, array $classes): ?int
    {
        if (isset($classes['bySlug']['phrase'])) {
            return (int) $classes['bySlug']['phrase'];
        }

        $class = WordClass::query()->firstOrCreate(
            ['language_id' => $target->id, 'slug' => 'phrase'],
            ['title' => 'phrase'],
        );

        return $class->id;
    }
}
