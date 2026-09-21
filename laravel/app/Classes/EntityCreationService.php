<?php

namespace App\Classes;

use App\Jobs\GenerateEntitySignature;
use App\Jobs\ProcessEntityFile;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\EntityWord;
use App\Models\Language;
use App\Models\User;
use App\Models\Work;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Runs the entity-upload pipeline behind both creation entry points: the
 * language-scoped entities form and the Library's work-scoped form.
 *
 * Outcomes of create():
 *  - 'created': a new Restricted entity under the given work, owned by the
 *    uploader (created_by + creator grant), with ProcessEntityFile dispatched
 *    to split sentences and generate derivations in the background;
 *  - 'created_from_copy': another entity with the same file (by sha256 file
 *    hash) already exists in this language, so the uploader still gets their
 *    own entity, cloned from that source — sentences, signature and word
 *    statistics copied verbatim, no Python split or embed calls at all.
 *
 * Uploads never call the Python service synchronously and never fail because
 * of it; the embedding signature is a background derivation used only for
 * cross-language alignment candidates (ADR 0033).
 */
class EntityCreationService
{
    private const CLONE_SENTENCE_CHUNK = 500;

    public function __construct(
        private readonly EntityAccessService $access = new EntityAccessService,
        private readonly EntityTextHasher $hasher = new EntityTextHasher,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated entity fields (name, label, description)
     * @return array{status: 'created'|'created_from_copy', entity: Entity, source: ?Entity}
     */
    public function create(User $user, Work $work, Language $language, array $data, ?UploadedFile $file): array
    {
        $filePath = null;
        $fileHash = null;

        if ($file !== null) {
            $filePath = $file->store("entities/{$language->code}", 'local');
            $fileHash = EntityTextHasher::hashFile(
                Storage::disk('local')->path($filePath),
            );
        }

        if ($filePath !== null) {
            $source = $this->findExactCopySource($fileHash, $language);

            if ($source !== null) {
                $entity = $this->createCloneFrom($user, $work, $language, $data, $filePath, $fileHash, $source);

                return ['status' => 'created_from_copy', 'entity' => $entity, 'source' => $source];
            }
        }

        $entity = Entity::query()->create([
            'work_id' => $work->id,
            'language_id' => $language->id,
            'created_by' => $user->id,
            'name' => $data['name'],
            'label' => $data['label'] ?? null,
            'description' => $data['description'] ?? null,
            'file_path' => $filePath,
            'file_hash' => $fileHash,
            'is_restricted' => true,
            'sentences_updated_at' => now(),
        ]);

        $this->access->grant($user, $entity, null);

        if ($filePath !== null) {
            ProcessEntityFile::dispatch($entity->id, $filePath);
        }

        return ['status' => 'created', 'entity' => $entity, 'source' => null];
    }

    /**
     * The best exact-copy source for a freshly uploaded file: same language,
     * same raw file hash, with sentences already extracted. Prefer a source
     * whose derivations are complete (signature generated, word index built),
     * then the most recent one.
     */
    private function findExactCopySource(string $fileHash, Language $language): ?Entity
    {
        return Entity::query()
            ->where('language_id', $language->id)
            ->where('file_hash', $fileHash)
            ->whereHas('sentences')
            ->orderByRaw('signature IS NULL')
            ->orderByRaw('words_indexed_at IS NULL')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Create the uploader's own entity from an exact-copy source: their
     * metadata, work and file; the source's sentences (content, type, order),
     * signature vector, word statistics and text hash copied verbatim. The
     * clone is fully independent — no foreign keys link it to the source, and
     * deleting either never touches the other.
     */
    private function createCloneFrom(
        User $user,
        Work $work,
        Language $language,
        array $data,
        string $filePath,
        string $fileHash,
        Entity $source,
    ): Entity {
        $sourceHashIsFresh = $source->text_hash !== null
            && ! $this->hasher->isStale($source);

        $entity = Entity::query()->create([
            'work_id' => $work->id,
            'language_id' => $language->id,
            'created_by' => $user->id,
            'name' => $data['name'],
            'label' => $data['label'] ?? null,
            'description' => $data['description'] ?? null,
            'file_path' => $filePath,
            'file_hash' => $fileHash,
            'text_hash' => $source->text_hash,
            'text_hashed_at' => $source->text_hashed_at,
            // The clone's sentences are the source's sentences verbatim, so
            // when the source's hash is fresh the clone's is too; otherwise
            // now() leaves it stale for the refresh scheduler to compute.
            'sentences_updated_at' => $sourceHashIsFresh ? $source->text_hashed_at : now(),
            'signature' => $source->signature,
            'words_indexed_at' => $source->words_indexed_at,
            'is_restricted' => true,
        ]);

        DB::transaction(function () use ($source, $entity): void {
            $now = now();

            $source->sentences()
                ->orderBy('order')
                ->chunk(500, function ($sentences) use ($entity, $now): void {
                    EntitySentence::query()->insert($sentences->map(fn ($sentence): array => [
                        'entity_id' => $entity->id,
                        'sentence_type_id' => $sentence->sentence_type_id,
                        'content' => $sentence->content,
                        'order' => $sentence->order,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                });

            $source->entityWords()
                ->chunk(500, function ($words) use ($entity, $now): void {
                    EntityWord::query()->insert($words->map(fn ($word): array => [
                        'entity_id' => $entity->id,
                        'word_id' => $word->word_id,
                        'l_word' => $word->l_word,
                        'token' => $word->token,
                        'count' => $word->count,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all());
                });
        });

        $this->access->grant($user, $entity, null);

        if ($entity->signature === null) {
            // Source was still mid-pipeline; give the clone its own background
            // embedding pass.
            GenerateEntitySignature::dispatch($entity->id, $filePath);
        }

        return $entity;
    }
}
