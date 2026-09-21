<?php

namespace App\Http\Controllers;

use App\Classes\EntityAccessService;
use App\Classes\EntityCreationService;
use App\Http\Requests\StoreWorkEntityRequest;
use App\Http\Requests\StoreWorkRequest;
use App\Models\Entity;
use App\Models\Language;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Library — the work-first browse surface. Works are a public catalog
 * (every approved user sees every work, empty ones included; see ADR 0021);
 * entity lists and counts stay scoped to what the user can read.
 */
class LibraryController extends Controller
{
    public function __construct(
        private readonly EntityAccessService $access,
        private readonly EntityCreationService $creation,
    ) {}

    public function index(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $works = Work::query()
            ->with('originalLanguage')
            ->withCount(['entities' => $this->access->readableConstraint($request->user())])
            ->when($q !== '', function (Builder $query) use ($q): Builder {
                $needle = $this->searchNeedle($q);

                return $query->where(function (Builder $query) use ($needle): void {
                    $query->where('title', 'ilike', $needle)
                        ->orWhere('author', 'ilike', $needle);
                });
            })
            ->orderBy('title')
            ->paginate(15);

        return Inertia::render('Library/Index', [
            'q' => $q,
            'works' => $works->through(function (Work $work): array {
                return [
                    'id' => $work->id,
                    'title' => $work->title,
                    'author' => $work->author,
                    'description' => $work->description,
                    'original_language' => $this->languageRef($work),
                    'entities_count' => $work->entities_count,
                    'created_at' => $work->created_at?->toISOString(),
                ];
            })->items(),
            'meta' => [
                'current_page' => $works->currentPage(),
                'last_page' => $works->lastPage(),
                'total' => $works->total(),
                'per_page' => $works->perPage(),
            ],
        ]);
    }

    public function createWork(): Response
    {
        return Inertia::render('Library/CreateWork', [
            'languages' => $this->enabledLanguages(),
        ]);
    }

    public function storeWork(StoreWorkRequest $request): RedirectResponse
    {
        $work = Work::query()->create($request->validated());

        return redirect()->route('library.show', ['work' => $work->id]);
    }

    public function showWork(Request $request, int $work): Response
    {
        $work = Work::query()->with('originalLanguage')->findOrFail($work);
        $q = trim((string) $request->query('q', ''));

        $entities = $this->access->readableQuery($request->user())
            ->where('work_id', $work->id)
            ->with('language')
            ->withCount('sentences')
            ->when($q !== '', function (Builder $query) use ($q): Builder {
                $needle = $this->searchNeedle($q);

                return $query->where(function (Builder $query) use ($needle): void {
                    $query->where('name', 'ilike', $needle)
                        ->orWhere('label', 'ilike', $needle);
                });
            })
            ->orderBy('name')
            ->paginate(15);

        return Inertia::render('Library/ShowWork', [
            'work' => [
                'id' => $work->id,
                'title' => $work->title,
                'author' => $work->author,
                'description' => $work->description,
                'original_language' => $this->languageRef($work),
                'created_at' => $work->created_at?->toISOString(),
            ],
            'q' => $q,
            'entities' => $entities->through(function (Entity $entity): array {
                return [
                    'id' => $entity->id,
                    'name' => $entity->name,
                    'label' => $entity->label,
                    'description' => $entity->description,
                    'signature_status' => $entity->signatureStatus(),
                    'sentences_count' => $entity->sentences_count,
                    'language' => [
                        'code' => $entity->language?->code,
                        'name' => $entity->language?->name,
                    ],
                    'created_at' => $entity->created_at?->toISOString(),
                ];
            })->items(),
            'meta' => [
                'current_page' => $entities->currentPage(),
                'last_page' => $entities->lastPage(),
                'total' => $entities->total(),
                'per_page' => $entities->perPage(),
            ],
        ]);
    }

    public function createEntity(int $work): Response
    {
        $work = Work::query()->findOrFail($work);

        return Inertia::render('Library/CreateEntity', [
            'work' => [
                'id' => $work->id,
                'title' => $work->title,
                'author' => $work->author,
            ],
            'languages' => $this->enabledLanguages(),
        ]);
    }

    public function storeEntity(StoreWorkEntityRequest $request, int $work): RedirectResponse
    {
        $work = Work::query()->findOrFail($work);
        $language = Language::query()->enabled()->findOrFail((int) $request->validated('language_id'));

        $result = $this->creation->create(
            $request->user(),
            $work,
            $language,
            $request->validated(),
            $request->file('file'),
        );

        return $this->redirectFromCreation($result, $language->code);
    }

    /**
     * Map an EntityCreationService outcome to its redirect: the freshly
     * created entity's page, with an extra status when the upload was an
     * exact copy and the entity was cloned with precomputed derivations.
     * Mirrors EntityController::store.
     *
     * @param  array{status: string, entity: Entity, source: ?Entity}  $result
     */
    private function redirectFromCreation(array $result, string $lang): RedirectResponse
    {
        $redirect = redirect()->route('entities.show', ['lang' => $lang, 'entity' => $result['entity']->id]);

        if ($result['status'] === 'created_from_copy') {
            return $redirect->with('status', 'Your upload is an exact copy of an existing text — your own entity was created with sentences and word statistics precomputed.');
        }

        return $redirect;
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

    /**
     * @return array{code: string, name: string}|null
     */
    private function languageRef(Work $work): ?array
    {
        return $work->originalLanguage !== null ? [
            'code' => $work->originalLanguage->code,
            'name' => $work->originalLanguage->name,
        ] : null;
    }

    private function searchNeedle(string $q): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q).'%';
    }
}
