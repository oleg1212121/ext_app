<?php

namespace App\Http\Controllers;

use App\Classes\AIModelResolver;
use App\Classes\EntityAccessService;
use App\Classes\WordAiEnrichmentService;
use App\Exceptions\AiProviderException;
use App\Http\Requests\AiQuestionRequest;
use App\Http\Requests\AiWordEnrichRequest;
use App\Http\Requests\AiWordExplainRequest;
use App\Jobs\FetchWordAiEnrichment;
use App\Models\EntitySentence;
use App\Models\Language;
use App\Models\Word;
use App\Models\WordAiFetch;
use App\Support\PromptTemplates;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reading surfaces' AI answers: the simulator's assessment gloss
 * (question + streamed answer), the word popup's Context explanation and the
 * word popup's ask-AI enrichment — shared by the reader and the simulator.
 * The routes keep their paths, names and throttle; only the controller moved
 * out of SimulatorController, which keeps the page props and the POST /text
 * payload.
 */
class ReadingAiController extends Controller
{
    /**
     * How long a pending ledger row stays "another ask in flight" before a
     * retry may take it over. The provider layer's own cURL timeouts cap at
     * 120s, so a genuinely live ask finishes (or dies) far inside the
     * window — only a crashed worker's orphan crosses it.
     */
    public const PENDING_STALE_AFTER_MINUTES = 15;

    public function __construct(
        protected AIModelResolver $modelResolver,
        protected EntityAccessService $access,
        protected WordAiEnrichmentService $enrichment,
    ) {}

    public function askAi(AiQuestionRequest $request): JsonResponse
    {
        $prompt = $request->validated('data') ?? '';

        $instruction = $this->assembleInstruction($request);
        $model = $this->modelResolver->resolveAnswerModel();

        if ($model === null) {
            return $this->aiError('Choose an AI model in your profile settings.', 400);
        }

        try {
            $answer = $this->modelResolver->ask($model['key'], $instruction, $prompt);
        } catch (InvalidArgumentException) {
            return $this->aiError('Invalid model selection.', 400);
        } catch (AiProviderException $e) {
            return $this->aiError($e->getMessage(), $e->getStatusCode());
        }

        return response()->json(['data' => ['answer' => $answer]], 200);
    }

