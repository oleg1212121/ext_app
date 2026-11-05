<?php

namespace App\Jobs;

use App\Classes\AIModelResolver;
use App\Classes\WordAiEnrichmentService;
use App\Exceptions\AiProviderException;
use App\Models\User;
use App\Models\Word;
use App\Models\WordAiFetch;
use App\Support\EnrichmentReply;
use App\Support\PromptTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Throwable;

/**
 * The ask-AI enrichment's queued half (word-popup-overhaul ticket 06): the
 * HTTP request validates, gates the ledger, adopts/mints the word and
 * records the pending row, then hands the provider call to THIS job and
 * answers `{queued: true}` — the AI call's duration no longer occupies an
 * HTTP worker. Everything after the dispatch happens here: the prompt ask,
 * the lenient parse, the transactional merge, the succeeded finalize and
 * the word touch (the completeness recalc's staleness marker); any failure
 * finalizes the ledger failed.
 *
 * The job carries the ASKING user's id (plus the word's) as scalar ids —
 * the model preference and the BYO API key resolve INSIDE the job against
 * that user, through AIModelResolver's explicit-user seam, never the
 * authenticated session: a queue worker has none, and the ask must spend
 * the asker's own key whatever worker happens to pick it up.
 *
 * Deliberately NOT ShouldBeUnique: the per-word unique ledger constraint +
 * the dispatch-time fresh-pending 409 gate are the double-ask race net,
 * and a uniqueness lock would fight the stale-pending takeover (a retry
 * past the 15-minute grace window must be able to re-dispatch onto a row
 * a crashed worker stranded).
 *
 * `$tries = 1` — no auto-retry: a failed run finalizes the ledger failed
 * once and the user re-asks via the still-retryable icon; queue-level
 * retries would double-spend tokens against the asker's own key (the dev
 * worker even runs `queue:listen --tries=1`). `$timeout` sits comfortably
 * above the provider layer's 120s cURL cap — one provider call per run —
 * and a run the timeout kills finalizes nothing: the ledger's
 * `PENDING_STALE_AFTER_MINUTES` window is exactly the net for that orphan.
 * A failed job is silent client-side: nothing surfaces on the next open,
 * the icon simply stays retryable.
 */
#[Queue(QueueLane::DEFAULT)]
class FetchWordAiEnrichment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        public readonly int $wordId,
        public readonly int $userId,
    ) {}

    public function handle(AIModelResolver $modelResolver, WordAiEnrichmentService $enrichment): void
    {
        $word = Word::query()->with(['language', 'wordClass'])->find($this->wordId);
        $user = User::query()->find($this->userId);
        $ledger = WordAiFetch::query()->firstOrCreate(['word_id' => $this->wordId]);

        // The asker or the word vanished between dispatch and run (an
        // account or word deletion): nothing can ever merge — finalize the
        // stranded pending row failed instead of letting it gate the word
        // for the whole stale window.
        if ($word === null || $user === null) {
            $this->failLedger($ledger, 'The word or its asker no longer exists.');

            return;
        }

        try {
            // The ask resolves model AND key against the carried user —
            // the same twice-resolution the dispatching controller ran
            // cheaply (there it only answered the 400/403 guidance before
            // dispatching; here it is what makes the ask possible).
            $model = $modelResolver->resolveExplanationModel($user);

            if ($model === null) {
                $this->failLedger($ledger, 'Choose an AI model in your profile settings.');

                return;
            }

            // The ledger carries the resolved provider/model already (the
            // dispatch-time split stored the two halves) — the ask() call
            // below re-parses the key itself and its failure paths own the
            // invalid-model case.

            $nativeLanguage = $user->nativeLanguage();
            $instruction = PromptTemplates::enrichment(
                $word->word,
                $word->language?->name ?? 'the word\'s language',
                $nativeLanguage?->name ?? 'English',
            );

            // The adopted word's class is unknown until real data says
            // otherwise (ADR 0059 spirit) — the placeholder class never
            // travels into the prompt.
            $classTitle = $word->wordClass?->title;
            $question = 'Word to enrich: «'.$word->word.'»'
                .($classTitle !== null && $word->wordClass->slug !== 'unknown' ? ' — '.$classTitle : '');

            try {
                $answer = $modelResolver->ask($model['key'], $instruction, $question, $user);
            } catch (InvalidArgumentException) {
                $this->failLedger($ledger, 'Invalid model selection.');

                return;
            } catch (AiProviderException $e) {
                $this->failLedger($ledger, $e->getMessage());

                return;
            }

            $parsed = EnrichmentReply::parse((string) $answer);

            if ($parsed === null) {
                $this->failLedger($ledger, 'The AI reply could not be parsed as strict JSON.');

                return;
            }

            // A successfully parsed reply finalizes the ask even when the
            // merge dedupes to nothing new: the ask itself succeeded and
            // the content is present. The succeeded finalize is what the
            // payload's `ai_asked` reports — the toolbar's plain icon turns
            // into the re-ask variant (ADR 0082 amendment) on the next
            // payload build.
            $enrichment->applyParsed($word, $parsed, $nativeLanguage);

            $ledger->forceFill([
                'status' => WordAiFetch::STATUS_SUCCEEDED,
                'succeeded_at' => now(),
                'error' => null,
            ])->save();

            // The word is touched so ticket 02's lazy completeness recalc
            // sees a stale score (the next open's payload build then
            // recalculates over the replaced content). Note the finalize
            // never clears `succeeded_at` afterwards: a later FAILED
            // re-ask keeps it, so `ai_asked` — reported on `succeeded_at`
            // — stays true and the re-ask icon survives a failed retry.
            $word->touch();
        } catch (Throwable $exception) {
            // A failure the specific catches above don't own (a DB error
            // mid-merge, …) still finalizes the ledger failed before the
            // rethrow — pending must never strand past the run — and the
            // rethrow gives the queue's failed_jobs record the visibility
            // (no retry: $tries = 1).
            $this->failLedger($ledger, $exception->getMessage() ?: get_class($exception));

            throw $exception;
        }
    }

    /**
     * Finalize a failed attempt (the lock never engages) — the ledger row
     * is the only failure record a fire-and-forget ask surfaces: the
     * client stays silent, the icon simply stays retryable. The message
     * truncation mirrors the old controller's failLedger.
     */
    private function failLedger(WordAiFetch $ledger, string $message): void
    {
        $ledger->forceFill([
            'status' => WordAiFetch::STATUS_FAILED,
            'error' => mb_substr($message, 0, 500),
        ])->save();
    }
}
