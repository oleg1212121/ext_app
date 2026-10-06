<?php

namespace App\Http\Controllers\Bilinguals;

use App\Classes\AIModelResolver;
use App\Classes\Enrichment\EnricherRegistry;
use App\Classes\EntityAccessService;
use App\Classes\ReadingRowsPresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\BilingualsTextRequest;
use App\Models\EntityMatch;
use App\Models\MeaningMatch;
use App\Support\PromptTemplates;
use App\Support\SavedUiSettings;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class SimulatorController extends Controller
{
    public function __construct(
        protected AIModelResolver $modelResolver,
        protected ReadingRowsPresenter $readingRows,
        protected EnricherRegistry $enrichers,
        protected EntityAccessService $access,
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
        abort_unless($this->access->canReadMatch(auth()->user(), $entityMatch), 403);

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

        $saved = SavedUiSettings::section(auth()->user(), 'simulator');

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
            'showWorkplace' => SavedUiSettings::bool($saved, 'show_workplace', true),
            'showQuestion' => SavedUiSettings::bool($saved, 'show_question', false),
            'showText' => SavedUiSettings::bool($saved, 'show_text', true),
            'showAI' => SavedUiSettings::bool($saved, 'show_ai', true),
            'canUseAi' => auth()->user()->canUseAi(),
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
            // The word popup's Context explanation config (the reader's
            // sibling shape, built by the same resolver method); the answer
            // label is simulator-only — the Models used popup names the
            // model behind the assessment answer.
            'explain' => $this->modelResolver->explainConfig($answerModel['label'] ?? null),
            'currentTasks' => $saved['question'] ?? null,
            'currentText' => $pinnedName !== null
                ? (string) $pinned->id
                : ($firstId !== null ? (string) $firstId : ''),
            'fontSize' => SavedUiSettings::int($saved, 'font_size', ...SavedUiSettings::SIMULATOR_FONT_SIZE),
            'aiPanelWidth' => SavedUiSettings::int($saved, 'ai_panel_width', ...SavedUiSettings::SIMULATOR_AI_PANEL_WIDTH),
            'workplaceHeight' => SavedUiSettings::int($saved, 'workplace_height', ...SavedUiSettings::SIMULATOR_WORKPLACE_HEIGHT),
            'highlightWords' => SavedUiSettings::bool($saved, 'highlight_words', true),
            // The annotation display preferences (ADR 0067): one prop per
            // registry annotation, camelCased from its setting key —
            // stressMarks, phrasalVerbs, ...
            ...SavedUiSettings::annotations($saved, $this->enrichers->annotations()),
        ]);
    }

    /**
     * @return array<int, array{id: int, text: string}>
     */
    private function getEntityMatchTextList(): array
    {
        try {
            $matches = $this->access
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

        // The reading-surface JSON envelope: success wraps the payload in
        // data (the flat /word-events convention); errors are a top-level
        // error plus the HTTP status — no data.data, no mirrored code key.
        if (array_key_exists('error', $result)) {
            return response()->json(['error' => $result['error']], $result['status']);
        }

        return response()->json(['data' => $result], 200);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, word_maps: array{a: array, b: array}, highlightable: array{a: bool, b: bool}, explainable: array{a: bool, b: bool}, languages: array{a: array, b: array}, default_learning_side: string, meta: array{current_page: int, per_page: int, total: int, last_page: int}}|array{error: string, status: int}
     */
    private function textFromEntityMatch(int $entityMatchId, int $page, int $perPage): array
    {
        $match = EntityMatch::query()
            ->with(['aEntity.language', 'bEntity.language'])
            ->find($entityMatchId);

        if ($match === null) {
            return ['error' => 'Entity match not found', 'status' => 404];
        }

        if (! $this->access->canReadMatch(auth()->user(), $match)) {
            return ['error' => 'You do not have access to this text.', 'status' => 403];
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
        ];
    }
}
