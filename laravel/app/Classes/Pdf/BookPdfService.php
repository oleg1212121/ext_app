<?php

namespace App\Classes\Pdf;

use App\Classes\EntityTextHasher;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\Language;
use App\Models\PdfRender;
use App\Models\Work;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Illuminate\Support\Facades\Storage;

/**
 * Renders and caches book-style, single-language PDFs.
 *
 * One render exists per subject: a `pdf_renders` row (partial-unique per FK)
 * plus the file at a fixed path on the `local` disk. The row's content_hash
 * is the render digest — the subject's fresh text hash plus the title-page
 * inputs — so a request whose digest matches the stored row and whose file
 * still exists is served straight from storage; anything else re-renders and
 * updates the row in place. Stale files are overwritten, never accumulated.
 */
class BookPdfService
{
    /**
     * Render-input version. Bump it when the layout changes in a way that
     * must invalidate every cached render at once.
     */
    private const DIGEST_VERSION = 'entity-book/v1';

    private array $tempIllustrations = [];

    public function __construct(private readonly EntityTextHasher $textHasher) {}

    /**
     * The render digest: sha256 over the entity's fresh text hash
     * (refreshIfStale — illustrations participate through their image_hash
     * markers already) plus every title-page input.
     */
    public function entityDigest(Entity $entity): string
    {
        $textHash = $this->textHasher->refreshIfStale($entity);

        $entity->loadMissing('work');

        return hash('sha256', implode("\n", [
            self::DIGEST_VERSION,
            'text_hash:'.$textHash,
            'name:'.$entity->name,
            'label:'.($entity->label ?? ''),
            'work_title:'.($entity->work?->title ?? ''),
            'work_author:'.($entity->work?->author ?? ''),
        ]));
    }

    /**
     * Render — or fetch from cache — the entity's book PDF and return its
     * `local`-disk path.
     */
    public function entityPdf(Entity $entity): string
    {
        $digest = $this->entityDigest($entity);
        $path = "pdfs/entities/{$entity->id}.pdf";
        $disk = Storage::disk('local');

        $render = PdfRender::query()->where('entity_id', $entity->id)->first();

        if ($render !== null
            && $render->content_hash === $digest
            && $disk->exists($render->path)) {
            return $render->path;
        }

        // Clear any stale file before the fresh render rewrites the
        // subject's fixed path.
        if ($render !== null) {
            $disk->delete($render->path);
        }

        $dompdf = $this->entityDompdf($entity);

        try {
            $dompdf->render();

            // The footer goes on after render(): the canvas page_script()
            // callback applies to the pages that exist when it runs, i.e.
            // all of them.
            $this->drawPageNumbers($dompdf);

            $disk->put($path, $dompdf->output());
        } finally {
            $this->discardTempIllustrations();
        }

        $render ??= new PdfRender;
        $render->forceFill([
            'entity_id' => $entity->id,
            'entity_match_id' => null,
            'content_hash' => $digest,
            'path' => $path,
            'size_bytes' => $disk->size($path),
            'generated_at' => now(),
        ])->save();

        return $path;
    }

    /**
     * The rendered book layout for one entity: the title-page inputs plus
     * every sentence as a view row (type, plain content, embeddable image
     * path). Public so the view can be exercised without a full dompdf
     * render.
     *
     * @return array{work: Work, entity: Entity, language: Language, sentences: list<array{type: string, content: ?string, image: ?string}>}
     */
    public function entityViewData(Entity $entity): array
    {
        $entity->loadMissing(['work', 'language']);

        $sentences = $entity->sentences()
            ->with('sentenceType')
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (EntitySentence $sentence): array => [
                'type' => $sentence->sentenceType?->name ?? 'sentence',
                'content' => $sentence->content,
                'image' => $this->embeddableImage($sentence),
            ])
            ->all();

        return [
            'work' => $entity->work,
            'entity' => $entity,
            'language' => $entity->language,
            'sentences' => $sentences,
        ];
    }

    /**
     * Absolute filesystem path of an embeddable image for the sentence, or
     * null when it cannot be embedded. dompdf loads local files by path
     * while remote URLs stay disabled. WebP is not among dompdf's supported
     * image formats: GD-convert to a temporary PNG; when GD lacks webp (or
     * the bytes do not decode), the caller degrades to the caption.
     */
    private function embeddableImage(EntitySentence $sentence): ?string
    {
        if ($sentence->image_path === null) {
            return null;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($sentence->image_path)) {
            return null;
        }

        $absolute = $disk->path($sentence->image_path);

        if (($sentence->image_mime ?? null) !== 'image/webp') {
            return $absolute;
        }

        return $this->webpToTempPng($absolute);
    }

    private function webpToTempPng(string $absolute): ?string
    {
        if (! function_exists('imagecreatefromwebp') || ! function_exists('imagepng')) {
            return null;
        }

        $image = @imagecreatefromwebp($absolute);

        if ($image === false) {
            return null;
        }

        // Composite onto white (the page's paper): WebP alpha would
        // otherwise render black in the PNG.
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        $temp = tempnam(sys_get_temp_dir(), 'bookpdf-').'.png';
        imagepng($canvas, $temp);
        imagedestroy($canvas);

        $this->tempIllustrations[] = $temp;

        return $temp;
    }

    private function discardTempIllustrations(): void
    {
        foreach ($this->tempIllustrations as $temp) {
            @unlink($temp);
        }

        $this->tempIllustrations = [];
    }

    private function entityDompdf(Entity $entity): Dompdf
    {
        $pdf = Pdf::loadView('pdf.entity-book', $this->entityViewData($entity));
        // DejaVu Serif covers the app's languages (en/ru/be incl. Cyrillic);
        // remote loading stays off — illustrations embed by local path only.
        $pdf->setOption('defaultFont', 'dejavu serif');
        $pdf->setOption('isRemoteEnabled', false);

        return $pdf->getDomPDF();
    }

    /**
     * Centered "n / m" footer on every page, drawn through the dompdf
     * canvas page_script() callback.
     */
    private function drawPageNumbers(Dompdf $dompdf): void
    {
        $dompdf->getCanvas()->page_script(
            function (int $pageNumber, int $pageCount, Canvas $canvas, FontMetrics $fontMetrics): void {
                $font = $fontMetrics->getFont('dejavu sans', 'normal');
                $text = "{$pageNumber} / {$pageCount}";
                $width = $fontMetrics->getTextWidth($text, $font, 9);
                $canvas->text(
                    ($canvas->get_width() - $width) / 2,
                    $canvas->get_height() - 28,
                    $text,
                    $font,
                    9,
                    [0.29, 0.29, 0.29],
                );
            },
        );
    }
}
