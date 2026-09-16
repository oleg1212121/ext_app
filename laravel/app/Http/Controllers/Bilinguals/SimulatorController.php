<?php

namespace App\Http\Controllers\Bilinguals;

use App\Classes\AIModelResolver;
use App\Classes\EntityAccessService;
use App\Classes\EntityWordMap;
use App\Classes\MeaningMatchPresenter;
use App\Exceptions\AiProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiQuestionRequest;
use App\Http\Requests\BilingualsTextRequest;
use App\Models\EntityMatch;
use App\Models\MeaningMatch;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SimulatorController extends Controller
{
    public const DEFAULT_QUESTION = 'Compare Russian original vs. my translation. Format rules: use ## headings for each numbered task; quote every exact word or phrase you discuss in straight double quotes; in corrections mark removed words as ~~removed~~ and added words as **added**; wrap the few most important weak-point phrases in ==double equals==; put improved versions in > blockquotes. Tasks: 1. Assess meaning accuracy (with percentile) and point out my weak parts. 2. Assess grammar (with percentile) and point out my weak parts. 3. Fix grammar/improve my version. 4. Give a couple of improved versions.';

    public function __construct(
        protected AIModelResolver $modelResolver,
        protected MeaningMatchPresenter $presenter,
    ) {}

    public function simulator(): Response
    {
        $aiModels = $this->modelResolver->getGroupedModels();
        $textList = $this->getEntityMatchTextList();
        $firstId = $textList[0]['id'] ?? null;

        $currentModel = null;
        foreach ($aiModels as $models) {
            $keys = array_keys($models);
            if (! empty($keys)) {
                $currentModel = $keys[0];
                break;
            }
        }

        $canUseAi = auth()->user()->canUseAi();

        $saved = auth()->user()->settings?->ui_settings['simulator'] ?? [];

        $availableModels = [];
        foreach ($aiModels as $models) {
            $availableModels = [...$availableModels, ...array_keys($models)];
        }
        if (isset($saved['model']) && in_array($saved['model'], $availableModels, true)) {
            $currentModel = $saved['model'];
        }

        return Inertia::render('Bilinguals/Bilinguals', [
            'aiModels' => $aiModels,
            'textList' => $textList,
            'showWorkplace' => (bool) ($saved['show_workplace'] ?? true),
            'showQuestion' => (bool) ($saved['show_question'] ?? false),
            'showText' => (bool) ($saved['show_text'] ?? true),
            'showAI' => $canUseAi && (bool) ($saved['show_ai'] ?? true),
            'canUseAi' => $canUseAi,
            'currentModel' => $currentModel,
            'currentQuestion' => $saved['question'] ?? self::DEFAULT_QUESTION,
            'currentText' => $firstId !== null ? (string) $firstId : '',
            'fontSize' => $this->clampInt($saved['font_size'] ?? null, 12, 48, 26),
            'aiPanelWidth' => $this->clampInt($saved['ai_panel_width'] ?? null, 280, 1200, 560),
            'workplaceHeight' => $this->clampInt($saved['workplace_height'] ?? null, 80, 800, 168),
            'highlightWords' => (bool) ($saved['highlight_words'] ?? true),
        ]);
    }

    private function clampInt(mixed $value, int $min, int $max, int $default): int
    {
        if (! is_int($value) && ! is_string($value) || ! preg_match('/^-?\d+$/', (string) $value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
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
                $aName = $match->aEntity->name
                    ?? strtoupper($match->aEntity->language?->code ?? 'A');
                $bName = $match->bEntity->name
                    ?? strtoupper($match->bEntity->language?->code ?? 'B');
                $result[] = ['id' => $match->id, 'text' => "{$aName} / {$bName}"];
            }

            return $result;
        } catch (Exception $e) {
            error_log('Entity matches not loaded: '.$e->getMessage());

            return [];
        }
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
        $modelString = $request->validated('model');

        try {
            $answer = $this->modelResolver->ask($modelString, $instruction, $prompt);
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
    public function askAiStreamed(AiQuestionRequest $request): StreamedResponse
    {
        $prompt = $request->validated('data') ?? '';
        $instruction = $request->validated('question') ?? '';
        $modelString = $request->validated('model');

        return response()->stream(function () use ($modelString, $instruction, $prompt): void {
            $sendEvent = function (string $payload): void {
                echo 'data: '.$payload."\n\n";
                @ob_flush();
                flush();
            };

            try {
                $this->modelResolver->askStreamed(
                    $modelString,
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

    private function access(): EntityAccessService
    {
        return new EntityAccessService;
    }
}
