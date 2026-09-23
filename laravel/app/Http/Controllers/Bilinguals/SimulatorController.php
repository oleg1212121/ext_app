<?php

namespace App\Http\Controllers\Bilinguals;

use App\Classes\AIModelResolver;
use App\Classes\EntityAccessService;
use App\Classes\EntityWordMap;
use App\Classes\MeaningMatchPresenter;
use App\Exceptions\AiProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiQuestionRequest;
use App\Http\Requests\AiWordExplainRequest;
use App\Http\Requests\BilingualsTextRequest;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\Word;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SimulatorController extends Controller
{
    /**
     * The default assessment instruction. :base is substituted client-side
     * with the name of whichever side currently plays the base (translation
     * target) language, so the question tracks the language toggle.
     */
    public const DEFAULT_QUESTION = 'Compare :base original vs. my translation. Format rules: use ## headings for each numbered task; quote every exact word or phrase you discuss in straight double quotes; in corrections mark removed words as ~~removed~~ and added words as **added**; wrap the few most important weak-point phrases in ==double equals==; put improved versions in > blockquotes. Tasks: 1. Assess meaning accuracy (with percentile) and point out my weak parts. 2. Assess grammar (with percentile) and point out my weak parts. 3. Fix grammar/improve my version. 4. Give a couple of improved versions.';

    /**
     * The default question as it shipped before the sides became toggleable;
     * a saved copy of it is treated as "not customized" so the templated
     * default keeps tracking the current sides.
     */
    public const LEGACY_DEFAULT_QUESTION = 'Compare Russian original vs. my translation. Format rules: use ## headings for each numbered task; quote every exact word or phrase you discuss in straight double quotes; in corrections mark removed words as ~~removed~~ and added words as **added**; wrap the few most important weak-point phrases in ==double equals==; put improved versions in > blockquotes. Tasks: 1. Assess meaning accuracy (with percentile) and point out my weak parts. 2. Assess grammar (with percentile) and point out my weak parts. 3. Fix grammar/improve my version. 4. Give a couple of improved versions.';

    public function __construct(
        protected AIModelResolver $modelResolver,
        protected MeaningMatchPresenter $presenter,
    ) {}

    /**
     * The simulator with a match pinned by the URL (opened from its
     * alignment card): no text selector, the client loads this match.
     */
    public function simulatorForMatch(EntityMatch $entityMatch): Response
    {
        abort_unless($this->access()->canReadMatch(auth()->user(), $entityMatch), 403);

        return $this->simulatorResponse($entityMatch);
    }

    private function simulatorResponse(EntityMatch $pinned): Response
    {
        $pinned->loadMissing(['aEntity.language', 'bEntity.language']);
        $pinnedName = $this->matchLabel($pinned);

        $canUseAi = auth()->user()->canUseAi();

        $saved = auth()->user()->settings?->ui_settings['simulator'] ?? [];

        // A saved question is the user's customization and ships verbatim;
        // a copy of the pre-template default counts as not customized so it
        // keeps tracking the language toggle. Null lets the client render
        // the templated default for the current sides.
        $savedQuestion = $saved['question'] ?? null;
        if ($savedQuestion === self::LEGACY_DEFAULT_QUESTION) {
            $savedQuestion = null;
        }

        // The model is a per-user preference picked in the profile; the page
        // only shows which model is answering (or that none is chosen).
        $answerModel = $this->modelResolver->resolveAnswerModel();

        return Inertia::render('Bilinguals/Bilinguals', [
            'pinnedMatch' => ['id' => $pinned->id, 'text' => $pinnedName],
            // Both sides' languages: the client labels the columns and
            // substitutes the question template from these.
            'languages' => [
                'a' => [
                    'code' => $pinned->aEntity->language?->code,
                    'name' => $pinned->aEntity->language?->name,
                ],
                'b' => [
                    'code' => $pinned->bEntity->language?->code,
                    'name' => $pinned->bEntity->language?->name,
                ],
            ],
            // The side the side rule (EntityMatch::readingSideFor) reads by
            // default; the client's toggle flips around this.
            'defaultLearningSide' => $pinned->readingSideFor(auth()->user()->nativeLanguage()?->id),
            'questionTemplate' => self::DEFAULT_QUESTION,
            'showWorkplace' => (bool) ($saved['show_workplace'] ?? true),
            'showQuestion' => (bool) ($saved['show_question'] ?? false),
            'showText' => (bool) ($saved['show_text'] ?? true),
            'showAI' => $canUseAi && (bool) ($saved['show_ai'] ?? true),
            'canUseAi' => $canUseAi,
            'answerModel' => $answerModel !== null
                ? ['id' => $answerModel['id'], 'label' => $answerModel['label']]
                : null,
            'explanationModelKey' => $this->modelResolver->resolveExplanationModel()['id'] ?? null,
            'currentQuestion' => $savedQuestion,
            'currentText' => (string) $pinned->id,
            'fontSize' => $this->clampInt($saved['font_size'] ?? null, 12, 48, 26),
            'aiPanelWidth' => $this->clampInt($saved['ai_panel_width'] ?? null, 280, 1200, 560),
            'workplaceHeight' => $this->clampInt($saved['workplace_height'] ?? null, 80, 800, 168),
            'highlightWords' => (bool) ($saved['highlight_words'] ?? true),
        ]);
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

        if (! empty($validated['entity_match_id'])) {
            $result = $this->textFromEntityMatch(
                (int) $validated['entity_match_id'],
                $page,
                $perPage
            );
        } else {
            $result = $this->textFromFilename(
                $validated['filename'],
                $page,
                $perPage
            );
        }

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
     * @return array{rows: list<array{0: string, 1: string}>, row_keys: list<string>|null, word_maps: array|null, meta: array{current_page: int, per_page: int, total: int, last_page: int}, error?: string, code: int}
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

        return [
            'rows' => $this->presenter->toSimulatorRows($paginator->getCollection()),
            'row_keys' => $this->presenter->toSimulatorRowKeys($paginator->getCollection()),
            'word_maps' => $this->wordMapsFor($match),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => max(1, $paginator->lastPage()),
            ],
            'code' => 200,
        ];
    }

    /**
     * Interactive word maps and highlight eligibility for both sides of the
     * match. Null when either entity is gone (legacy file mode has none).
     *
     * @return array{a: array, b: array}|null
     */
    private function wordMapsFor(EntityMatch $match): ?array
    {
        if ($match->aEntity === null || $match->bEntity === null) {
            return null;
        }

        $userId = (int) auth()->id();
        $nativeLanguageId = auth()->user()->nativeLanguage()?->id;
        $wordMap = new EntityWordMap;

        return [
            'a' => $wordMap->forEntity($match->aEntity, $userId),
            'b' => $wordMap->forEntity($match->bEntity, $userId),
            'highlightable' => [
                'a' => $match->aEntity->language_id !== $nativeLanguageId,
                'b' => $match->bEntity->language_id !== $nativeLanguageId,
            ],
            // The AI explanation tab is offered on exactly the sides whose
            // language is not the user's native language.
            'explainable' => [
                'a' => $match->aEntity->language_id !== $nativeLanguageId,
                'b' => $match->bEntity->language_id !== $nativeLanguageId,
            ],
        ];
    }

    /**
     * @return array{rows: list<array{0: string, 1: string}>, row_keys: null, word_maps: null, meta: array{current_page: int, per_page: int, total: int, last_page: int}, error?: string, code: int}
     */
    private function textFromFilename(string $filename, int $page, int $perPage): array
    {
        $result = [
            'rows' => [],
            'row_keys' => null,
            'word_maps' => null,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => 0,
                'last_page' => 1,
            ],
        ];
        $isRus = false;

        $path = public_path('texts/simulator/'.$filename);

        if (! file_exists($path)) {
            $result['error'] = 'File not found';

            return [...$result, 'code' => 404];
        }

        $fd = fopen($path, 'r');
        if ($fd === false) {
            $result['error'] = 'Could not open file';

            return [...$result, 'code' => 500];
        }

        $allRows = [];
        $cur = ['', ''];

        while (($line = fgets($fd)) !== false) {
            $line = trim($line);

            if ($line === '') {
                if ($isRus) {
                    $allRows[] = $cur;
                    $cur = ['', ''];
                }
                $isRus = ! $isRus;
            } else {
                if ($isRus) {
                    $cur[1] = $line;
                } else {
                    $cur[0] = $line;
                }
            }
        }

        fclose($fd);

        $total = count($allRows);
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;
        if ($page > $lastPage) {
            $page = $lastPage;
        }
        $offset = ($page - 1) * $perPage;
        $result['rows'] = array_slice($allRows, $offset, $perPage);
        $result['meta'] = [
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
        ];

        return [...$result, 'code' => 200];
    }

    public function askAi(AiQuestionRequest $request): JsonResponse
    {
        $status = 200;
        $prompt = $request->validated('data') ?? '';

        $instruction = $request->validated('question') ?? '';
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
        $instruction = $request->validated('question') ?? '';
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
     * Explain a Ctrl-clicked word in its sentence context (the sentence
     * before, the clicked sentence, the sentence after — by document order
     * in the clicked side's entity). The client identifies the clicked
     * sentence either by a meaning-match row key (bilingual rows: its index
     * within the row side's text, which joins the side's non-empty sentences
     * in document order with newlines — MeaningMatchPresenter::sideText —
     * the same list is rebuilt here so the index lines up exactly) or by the
     * entity sentence id directly (single-language reader rows).
     */
    public function explainWord(AiWordExplainRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $model = $this->modelResolver->resolveExplanationModel();

        if ($model === null) {
            return $this->explainError('Choose an AI model in your profile settings.', 400);
        }

        if (! empty($validated['entity_sentence_id'])) {
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
        } else {
            /** @var MeaningMatch|null $meaningMatch */
            $meaningMatch = MeaningMatch::query()
                ->with(['entityMatch', 'sentenceMeaningMatches.entitySentence'])
                ->find($validated['meaning_match_id']);

            if ($meaningMatch === null || $meaningMatch->entityMatch === null) {
                return $this->explainError('Entity match not found', 404);
            }

            if (! $this->access()->canReadMatch(auth()->user(), $meaningMatch->entityMatch)) {
                return $this->explainError('You do not have access to this text.', 403);
            }

            $clicked = $meaningMatch->sentenceMeaningMatches
                ->where('side', $validated['side'])
                ->sortBy(fn ($junction) => $junction->entitySentence?->order ?? 0)
                ->filter(fn ($junction) => ($junction->entitySentence?->content ?? '') !== '')
                ->values()
                ->get((int) $validated['sentence_index'])
                ?->entitySentence;

            if ($clicked === null) {
                return $this->explainError('Sentence not found.', 404);
            }
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
        $instruction = 'You are a dictionary assistant for a language learner. '
            .'Explain the meaning of the word «'.$validated['surface'].'» as it is used in the sentence labelled "Sentence with the word", '
            .'using the neighbouring sentences only as context. Reply in '.$nativeName.'. '
            .'Be concise: 2 to 4 sentences. Name the sense that applies here and, when natural, give the closest '
            .$nativeName.' equivalent word or phrase. Markdown formatting is allowed. Do not repeat the sentences back.';

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
