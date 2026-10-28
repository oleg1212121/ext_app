<?php

namespace App\Http\Controllers;

use App\Classes\EntityAccessService;
use App\Classes\Pdf\BookPdfService;
use App\Models\EntityMatch;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AlignmentPdfController extends Controller
{
    public function __construct(private readonly BookPdfService $books) {}

    /**
     * Download the alignment's bilingual book PDF. The guards mirror the
     * show page exactly (AlignmentController::show): the work comes via
     * aEntity, the both-sides read rule — plus the completed rule, since a
     * book of an unfinished alignment is not a book yet. Attachment
     * disposition — the browser downloads the file, it never navigates to
     * it.
     */
    public function show(Work $work, EntityMatch $entityMatch): StreamedResponse
    {
        abort_unless($entityMatch->aEntity?->work_id === $work->id, 404);

        abort_unless((new EntityAccessService)->canReadMatch(auth()->user(), $entityMatch), 403);

        abort_unless($entityMatch->status === 'completed', 404);

        $path = $this->books->entityMatchPdf($entityMatch);

        return Storage::disk('local')->download(
            $path,
            "{$work->title} — {$entityMatch->aEntity->name} ⇄ {$entityMatch->bEntity->name}.pdf",
            [
                'Content-Type' => 'application/pdf',
                'Cache-Control' => 'private',
            ],
        );
    }
}
