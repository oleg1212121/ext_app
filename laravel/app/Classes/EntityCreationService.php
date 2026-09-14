<?php

namespace App\Classes;

use App\Jobs\ProcessEntityFile;
use App\Models\Entity;
use App\Models\Language;
use App\Models\User;
use App\Models\Work;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Runs the entity-upload pipeline behind both creation entry points: the
 * language-scoped entities form and the Library's work-scoped form.
 *
 * Outcomes of create():
 *  - 'created': a new Restricted entity under the given work, with a creator
 *    grant for the user (and ProcessEntityFile dispatched when a file was
 *    uploaded);
 *  - 'matched_existing': the uploaded text's signature matched an existing
 *    entity (>= 0.95 cosine, same language) — the user is granted access to
 *    that entity instead and no new entity is created;
 *  - 'upload_failed': the embedding service was unavailable; nothing is
 *    persisted and the uploaded file is discarded.
 */
class EntityCreationService
{
    public function __construct(
        private readonly EntityAccessService $access = new EntityAccessService,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated entity fields (name, label, description)
     * @return array{status: 'created'|'matched_existing'|'upload_failed', entity: ?Entity, similarity: ?float}
     */
    public function create(User $user, Work $work, Language $language, array $data, ?UploadedFile $file): array
    {
        $filePath = null;
        if ($file !== null) {
            $filePath = $file->store("entities/{$language->code}", 'local');
        }

        $signature = null;

        if ($filePath !== null) {
            $result = TextSignatureService::create()
                ->findSimilarExisting(TextSignatureService::readFileFromLocalPath($filePath), $language);

            if ($result['signature'] === null) {
                Storage::disk('local')->delete($filePath);

                return ['status' => 'upload_failed', 'entity' => null, 'similarity' => null];
            }

            if ($result['entity'] !== null) {
                $similarity = (float) $result['similarity'];

                $this->access->grant($user, $result['entity'], $similarity);
                Storage::disk('local')->delete($filePath);

                return ['status' => 'matched_existing', 'entity' => $result['entity'], 'similarity' => $similarity];
            }

            $signature = $result['signature'];
        }

        $entity = Entity::query()->create([
            'work_id' => $work->id,
            'language_id' => $language->id,
            'name' => $data['name'],
            'label' => $data['label'] ?? null,
            'description' => $data['description'] ?? null,
            'file_path' => $filePath,
            'is_restricted' => true,
            'signature' => $signature !== null ? json_encode($signature) : null,
        ]);

        $this->access->grant($user, $entity, null);

        if ($filePath !== null) {
            ProcessEntityFile::dispatch($entity->id, $filePath);
        }

        return ['status' => 'created', 'entity' => $entity, 'similarity' => null];
    }
}
