<?php

namespace App\Http\Controllers;

use App\Classes\AIModelResolver;
use App\Classes\EntityAccessService;
use App\Exceptions\AiProviderException;
use App\Http\Requests\AiQuestionRequest;
use App\Http\Requests\AiWordExplainRequest;
use App\Models\EntitySentence;
use App\Models\Word;
use App\Support\PromptTemplates;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reading surfaces' AI answers: the simulator's assessment gloss
 * (question + streamed answer) and the word popup's Context explanation —
 * shared by the reader and the simulator. The routes keep their paths,
 * names and throttle; only the controller moved out of SimulatorController,
 * which keeps the page props and the POST /text payload.
 */
class ReadingAiController extends Controller
{
    public function __construct(
        protected AIModelResolver $modelResolver,
        protected EntityAccessService $access,
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
     * The reading-surface JSON error shape: a top-level error plus the HTTP
     * status — the flat /word-events convention, no double envelope.
     */
    private function aiError(string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }
}