    /**
     * Stream the AI response as Server-Sent Events.
     *
     * Each text chunk is emitted as `data: {"text": "..."}\n\n`.
     * On error: `data: {"error": "..."}\n\n`.
     * On completion: `data: [DONE]\n\n`.
     */
    public function askAiStreamed(AiQuestionRequest $request): StreamedResponse|JsonResponse
    {
        $prompt = $request->validated('data') ?? '';
        $instruction = $this->assembleInstruction($request);
        $model = $this->modelResolver->resolveAnswerModel();

        if ($model === null) {
            return $this->aiError('Choose an AI model in your profile settings.', 400);
        }

        return response()->stream(function () use ($model, $instruction, $prompt): void {
            $sendEvent = function (string $payload): void {
                echo 'data: '.$payload."\n\n";
                @ob_flush();
                flush();
            };

            try {
                $this->modelResolver->askStreamed(
                    $model['key'],
                    $instruction,
                    $prompt,
                    function (string $chunk) use ($sendEvent): void {
                        $sendEvent(json_encode(['text' => $chunk]) ?: '{"text":""}');
                    }
                );
            } catch (InvalidArgumentException) {
                $sendEvent(json_encode(['error' => 'Invalid model selection.']) ?: '{"error":"Invalid model selection."}');
            } catch (AiProviderException $e) {
                $sendEvent(json_encode(['error' => $e->getMessage()]) ?: '{"error":"error"}');
            }

            $sendEvent('[DONE]');
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * The system message for an assessment: the admin's format template
     * (prompt_templates, :base/:learning substituted from the client's
     * current column language codes) joined with the user's task list.
     */
    private function assembleInstruction(AiQuestionRequest $request): string
    {
        return PromptTemplates::assemble(
            $request->validated('tasks'),
            $request->validated('base'),
            $request->validated('learning'),
        );
    }

    /**
     * Explain a Ctrl-clicked word in its sentence context (the sentence
     * before, the clicked sentence, the sentence after — by document order
     * in the clicked side's entity). The client identifies the clicked
     * sentence by its entity sentence id — reading rows carry sentence ids
     * on every side (ADR 0060), so no positional contract exists.
     */
    public function explainWord(AiWordExplainRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $model = $this->modelResolver->resolveExplanationModel();

        if ($model === null) {
            return $this->aiError('Choose an AI model in your profile settings.', 400);
        }

        /** @var EntitySentence|null $clicked */
        $clicked = EntitySentence::query()
            ->with('entity')
            ->find($validated['entity_sentence_id']);

        if ($clicked === null) {
            return $this->aiError('Sentence not found.', 404);
        }

        if (! $this->access->canRead(auth()->user(), $clicked->entity)) {
            return $this->aiError('You do not have access to this text.', 403);
        }

        $previous = EntitySentence::query()
            ->where('entity_id', $clicked->entity_id)
            ->where('order', '<', $clicked->order)
            ->orderByDesc('order')
            ->first();
        $next = EntitySentence::query()
            ->where('entity_id', $clicked->entity_id)
            ->where('order', '>', $clicked->order)
            ->orderBy('order')
            ->first();

        $marked = preg_replace_callback(
            '/(?<![\p{L}])'.preg_quote($validated['surface'], '/').'(?![\p{L}])/iu',
            fn (array $matches) => '**'.$matches[0].'**',
            $clicked->content,
        ) ?? $clicked->content;

        $word = Word::query()->find($validated['word_id']);
        $headwordNote = $word !== null && mb_strtolower($word->word) !== mb_strtolower($validated['surface'])
            ? ' (dictionary form: «'.$word->word.'»)'
            : '';

        $nativeName = auth()->user()->nativeLanguage()?->name ?? 'English';
        $instruction = PromptTemplates::explanation($validated['surface'], $nativeName);

        $question = "Word to explain: «{$validated['surface']}»{$headwordNote}\n\n"
            ."Sentence before:\n".($previous?->content ?? '(not available)')."\n\n"
            ."Sentence with the word:\n{$marked}\n\n"
            ."Sentence after:\n".($next?->content ?? '(not available)');

        try {
            $answer = $this->modelResolver->ask($model['key'], $instruction, $question);
        } catch (InvalidArgumentException) {
            return $this->aiError('Invalid model selection.', 400);
        } catch (AiProviderException $e) {
            return $this->aiError($e->getMessage(), $e->getStatusCode());
        }

        return response()->json(['data' => ['answer' => $answer]], 200);
    }

    /**
     * Ask-AI enrichment (popup-ai-enrich tickets 04 + 05; word-popup-overhaul
     * ticket 06 made it admin-only, queued and fire-and-forget): the click
     * still validates, resolves the model + key cheaply (no HTTP), and
     * adopts/mints the word synchronously — so repeat clicks and races keep
     * hitting the clean in-progress 409 through the dispatch-time ledger
     * gate — then records the pending ledger row and DISPATCHES the queued
     * job, answering immediately with the minimal `{queued: true}` payload.
     * No AI call runs inside the HTTP request anymore: the job carries the
     * asking user and runs the prompt ask, the lenient parse, the
     * transactional merge, the succeeded finalize and the word touch — any
     * failure finalizes the ledger failed (silently client-side). Model
     * + key resolve TWICE by design: here against the session user (a
     * no-model/no-key user gets the existing 400/403 guidance at dispatch
     * and dispatches nothing), and again inside the job against the carried
     * user via AIModelResolver's explicit-user seam.
     *
     * Admin-only (approved admins; ticket 06): the ask spends the asker's
     * own API-key tokens, so the endpoint refuses everyone else — in the
     * reading-surfaces JSON shape, before any gate, mint or ledger row —
     * matching the sparkles icon the UI hides for them.
     *
     * Two request modes: the known-word path binds the word by id (ticket
     * 04); the missing-word adoption path (ticket 05) resolves it from the
     * RAW clicked surface through the ADR 0081 lookup key — a hit adopts
     * onto the existing row, a miss mints one unknown-class word
     * (WordAiEnrichmentService::resolveOrAdopt) — then runs the identical
     * flow. The model gate precedes the adoption mint, so a no-model user
     * mints nothing; the ledger gate runs once per path after the word is
     * known (409 precedence over the model 400 is ticket 04's documented
     * order on the known path).
     */
    public function enrichWord(AiWordEnrichRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Approved admins only: hidden, not disabled, in the UI; the gate
        // sits first so a non-admin learns nothing about words or ledger
        // state from this endpoint.
        $user = $request->user();
        if (! $user->isAdmin() || ! $user->is_approved) {
            return $this->aiError('Only approved admins can ask the AI to enrich a word.', 403);
        }

        /** @var Word|null $word */
        $word = array_key_exists('word_id', $validated)
            ? Word::query()->findOrFail($validated['word_id'])
            : null;

        if ($word !== null && ($blocked = $this->ledgerBlock($word)) !== null) {
            return $blocked;
        }

        $model = $this->modelResolver->resolveExplanationModel();

        if ($model === null) {
            return $this->aiError('Choose an AI model in your profile settings.', 400);
        }

        // The ledger carries the resolved model's own two halves, split by
        // the resolver's parser (first colon, so OpenRouter ids with '/'
        // stay intact). A key without one is dead in the water — the same
        // 400 the ask would raise, before any ledger row or mint exists.
        try {
            $modelParts = $this->modelResolver->parseModelString($model['key']);
        } catch (InvalidArgumentException) {
            return $this->aiError('Invalid model selection.', 400);
        }

        if ($word === null) {
            $language = Language::query()
                ->where('code', (string) $validated['language_code'])
                ->firstOrFail();

            $word = $this->enrichment->resolveOrAdopt((string) $validated['surface'], $language);

            // The adopted (possibly minted) word's ledger gate: a hit can
            // already be locked or have an ask in flight; a fresh mint
            // passes trivially.
            if (($blocked = $this->ledgerBlock($word)) !== null) {
                return $blocked;
            }
        }

        try {
            $ledger = WordAiFetch::query()->firstOrNew(['word_id' => $word->id]);
            $ledger->forceFill([
                'status' => WordAiFetch::STATUS_PENDING,
                'provider' => $modelParts['provider'],
                'model' => $modelParts['model'],
                'attempts' => (int) $ledger->attempts + 1,
                'asked_at' => now(),
                'error' => null,
            ])->save();
        } catch (QueryException $e) {
            // A true simultaneous double-ask: both requests saw no ledger
            // row, and the unique word constraint bounced the loser's
            // insert. That loser gets the same clean retryable-later
            // answer as the visible-pending gate — never a 500.
            if (in_array($e->errorInfo[0] ?? null, ['23505', '23000'], true)) {
                return $this->aiInProgress();
            }

            throw $e;
        }

        // Fire-and-forget: the queued job (carrying the asking user's id
        // and the word's) resolves model + key against that user again and
        // runs the ask → parse → merge → finalize → touch. The pending row
        // this save just recorded is what the job finalizes — and what the
        // fresh-pending gate reports as "already in progress" until it does.
        FetchWordAiEnrichment::dispatch($word->id, (int) $user->id);

        return response()->json(['queued' => true], 200);
    }

    /**
     * The ledger gate both enrich paths run once the word is known: a fresh
     * pending row means another ask is in flight right now — the concurrent
     * double-ask's visible loser gets the clean retryable-later answer
     * instead of racing on. Succeeded rows pass too (ADR 0082 amendment:
     * the toolbar's re-ask icon, a re-ask replaces the previous AI rows),
     * and so do failed rows (retryable at any count) and a pending row
     * older than the grace window: pending is not a lock — a
     * fatal/timeout/OOM between the pending save and the queued job's
     * finalize would otherwise strand the word as "in progress" forever.
     * The retrying flow's pending save then takes the stale row over
     * (attempts++, asked_at refreshed). The window sits far above the
     * provider layer's own cURL timeouts — and above the queued job's —
     * so a genuinely live ask never reaches it: crossing it is a crashed
     * (or timeout-killed) worker's orphan, exactly the failure mode the
     * queued flow added.
     */
    private function ledgerBlock(Word $word): ?JsonResponse
    {
        $ledger = WordAiFetch::query()->firstOrNew(['word_id' => $word->id]);

        if ($ledger->exists
            && $ledger->status === WordAiFetch::STATUS_PENDING
            && $ledger->asked_at !== null
            && $ledger->asked_at->gt(now()->subMinutes(self::PENDING_STALE_AFTER_MINUTES))) {
            return $this->aiInProgress();
        }

        return null;
    }

    /**
     * The concurrent double-ask's loser: the winner's ask is mid-flight
     * (now as a queued job), so a retry a moment later either re-asks
     * (winner failed, or the word was already succeeded and this was a
     * re-ask) or takes the takeover path past the stale window — never a
     * lost attempt.
     */
    private function aiInProgress(): JsonResponse
    {
        return $this->aiError('An AI ask for this word is already in progress. Try again in a moment.', 409);
    }

    /**
     * The reading-surface JSON error shape: a top-level error plus the HTTP
     * status — the flat /word-events convention, no double envelope.
     */
    private function aiError(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
