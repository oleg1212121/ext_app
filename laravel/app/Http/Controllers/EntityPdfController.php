<?php

namespace App\Http\Controllers;

use App\Classes\EntityAccessService;
use App\Classes\Pdf\BookPdfService;
use App\Models\Entity;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EntityPdfController extends Controller
{
    public function __construct(private readonly BookPdfService $books) {}

    /**
     * Download the entity's book PDF. The guards mirror the show page
     * exactly (EntityController::show): a work-scoped URL and the read
     * rule, then the cached render. Attachment disposition — the browser
     * downloads the file, it never navigates to it.
     */
    public function show(Work $work, Entity $entity): StreamedResponse
    {
        abort_unless($entity->work_id === $work->id, 404);

        abort_unless((new EntityAccessService)->canRead(auth()->user(), $entity), 403);

        $path = $this->books->entityPdf($entity);

        return Storage::disk('local')->download(
            $path,
            "{$work->title} — {$entity->name}.pdf",
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private',
            ],
        );
    }
}
