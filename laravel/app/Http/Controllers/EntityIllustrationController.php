<?php

namespace App\Http\Controllers;

use App\Classes\EntityAccessService;
use App\Models\EntitySentence;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves an entity sentence's illustration from the private local disk
 * behind the entity's read access (ADR 0050): a Restricted entity's
 * illustrations stay unreachable for users without a grant. The <img> tags
 * on the reading surfaces are same-origin, so the session cookie rides
 * along and the access check holds without signed URLs.
 */
class EntityIllustrationController extends Controller
{
    public function show(int $sentence): StreamedResponse
    {
        $sentence = EntitySentence::query()
            ->with('entity')
            ->whereNotNull('image_path')
            ->findOrFail($sentence);

        $entity = $sentence->entity;

        abort_unless($entity !== null, 404);

        abort_unless((new EntityAccessService)->canRead(auth()->user(), $entity), 403);

        abort_unless(Storage::disk('local')->exists($sentence->image_path), 404);

        return Storage::disk('local')->response($sentence->image_path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
