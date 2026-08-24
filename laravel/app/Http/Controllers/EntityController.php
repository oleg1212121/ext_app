<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEntityRequest;
use App\Jobs\ProcessEntityFile;
use App\Models\EnEntity;
use App\Models\EnRuEntityMatch;
use App\Models\Language;
use App\Models\RuEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class EntityController extends Controller
{
    public function index(): Response
    {
        $languages = Language::query()
            ->enabled()
            ->orderBy('sort_order')
            ->get()
            ->map(function (Language $language): array {
                return [
                    'code' => $language->code,
                    'name' => $language->name,
                    'native_name' => $language->native_name,
                    'entity_count' => $this->queryForLanguage($language->code)->count(),
                ];
            });

        return Inertia::render('Entities/Index', [
            'languages' => $languages,
        ]);
    }

    public function list(string $lang): Response
    {
        $language = $this->resolveLanguage($lang);

        $entities = $this->queryForLanguage($lang)
            ->withCount('sentences')
            ->orderBy('name')
            ->paginate(15);

        return Inertia::render('Entities/List', [
            'lang' => $lang,
            'language' => [
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
            ],
            'entities' => $entities->through(function (EnEntity|RuEntity $entity): array {
                return [
                    'id' => $entity->id,
                    'name' => $entity->name,
                    'description' => $entity->description,
                    'signature_status' => $this->signatureStatus($entity),
                    'sentences_count' => $entity->sentences_count,
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

    public function create(string $lang): Response
    {
        $language = $this->resolveLanguage($lang);

        return Inertia::render('Entities/Create', [
            'lang' => $lang,
            'language' => [
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
            ],
        ]);
    }

    public function store(StoreEntityRequest $request, string $lang): RedirectResponse
    {
        $this->resolveLanguage($lang);

        $data = $request->validated();

        $filePath = null;
        if ($request->hasFile('file')) {
            $filePath = $request->file('file')->store("entities/{$lang}", 'local');
        }

        $entity = $this->queryForLanguage($lang)->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'file_path' => $filePath,
        ]);

        if ($filePath !== null) {
            ProcessEntityFile::dispatch($entity->id, $filePath, $lang);
        }

        return redirect()->route('entities.show', ['lang' => $lang, 'entity' => $entity->id]);
    }

    public function show(string $lang, int $entityId): Response
    {
        $language = $this->resolveLanguage($lang);

        $entity = $this->queryForLanguage($lang)
            ->withCount('sentences')
            ->findOrFail($entityId);

        $entityMatches = EnRuEntityMatch::query()
            ->where(function (Builder $query) use ($lang, $entityId): void {
                if ($lang === 'en') {
                    $query->where('en_entity_id', $entityId);
                } else {
                    $query->where('ru_entity_id', $entityId);
                }
            })
            ->get(['id', 'status'])
            ->map(fn (EnRuEntityMatch $match): array => [
                'id' => $match->id,
                'status' => $match->status,
            ]);

        $sentences = $entity->sentences()
            ->with('sentenceType')
            ->orderBy('order')
            ->paginate(20);

        return Inertia::render('Entities/Show', [
            'lang' => $lang,
            'language' => [
                'code' => $language->code,
                'name' => $language->name,
                'native_name' => $language->native_name,
            ],
            'entity' => [
                'id' => $entity->id,
                'name' => $entity->name,
                'description' => $entity->description,
                'file_path' => $entity->file_path,
                'signature_status' => $this->signatureStatus($entity),
                'sentences_count' => $entity->sentences_count,
                'created_at' => $entity->created_at?->toISOString(),
                'updated_at' => $entity->updated_at?->toISOString(),
            ],
            'entityMatches' => $entityMatches,
            'sentences' => $sentences->through(function (object $sentence): array {
                return [
                    'id' => $sentence->id,
                    'order' => $sentence->order,
                    'content' => $sentence->content,
                    'type' => $sentence->sentenceType?->name,
                ];
            })->items(),
            'sentences_meta' => [
                'current_page' => $sentences->currentPage(),
                'last_page' => $sentences->lastPage(),
                'total' => $sentences->total(),
                'per_page' => $sentences->perPage(),
            ],
        ]);
    }

    private function resolveLanguage(string $lang): Language
    {
        return Language::query()
            ->enabled()
            ->where('code', $lang)
            ->firstOrFail();
    }

    private function queryForLanguage(string $lang): Builder
    {
        return match ($lang) {
            'en' => EnEntity::query(),
            'ru' => RuEntity::query(),
            default => abort(404),
        };
    }

    private function signatureStatus(EnEntity|RuEntity $entity): string
    {
        if ($entity->signature !== null) {
            return 'generated';
        }

        if ($entity->file_path !== null) {
            return 'pending';
        }

        return 'none';
    }
}
