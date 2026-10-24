<?php

namespace App\Jobs;

use App\Classes\WordTranslations\WordTranslationResolver;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordClass;
use App\Models\WordTranslation;
use App\Models\WordTranslationFetch;
use App\Support\StagedTranslations;
use App\Support\StressMarks;
use App\Support\TranslationTargets;
use App\Support\WordLookupKey;
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
            $candidates = $this->usableCandidates($result['candidates'], $target->code);

            if ($candidates === []) {
                // Every provider answered "no translation" (or nothing usable
                // survived): the exclusion.
                $record->forceFill([
                    'status' => WordTranslationFetch::STATUS_EMPTY,
                    'provider' => $result['provider'],
                    'linked_count' => 0,
                    'staged_count' => 0,
                ])->save();

                return;
            }

            // Link-only (ADR 0078): a candidate never creates its target row.
            // The candidates resolve against the target language's EXISTING
            // words through the shared resolver; matches become
            // word_translations pairs, the rest stays staged in the source
            // word's translations JSON for the later link pass.
            $resolved = TranslationTargets::forLanguage(
                $target->id,
                $this->wantedClasses($candidates, $target, $word),
            );

            $linked = 0;
            foreach ($resolved as $targetWordId) {
                WordTranslation::link((int) $word->id, $targetWordId);
                $linked++;
            }

            $staged = $this->stageUnmatched($word, $candidates, $resolved);

            $record->forceFill([
                'status' => WordTranslationFetch::STATUS_SUCCEEDED,
                'provider' => $result['provider'],
                'linked_count' => $linked,
                'staged_count' => $staged,
            ])->save();

            Log::info('FetchWordTranslations completed', [
                'word_id' => $word->id,
                'target_language_id' => $target->id,
                'provider' => $result['provider'],
                'linked' => $linked,
                'staged' => $staged,
            ]);
        } catch (Throwable $exception) {
            $record->forceFill(['status' => WordTranslationFetch::STATUS_FAILED])->save();

            throw $exception;
        }
    }

    /**
     * Normalize and drop unusable candidates. The text is trimmed and
     * stripped of the combining stress marks for the Cyrillic target
     * languages HERE — the providers strip too, but the job must not depend
     * on a future provider keeping that promise — then overlong text and
     * sentence punctuation (a provider occasionally answers with a full
     * clause) are dropped. The normalized text is what resolves, links and
     * stages; the stored staged strings never carry marks.
     *
     * @param  list<array{text: string, pos: string|null}>  $candidates
     * @return list<array{text: string, pos: string|null}>
     */
    private function usableCandidates(array $candidates, string $targetCode): array
    {
        $strip = StressMarks::stripsFor($targetCode);
        $usable = [];

        foreach ($candidates as $candidate) {
            $text = trim($strip ? StressMarks::strip($candidate['text']) : $candidate['text']);

            if ($text === ''
                || mb_strlen($text) > 256
                || preg_match('/[.,;:]/', $text) === 1) {
                continue;
            }

            $usable[] = ['text' => $text, 'pos' => $candidate['pos']];
        }

        return $usable;
    }

    /**
     * The wanted-class map for the shared resolver — the shared decision
     * (TranslationTargets::wantedClasses): a multi-word candidate wants the
     * target language's `phrase` class, a single-word one the class of its
     * pos slug, else the source word's class slug mapped into the target
     * language; an unmappable slug resolves to null and the resolver then
     * matches by l_word alone.
     *
     * @param  list<array{text: string, pos: string|null}>  $candidates
     * @return array<string, int|null> l_word => wanted word_class_id
     */
    private function wantedClasses(array $candidates, Language $target, Word $word): array
    {
        return TranslationTargets::wantedClasses(
            $candidates,
            WordClass::query()
                ->where('language_id', $target->id)
                ->orderBy('id')
                ->pluck('id', 'slug')
                ->all(),
            $word->word_class_id !== null
                ? WordClass::query()->whereKey($word->word_class_id)->value('slug')
                : null,
        );
    }

    /**
     * Stage the candidates whose l_word the target language does not carry:
     * their normalized strings union into the source word's translations
     * JSON through the shared merge (a re-fetch never duplicates, never
     * clobbers what is already staged), so the later link pass converts
     * them once the target words exist. Returns how many distinct
     * candidates went unmatched.
     *
     * @param  array<string, int>  $resolved  l_word => word id
     * @param  list<array{text: string, pos: string|null}>  $candidates
     */
    private function stageUnmatched(Word $word, array $candidates, array $resolved): int
    {
        $unmatched = [];
        foreach ($candidates as $candidate) {
            $normal = WordLookupKey::normalize($candidate['text']);
            if (! isset($resolved[$normal]) && ! isset($unmatched[$normal])) {
                $unmatched[$normal] = $candidate['text'];
            }
        }

        if ($unmatched !== []) {
            $merged = StagedTranslations::merge($word->translations, array_values($unmatched));
            if ($merged !== $word->translations) {
                $word->forceFill(['translations' => $merged])->save();
            }
        }

        return count($unmatched);
    }
}
