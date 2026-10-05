<?php

namespace App\Http\Controllers\Bilinguals;

use App\Classes\AIModelResolver;
use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\EntityAccessService;
use App\Classes\ReadingRowsPresenter;
use App\Exceptions\AiProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiQuestionRequest;
use App\Http\Requests\AiWordExplainRequest;
use App\Http\Requests\BilingualsTextRequest;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\Word;
use App\Support\PromptTemplates;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SimulatorController extends Controller
{
    public function __construct(
        protected AIModelResolver $modelResolver,
        protected ReadingRowsPresenter $readingRows,
        protected EnricherRegistry $enrichers,
    ) {}

    /**
     * The standalone simulator (Practice menu): no pinned match — the page
     * shows the alignment picker and loads matches via POST /text.
     */
    public function simulator(): Response
    {
        return $this->simulatorResponse(null);
    }

    /**
     * The simulator with a match pinned by the URL (opened from its
     * alignment card): no text selector, the client loads this match.
     */
    public function simulatorForMatch(EntityMatch $entityMatch): Response
    {
        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        return $this->simulatorResponse($entityMatch);
    }

    private function simulatorResponse(?EntityMatch $pinned): Response
    {
        if ($pinned !== null) {
            $pinned->loadMissing(['aEntity.language', 'bEntity.language']);
            $pinnedName = $this->matchLabel($pinned);
        } else {
            $pinnedName = null;
        }

        $textList = $pinned !== null ? [] : $this->getEntityMatchTextList();
        $firstId = $textList[0]['id'] ?? null;

        $canUseAi = auth()->user()->canUseAi();

        $saved = auth()->user()->settings?->ui_settings['simulator'] ?? [];

        // The saved question now holds only the user's customized task list
        // and ships verbatim; null lets the client show the default tasks.

        // The model is a per-user preference picked in the profile; the page
        // only shows which model is answering (or that none is chosen).
        $answerModel = $this->modelResolver->resolveAnswerModel();
        $explanationModel = $this->modelResolver->resolveExplanationModel();
        $explanationFollowsAnswer = $this->modelResolver->explanationModelFollowsAnswer();

        return Inertia::render('Bilinguals/Bilinguals', [
            'textList' => $textList,
            'pinnedMatch' => $pinnedName !== null ? ['id' => $pinned->id, 'text' => $pinnedName] : null,
            // Both sides' languages: the client labels the columns and
            // substitutes the question template from these. Null on the
            // picker entry — they arrive with each POST /text response.
            'languages' => $pinned !== null ? [
                'a' => [
                    'code' => $pinned->aEntity->language?->code,
                    'name' => $pinned->aEntity->language?->name,
                ],
                'b' => [
                    'code' => $pinned->bEntity->language?->code,
                    'name' => $pinned->bEntity->language?->name,
                ],
            ] : null,
            // The side the side rule (EntityMatch::readingSideFor) reads by
            // default; the client's toggle flips around this.
            'defaultLearningSide' => $pinned !== null
                ? $pinned->readingSideFor(auth()->user()->nativeLanguage()?->id)
                : 'a',
            // Raw admin-editable templates: the client substitutes the current
            // sides for display; the AI endpoints assemble server-side.
            'questionTemplates' => [
                'format' => PromptTemplates::format(),
                'tasks' => PromptTemplates::tasks(),
            ],
            'showWorkplace' => (bool) ($saved['show_workplace'] ?? true),
            'showQuestion' => (bool) ($saved['show_question'] ?? false),
            'showText' => (bool) ($saved['show_text'] ?? true),
            'showAI' => (bool) ($saved['show_ai'] ?? true),
            'canUseAi' => $canUseAi,
            'answerModel' => $answerModel !== null
                ? ['id' => $answerModel['id'], 'label' => $answerModel['label']]
                : null,
            'explanationModel' => $explanationModel !== null
                ? [
                    'id' => $explanationModel['id'],
                    'label' => $explanationModel['label'],
                    'followsAnswer' => $explanationFollowsAnswer,
                ]
                : null,
            'currentTasks' => $saved['question'] ?? null,
            'currentText' => $pinnedName !== null
                ? (string) $pinned->id
                : ($firstId !== null ? (string) $firstId : ''),
            'fontSize' => $this->clampInt($saved['font_size'] ?? null, 12, 48, 26),
            'aiPanelWidth' => $this->clampInt($saved['ai_panel_width'] ?? null, 280, 1200, 560),
            'workplaceHeight' => $this->clampInt($saved['workplace_height'] ?? null, 80, 800, 168),
            'highlightWords' => (bool) ($saved['highlight_words'] ?? true),
            // The annotation display preferences (ADR 0067): one prop per
            // registry annotation, camelCased from its setting key —
            // stressMarks, phrasalVerbs, ...
            ...$this->annotationPrefs($saved),
        ]);
    }

    /**
     * The saved annotation display preferences (ADR 0067), keyed by the
     * camelCased setting key the page expects as its prop name; default off.
     *
     * @param  array<string, mixed>  $saved  the user's ui_settings['simulator'] section
     * @return array<string, bool>
     */
    private function annotationPrefs(array $saved): array
    {
        $props = [];

        foreach ($this->enrichers->annotations() as $annotation) {
            $props[Str::camel($annotation->settingKey)] = (bool) ($saved[$annotation->settingKey] ?? false);
        }

        return $props;
    }

    /**
     * @return array<int, array{id: int, text: string}>
     */
    private function getEntityMatchTextList(): array
    {
        try {
            $matches = $this->access()
                ->readableMatchQuery(auth()->user())
                ->with(['aEntity.language', 'bEntity.language'])
                ->latest('id')
                ->get();

            $result = [];
            foreach ($matches as $match) {
                $result[] = ['id' => $match->id, 'text' => $this->matchLabel($match)];
            }

            return $result;
        } catch (Exception $e) {
            error_log('Entity matches not loaded: '.$e->getMessage());

            return [];
        }
    }

    private function matchLabel(EntityMatch $match): string
    {
        $aName = $match->aEntity->name
            ?? strtoupper($match->aEntity->language?->code ?? 'A');
        $bName = $match->bEntity->name
            ?? strtoupper($match->bEntity->language?->code ?? 'B');

        return "{$aName} / {$bName}";
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_int($value) && ! is_string($value) || ! preg_match('/^-?\d+$/', (string) $value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    public function text(BilingualsTextRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $page = max(1, (int) ($validated['page'] ?? 1));
        $perPage = min(200, max(1, (int) ($validated['per_page'] ?? 50)));

        $result = $this->textFromEntityMatch(
            (int) $validated['entity_match_id'],
            $page,
            $perPage
        );

        $status = $result['code'];
        unset($result['code']);

        return response()->json(
            [
                'data' => [
                    'data' => $result,
                    'code' => $status,
                ],
            ],
            $status,
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, word_maps: array{a: array, b: array}, highlightable: array{a: bool, b: bool}, explainable: array{a: bool, b: bool}, languages: array{a: array, b: array}, default_learning_side: string, meta: array{current_page: int, per_page: int, total: int, last_page: int}, error?: string, code: int}
     */
    private function textFromEntityMatch(int $entityMatchId, int $page, int $perPage): array
    {
        $match = EntityMatch::query()
            ->with(['aEntity.language', 'bEntity.language'])
            ->find($entityMatchId);

        if ($match === null) {
            return ['error' => 'Entity match not found', 'code' => 404];
        }

        if (! $this->access()->canReadMatch(auth()->user(), $match)) {
            return ['error' => 'You do not have access to this text.', 'code' => 403];
        }

        /** @var LengthAwarePaginator<int, MeaningMatch> $paginator */
        $paginator = MeaningMatch::query()
            ->where('entity_match_id', $entityMatchId)
            ->with(['sentenceMeaningMatches.entitySentence'])
            ->orderBy('order')
            ->paginate(perPage: $perPage, columns: ['*'], pageName: 'page', page: $page);

        $rows = $this->readingRows->toReadingRows($paginator->getCollection());
        ['wordMaps' => $wordMaps, 'highlightable' => $highlightable, 'explainable' => $explainable] = $this->readingRows->wordMapsFor(
            ['a' => $match->aEntity, 'b' => $match->bEntity],
            (int) auth()->id(),
            auth()->user()->nativeLanguage()?->id,
            $rows,
        );

        return [
            // Reading rows in canonical a/b order (ADR 0060) — which side is
            // the learning target is the client's flip around
            // default_learning_side.
            'rows' => $rows,
            // The reader's sibling shape (ADR 0060): page-scoped word maps,
            // with the highlight/explain eligibility flags as their own
            // keys. The wire stays snake_case; the client renames.
            'word_maps' => $wordMaps,
            'highlightable' => $highlightable,
            'explainable' => $explainable,
            // The picker page's language toggle tracks the loaded match: the
            // same shapes the pinned route ships at render time.
            'languages' => [
                'a' => [
                    'code' => $match->aEntity->language?->code,
                    'name' => $match->aEntity->language?->name,
                ],
                'b' => [
                    'code' => $match->bEntity->language?->code,
                    'name' => $match->bEntity->language?->name,
                ],
            ],
            'default_learning_side' => $match->readingSideFor(auth()->user()->nativeLanguage()?->id),
            'meta' => $this->readingRows->metaFor($paginator),
            'code' => 200,
        ];
    }

    public function askAi(AiQuestionRequest $request): JsonResponse
    {
        $status = 200;
        $prompt = $request->validated('data') ?? '';

        $instruction = $this->assembleInstruction($request);
        $model = $this->modelResolver->resolveAnswerModel();

        if ($model === null) {
            return $this->explainError('Choose an AI model in your profile settings.', 400);
        }

        try {
            $answer = $this->modelResolver->ask($model['key'], $instruction, $prompt);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'data' => [
                    'data' => ['error' => 'Invalid model selection.'],
                    'code' => 400,
                ],
            ], 400);
        } catch (AiProviderException $e) {
            return response()->json([
                'data' => [
                    'data' => ['error' => $e->getMessage()],
                    'code' => $e->getStatusCode(),
                ],
            ], $e->getStatusCode());
        }

        $data = [
            'answer' => $answer,
            'code' => $status,
        ];

        return response()->json(
            [
                'data' => $data,
            ],
            $status
        );
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
            return $this->explainError('Choose an AI model in your profile settings.', 400);
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
            return $this->explainError('Choose an AI model in your profile settings.', 400);
        }

        /** @var EntitySentence|null $clicked */
        $clicked = EntitySentence::query()
            ->with('entity')
            ->find($validated['entity_sentence_id']);

        if ($clicked === null) {
            return $this->explainError('Sentence not found.', 404);
        }

        if (! $this->access()->canRead(auth()->user(), $clicked->entity)) {
            return $this->explainError('You do not have access to this text.', 403);
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
            return $this->explainError('Invalid model selection.', 400);
        } catch (AiProviderException $e) {
            return $this->explainError($e->getMessage(), $e->getStatusCode());
        }

        return response()->json(['data' => ['answer' => $answer, 'code' => 200]], 200);
    }

    private function explainError(string $message, int $status): JsonResponse
    {
        return response()->json([
            'data' => [
                'data' => ['error' => $message],
                'code' => $status,
            ],
        ], $status);
    }

    private function access(): EntityAccessService
    {
        return new EntityAccessService;
    }
}
