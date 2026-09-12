<?php

namespace App\Http\Controllers;

use App\Classes\Crossword;
use App\Classes\CrosswordLevel;
use App\Classes\EntityAccessService;
use App\Classes\EntityWordIndexer;
use App\Http\Requests\CompleteCrosswordRequest;
use App\Http\Requests\GenerateCrosswordRequest;
use App\Http\Requests\KnowWordRequest;
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
            ->with(['work:id,title', 'language:id,code'])
            ->orderBy('work_id')
            ->get(['id', 'work_id', 'language_id', 'name', 'label'])
            ->map(fn (Entity $entity) => [
                'id' => $entity->id,
                'work_title' => $entity->work?->title,
                'language_code' => $entity->language?->code,
                'name' => $entity->name,
                'label' => $entity->label,
            ]);

        return Inertia::render('Crossword/Crossword', [
            'entities' => $entities,
            'levels' => CrosswordLevel::levels(),
            'nativeLanguageId' => $user->nativeLanguage()?->id,
        ]);
    }

    public function generate(GenerateCrosswordRequest $request, EntityWordIndexer $indexer): JsonResponse
    {
        $user = $request->user();
        $entity = Entity::query()->findOrFail($request->integer('entity_id'));

        if (! $this->access->canRead($user, $entity)) {
            return response()->json(['message' => 'You cannot read this entity.'], 403);
        }

        if ($indexer->isStale($entity)) {
            $indexer->index($entity);
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
            ->with(['definitions', 'forms'])
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

    public function know(KnowWordRequest $request): JsonResponse
    {
        $user = $request->user();

        UserWord::query()->updateOrCreate(
            ['user_id' => $user->id, 'word_id' => $request->integer('word_id')],
            ['status' => UserWord::STATUS_KNOWN],
        );

        return response()->json(['saved' => true]);
    }
}
