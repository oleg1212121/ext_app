<?php

namespace App\Http\Controllers;

use App\Classes\Crossword;
use App\Classes\CrosswordLevel;
use App\Classes\EntityAccessService;
use App\Classes\EntityWordIndexer;
use App\Http\Requests\CompleteCrosswordRequest;
use App\Http\Requests\GenerateCrosswordRequest;
use App\Models\Entity;
use App\Models\EntityWord;
use App\Models\UserWord;
use App\Models\Word;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrosswordController extends Controller
{
    private const WORD_LIMIT = 30;

    private const MIN_WORDS = 3;

    public function __construct(private readonly EntityAccessService $access) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $entities = $this->access->readableQuery($user)
            ->with(['work:id,title,original_language_id', 'work.originalLanguage:id,code', 'language:id,code,name'])
            ->orderBy('work_id')
            ->get(['id', 'work_id', 'language_id', 'name', 'label']);

        $works = $entities
            ->groupBy('work_id')
            ->map(fn ($workEntities) => [
                'id' => $workEntities->first()->work->id,
                'title' => $workEntities->first()->work->title,
                'original_language_code' => $workEntities->first()->work->originalLanguage?->code,
                'entities' => $workEntities
                    ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                    ->map(fn (Entity $entity) => [
                        'id' => $entity->id,
                        'name' => $entity->name,
                        'label' => $entity->label,
                        'language_code' => $entity->language?->code,
                    ])
                    ->values(),
            ])
            ->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $languages = $entities
            ->map(fn (Entity $entity) => $entity->language)
            ->filter()
            ->unique('id')
            ->sortBy('code')
            ->map(fn ($language) => ['code' => $language->code, 'name' => $language->name])
            ->values();

        return Inertia::render('Crossword/Crossword', [
            'works' => $works,
            'languages' => $languages,
            'levels' => CrosswordLevel::levels(),
        ]);
    }

    public function generate(GenerateCrosswordRequest $request, EntityWordIndexer $indexer): JsonResponse
    {
        $user = $request->user();
        $entity = Entity::query()->findOrFail($request->integer('entity_id'));

        if (! $this->access->canRead($user, $entity)) {
            return response()->json(['message' => 'You cannot read this entity.'], 403);
        }

        // The word list is built and linked in the background
        // (crossword:refresh); generate never blocks on it.
        if ($indexer->isStale($entity)) {
            return response()->json(['message' => __('crossword.still_building')], 422);
        }

        $wordIds = EntityWord::query()
            ->where('entity_words.entity_id', $entity->id)
            ->whereNotNull('entity_words.word_id')
            ->join('words', 'words.id', '=', 'entity_words.word_id')
            ->where('words.frequency', '>', 0)
            ->where('words.frequency', '<=', CrosswordLevel::cutoff($request->integer('level')))
            ->whereNotExists(function ($query) use ($user) {
                $query->selectRaw(1)
                    ->from('user_word')
                    ->whereColumn('user_word.word_id', 'words.id')
                    ->where('user_word.user_id', $user->id)
                    ->whereIn('user_word.status', [UserWord::STATUS_SOLVED, UserWord::STATUS_KNOWN]);
            })
            ->orderBy('words.frequency')
            ->orderBy('words.id')
            ->limit(self::WORD_LIMIT)
            ->pluck('words.id');

        $words = Word::query()
            ->whereIn('id', $wordIds)
            ->orderBy('frequency')
            ->orderBy('id')
            ->with('definitions')
            ->get();

        if ($words->count() < self::MIN_WORDS) {
            return response()->json(['message' => __('crossword.not_enough_words')], 422);
        }

        foreach ($words as $word) {
            UserWord::query()->firstOrCreate(
                ['user_id' => $user->id, 'word_id' => $word->id],
                ['status' => UserWord::STATUS_LEARNING],
            );
        }

        $crossword = new Crossword($words, $user->nativeLanguage()?->id);
        $crossword->crossword();
        $crossword->word_ids = $words->pluck('id', 'word')->all();

        return response()->json(['data' => ['crossword' => $crossword]]);
    }

    public function complete(CompleteCrosswordRequest $request): JsonResponse
    {
        $user = $request->user();

        UserWord::query()
            ->where('user_id', $user->id)
            ->whereIn('word_id', $request->array('word_ids'))
            ->where('status', UserWord::STATUS_LEARNING)
            ->update(['status' => UserWord::STATUS_SOLVED]);

        return response()->json(['saved' => true]);
    }
}
