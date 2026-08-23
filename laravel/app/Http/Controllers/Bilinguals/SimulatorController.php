<?php

namespace App\Http\Controllers\Bilinguals;

use App\Classes\AIModelResolver;
use App\Classes\MeaningMatchPresenter;
use App\Exceptions\AiProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AiQuestionRequest;
use App\Http\Requests\BilingualsTextRequest;
use App\Models\EnRuEntityMatch;
use App\Models\EnRuMeaningMatch;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SimulatorController extends Controller
{
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

        return Inertia::render('Bilinguals/Bilinguals', [
            'aiModels' => $aiModels,
            'textList' => $textList,
            'showWorkplace' => true,
            'showQuestion' => false,
            'showText' => true,
            'showAI' => true,
            'currentModel' => $currentModel,
            'currentQuestion' => 'Compare Russian original vs. my translation. Tasks: 1. Assess meaning accuracy (with percentile) and point out my weak parts. 2. Asses grammar (with percentile) and point out my weak parts. 3. Fix grammar/improve my version (highlight the changes). 4. Give  a couple of improved versions.',
            'currentText' => $firstId !== null ? (string) $firstId : '',
        ]);
    }

    /**
     * @return array<int, array{id: int, text: string}>
     */
    private function getEntityMatchTextList(): array
    {
        try {
            $matches = EnRuEntityMatch::query()
                ->with(['enEntity', 'ruEntity'])
                ->latest('id')
                ->get();

            $result = [];
            foreach ($matches as $match) {
                $enName = $match->enEntity->name ?? __('English');
                $ruName = $match->ruEntity->name ?? __('Russian');
                $result[] = ['id' => $match->id, 'text' => "{$enName} / {$ruName}"];
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

        if (! empty($validated['en_ru_entity_match_id'])) {
            $result = $this->textFromEntityMatch(
                (int) $validated['en_ru_entity_match_id'],
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
     * @return array{rows: list<array{0: string, 1: string}>, meta: array{current_page: int, per_page: int, total: int, last_page: int}, error?: string, code: int}
     */
    private function textFromEntityMatch(int $entityMatchId, int $page, int $perPage): array
    {
        /** @var LengthAwarePaginator<int, EnRuMeaningMatch> $paginator */
        $paginator = EnRuMeaningMatch::query()
            ->where('en_ru_entity_match_id', $entityMatchId)
            ->with([
                'enSentenceMatches.enEntitySentence',
                'ruSentenceMatches.ruEntitySentence',
            ])
            ->orderBy('order')
            ->paginate(perPage: $perPage, columns: ['*'], pageName: 'page', page: $page);

        return [
            'rows' => $this->presenter->toSimulatorRows($paginator->getCollection()),
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
     * @return array{rows: list<array{0: string, 1: string}>, meta: array{current_page: int, per_page: int, total: int, last_page: int}, error?: string, code: int}
     */
    private function textFromFilename(string $filename, int $page, int $perPage): array
    {
        $result = [
            'rows' => [],
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
}
