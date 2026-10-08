<?php

namespace App\Http\Controllers;

use App\Classes\EntityAccessService;
use App\Models\Entity;
use App\Models\Language;
use App\Models\UserEntityWordKnowledge;
use App\Models\UserWord;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Recommendations page (ADR 0075): the viewer's readable texts re-ranked
 * by their stored word-knowledge percentage (ADR 0074) — everything at or
 * above the threshold, least known first, grouped under works. The page only
 * reads the sparse user_entity_word_knowledge snapshots: it re-ranks texts
 * the viewer has already opened and never computes a score itself.
 */
class RecommendationsController extends Controller
{
    /** The knowledge threshold (%) the page defaults to. */
    private const DEFAULT_KNOWLEDGE = 90.0;

    /** Works per page — the Library list convention. */
    private const WORKS_PER_PAGE = 15;

    public function __construct(
        private readonly EntityAccessService $access,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $knowledge = $this->knowledgeThreshold($request);
        $language = Language::query()
            ->enabled()
            ->where('code', (string) $request->query('lang', 'en'))
            ->firstOrFail();

        // One qualifying filter for both the work rows and the texts inside
        // them: readable, completed, in the selected language, carrying a
        // non-null snapshot for this viewer at or above the threshold, with
        // a word list that is not mid-rebuild (a "calculating" text has no
        // comparable score). Unrankable texts are excluded silently.
        $qualifying = function () use ($user, $language, $knowledge, $q): Builder {
            return $this->access->readableQuery($user)
                ->where('entities.status', 'completed')
                ->where('entities.language_id', $language->id)
                ->join('user_entity_word_knowledge as uek', function ($join) use ($user): void {
                    $join->on('uek.entity_id', '=', 'entities.id')
                        ->where('uek.user_id', $user->id);
                })
                ->whereNotNull('uek.score')
                ->where('uek.score', '>=', $knowledge)
                ->whereNotNull('entities.words_indexed_at')
                ->whereNotExists(function ($query): void {
                    $query->selectRaw(1)
                        ->from('entity_sentences as es')
                        ->whereColumn('es.entity_id', 'entities.id')
                        ->whereColumn('es.updated_at', '>', 'entities.words_indexed_at');
                })
                ->when($q !== '', function (Builder $query) use ($q): Builder {
                    $needle = $this->searchNeedle($q);

                    return $query->where(function (Builder $query) use ($needle): void {
                        $query->where('entities.name', 'ilike', $needle)
                            ->orWhere('entities.label', 'ilike', $needle)
                            ->orWhereHas('work', function (Builder $work) use ($needle): void {
                                $work->where(function (Builder $inner) use ($needle): void {
                                    $inner->where('title', 'ilike', $needle)
                                        ->orWhere('author', 'ilike', $needle);
                                });
                            });
                    });
                });
        };

        // One row per work: the subquery keeps the lowest qualifying score,
        // so "least known first" reads down the page (ADR 0075). The join to
        // the grouped subquery also keeps paginate()'s count query correct.
        $works = Work::query()
            ->joinSub(
                $qualifying()
                    ->selectRaw('entities.work_id, min(uek.score) as min_score')
                    ->groupBy('entities.work_id'),
                'work_scores',
                'work_scores.work_id',
                '=',
                'works.id',
            )
            ->with('originalLanguage')
            ->orderBy('work_scores.min_score')
            ->orderBy('works.title')
            ->select('works.*', 'work_scores.min_score')
            ->paginate(self::WORKS_PER_PAGE);

        $workIds = collect($works->items())->pluck('id')->all();

        $entities = $workIds === []
            ? collect()
            : $qualifying()
                ->select('entities.*')
                ->whereIn('entities.work_id', $workIds)
                ->with('language')
                ->withCount('sentences')
                ->addSelect('uek.score as knowledge_score')
                ->orderBy('uek.score')
                ->orderBy('entities.name')
                ->get()
                ->groupBy('work_id');

        return Inertia::render('Recommendations/Index', [
            'q' => $q,
            'knowledge' => $knowledge,
            'lang' => $language->code,
            'languages' => $this->enabledLanguages(),
            'works' => $works->through(function (Work $work) use ($entities): array {
                return [
                    'id' => $work->id,
                    'title' => $work->title,
                    'author' => $work->author,
                    'description' => $work->description,
                    'original_language' => $work->originalLanguage !== null ? [
                        'code' => $work->originalLanguage->code,
                        'name' => $work->originalLanguage->name,
                    ] : null,
                    'min_score' => round((float) $work->min_score, 2),
                    'entities' => $entities->get($work->id, collect())
                        ->map(fn (Entity $entity): array => [
                            'id' => $entity->id,
                            'work_id' => $entity->work_id,
                            'name' => $entity->name,
                            'label' => $entity->label,
                            'description' => $entity->description,
                            'language' => [
                                'code' => $entity->language?->code,
                                'name' => $entity->language?->name,
                            ],
                            'sentences_count' => $entity->sentences_count,
                            'word_knowledge' => round((float) $entity->knowledge_score, 2),
                            'created_at' => $entity->created_at?->toISOString(),
                        ])
                        ->all(),
                ];
            })->items(),
            'meta' => [
                'current_page' => $works->currentPage(),
                'last_page' => $works->lastPage(),
                'total' => $works->total(),
                'per_page' => $works->perPage(),
            ],
            'has_no_familiarity' => ! UserWord::query()->where('user_id', $user->id)->exists(),
            'has_any_scores' => UserEntityWordKnowledge::query()->where('user_id', $user->id)->exists(),
        ]);
    }

    /**
     * The knowledge query parameter, clamped to 0-100; a missing or
     * non-numeric value falls back to the default threshold.
     */
    private function knowledgeThreshold(Request $request): float
    {
        $raw = $request->query('knowledge');

        if ($raw === null || ! is_numeric($raw)) {
            return self::DEFAULT_KNOWLEDGE;
        }

        return round(max(0.0, min(100.0, (float) $raw)), 2);
    }

    /**
     * @return list<array{id: int, code: string, name: string, native_name: ?string}>
     */
    private function enabledLanguages(): array
    {
        return Language::query()
            ->enabled()
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Language $language): array => [
                'id' => $language->id,
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
            ])
            ->all();
    }

    private function searchNeedle(string $q): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
    }
}
