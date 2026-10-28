<?php

namespace App\Classes\Pdf;

use App\Classes\EntityTextHasher;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\Language;
use App\Models\MeaningMatch;
use App\Models\PdfRender;
use App\Models\Work;
use Barryvdh\DomPDF\Facade\Pdf;
use Closure;
use Dompdf\Canvas;
use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Renders and caches book-style PDFs: single-language entity books and
 * bilingual alignment books.
 *
 * One render exists per subject: a `pdf_renders` row (partial-unique per FK)
 * plus the file at a fixed path on the `local` disk. The row's content_hash
 * is the render digest — the subject's fresh text hashes plus the title-page
 * inputs — so a request whose digest matches the stored row and whose file
 * still exists is served straight from storage; anything else re-renders and
 * updates the row in place. Stale files are overwritten, never accumulated.
 */
class BookPdfService
{
    /**
     * Render-input version of the single-language entity books. Bump it when
     * the layout changes in a way that must invalidate every cached render
     * at once.
     */
    private const DIGEST_VERSION = 'entity-book/v1';

    /**
     * Render-input version of the bilingual alignment books. Bump it when
     * the layout changes in a way that must invalidate every cached render
     * at once.
     */
    private const MATCH_DIGEST_VERSION = 'alignment-book/v1';

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
        return $this->renderOrCached(
            'entity_id',
            $entity->id,
            $this->entityDigest($entity),
            "pdfs/entities/{$entity->id}.pdf",
            fn (): Dompdf => $this->bookDompdf('pdf.entity-book', $this->entityViewData($entity)),
        );
    }

    /**
     * The match's render digest: sha256 over both entities' fresh digests
     * (entityDigest on each side), the title-page metadata, and the ordered
     * pairing — each meaning match's order plus its side-a and side-b
     * sentence ids in document order. Row create/delete/move/relink and any
     * sentence edit on either side all change it; `entity_matches`.
     * `updated_at` churn (approve/reject, similarity) deliberately does not.
     */
    public function matchDigest(EntityMatch $match): string
    {
        $match->loadMissing(['aEntity', 'bEntity']);

        $aEntity = $match->aEntity;
        $bEntity = $match->bEntity;
        $work = $aEntity?->work ?: $bEntity?->work;

        $lines = [
            self::MATCH_DIGEST_VERSION,
            'a_digest:'.($aEntity !== null ? $this->entityDigest($aEntity) : ''),
            'b_digest:'.($bEntity !== null ? $this->entityDigest($bEntity) : ''),
            'work_title:'.($work?->title ?? ''),
            'work_author:'.($work?->author ?? ''),
            'a_name:'.($aEntity?->name ?? ''),
            'a_label:'.($aEntity?->label ?? ''),
            'b_name:'.($bEntity?->name ?? ''),
            'b_label:'.($bEntity?->label ?? ''),
        ];

        foreach ($this->meaningMatches($match) as $meaningMatch) {
            $lines[] = 'row '.$meaningMatch->order
                .' | a: '.implode(',', $this->sideSentenceIds($meaningMatch, 'a'))
                .' | b: '.implode(',', $this->sideSentenceIds($meaningMatch, 'b'));
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * Render — or fetch from cache — the match's bilingual book PDF and
     * return its `local`-disk path.
     */
    public function entityMatchPdf(EntityMatch $match): string
    {
        return $this->renderOrCached(
            'entity_match_id',
            $match->id,
            $this->matchDigest($match),
            "pdfs/matches/{$match->id}.pdf",
            fn (): Dompdf => $this->bookDompdf('pdf.alignment-book', $this->alignmentViewData($match)),
        );
    }

    /**
     * The rendered bilingual layout for one match: the title-page inputs
     * plus the ordered rows, each carrying both sides' sentence cells in
     * document order. The left column shows the work's original side when
     * one is determinable, else canonical side a — the original marker
     * rides the left side, and there is none when both are translations.
     * Public so the view can be exercised without a full dompdf render.
     *
     * @return array{work: ?Work, left: array{entity: ?Entity, language: ?Language, is_original: bool}, right: array{entity: ?Entity, language: ?Language, is_original: bool}, rows: list<array{order: int, left: list<array{type: string, content: ?string, image: ?string}>, right: list<array{type: string, content: ?string, image: ?string}>}>}
     */
    public function alignmentViewData(EntityMatch $match): array
    {
        $match->loadMissing(['aEntity.language', 'bEntity.language', 'aEntity.work']);

        $originalSide = $match->originalSide();
        $leftSide = $originalSide ?? 'a';
        $rightSide = $leftSide === 'a' ? 'b' : 'a';

        [$left, $right] = $leftSide === 'a'
            ? [$match->aEntity, $match->bEntity]
            : [$match->bEntity, $match->aEntity];

        $rows = [];

        foreach ($this->meaningMatches($match) as $meaningMatch) {
            $rows[] = [
                'order' => (int) $meaningMatch->order,
                'left' => $this->cellSentences($this->sideSentences($meaningMatch, $leftSide)),
                'right' => $this->cellSentences($this->sideSentences($meaningMatch, $rightSide)),
            ];
        }

        return [
            'work' => $match->aEntity?->work ?? $match->bEntity?->work,
            'left' => [
                'entity' => $left,
                'language' => $left?->language,
                'is_original' => $originalSide !== null,
            ],
            'right' => [
                'entity' => $right,
                'language' => $right?->language,
                'is_original' => false,
            ],
            'rows' => $rows,
        ];
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
            ->all();

        return [
            'work' => $entity->work,
            'entity' => $entity,
            'language' => $entity->language,
            'sentences' => $this->cellSentences($sentences),
        ];
    }

    /**
     * The cache-or-render pipeline both books share: the cached path on a
     * digest hit, otherwise the fresh render written to the subject's fixed
     * path with the cache row updated in place.
     *
     * @param  Closure(): Dompdf  $dompdf
     */
    private function renderOrCached(string $foreignKey, int $subjectId, string $digest, string $path, Closure $dompdf): string
    {
        ['render' => $render, 'hit' => $hit] = $this->cacheState($foreignKey, $subjectId, $digest);

        if ($hit !== null) {
            return $hit;
        }

        $document = $dompdf();

        try {
            $document->render();

            // The footer goes on after render(): the canvas page_script()
            // callback applies to the pages that exist when it runs, i.e.
            // all of them.
            $this->drawPageNumbers($document);

            Storage::disk('local')->put($path, $document->output());
        } finally {
            $this->discardTempIllustrations();
        }

        $this->storeRender($render, $foreignKey, $subjectId, $digest, $path);

        return $path;
    }

    /**
     * The subject's cache row with the stale-file bookkeeping done: the
     * render path when the stored digest still matches and the file is on
     * disk, otherwise the row (kept for the in-place update) with any stale
     * file cleared before the fresh render rewrites the subject's fixed
     * path.
     *
     * @return array{render: ?PdfRender, hit: ?string}
     */
    private function cacheState(string $foreignKey, int $subjectId, string $digest): array
    {
        $render = PdfRender::query()->where($foreignKey, $subjectId)->first();

        if ($render !== null
            && $render->content_hash === $digest
            && Storage::disk('local')->exists($render->path)) {
            return ['render' => $render, 'hit' => $render->path];
        }

        if ($render !== null) {
            Storage::disk('local')->delete($render->path);
        }

        return ['render' => $render, 'hit' => null];
    }

    /**
     * Insert — or update in place — the subject's cache row after a fresh
     * render overwrote its fixed path. Exactly one FK is set; the other
     * subject kind's column is force-cleared so an updated row can never
     * keep a stale subject id.
     */
    private function storeRender(?PdfRender $render, string $foreignKey, int $subjectId, string $digest, string $path): void
    {
        $render ??= new PdfRender;
        $render->forceFill([
            'entity_id' => null,
            'entity_match_id' => null,
            $foreignKey => $subjectId,
            'content_hash' => $digest,
            'path' => $path,
            'size_bytes' => Storage::disk('local')->size($path),
            'generated_at' => now(),
        ])->save();
    }

    /**
     * The match's meaning matches in render order: `order`, then id as the
     * deterministic tiebreaker, with every junction and sentence loaded —
     * the same eager-loading shape as the reader's row query.
     */
    private function meaningMatches(EntityMatch $match): Collection
    {
        return $match->meaningMatches()
            ->with(['sentenceMeaningMatches.entitySentence.sentenceType'])
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    /**
     * The side's junctioned sentence ids in document order — the pairing
     * side of the render digest.
     *
     * @return list<int>
     */
    private function sideSentenceIds(MeaningMatch $meaningMatch, string $side): array
    {
        return array_map(
            fn (EntitySentence $sentence): int => (int) $sentence->id,
            $this->sideSentences($meaningMatch, $side),
        );
    }

    /**
     * The side's junctioned sentences in document order — the same shape
     * rule as ReadingRowsPresenter::sideSentences: pivot rows for the side,
     * sorted by entitySentence order, skipping empty-content non-image
     * junctions.
     *
     * @return list<EntitySentence>
     */
    private function sideSentences(MeaningMatch $meaningMatch, string $side): array
    {
        return $meaningMatch->sentenceMeaningMatches
            ->where('side', $side)
            ->sortBy(fn ($junction) => $junction->entitySentence?->order ?? 0)
            ->map(fn ($junction) => $junction->entitySentence)
            ->filter(fn (?EntitySentence $sentence): bool => $sentence !== null
                && ($sentence->image_path !== null || $sentence->content !== ''))
            ->values()
            ->all();
    }

    /**
     * The view rows for one cell: every sentence with its plain content and
     * embeddable image state.
     *
     * @param  list<EntitySentence>  $sentences
     * @return list<array{type: string, content: ?string, image: ?string}>
     */
    private function cellSentences(array $sentences): array
    {
        return array_map(
            fn (EntitySentence $sentence): array => [
                'type' => $sentence->sentenceType?->name ?? 'sentence',
                'content' => $sentence->content,
                'image' => $this->embeddableImage($sentence),
            ],
            $sentences,
        );
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

    private function bookDompdf(string $view, array $data): Dompdf
    {
        $pdf = Pdf::loadView($view, $data);
        // DejaVu covers the app's languages (en/ru/be incl. Cyrillic); remote
        // loading stays off — illustrations embed by local path only.
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
