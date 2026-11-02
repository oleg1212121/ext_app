<?php

use App\Classes\Pdf\BookPdfService;
use App\Models\Entity;
use App\Models\EntitySentence;
use App\Models\PdfRender;
use App\Models\SentenceType;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Database\Seeders\SentenceTypeSeeder;
use Illuminate\Support\Facades\Storage;

if (! function_exists('grantPdfAccess')) {
    function grantPdfAccess(User $user, Entity $entity): void
    {
        $entity->grantedUsers()->attach($user->id);
    }
}

if (! function_exists('pdfTestPng')) {
    /**
     * A genuine PNG built chunk by chunk (CRCs correct by construction), so
     * dompdf's image sniffing accepts it.
     */
    function pdfTestPng(int $width = 3, int $height = 2): string
    {
        $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        $raw = '';

        for ($row = 0; $row < $height; $row++) {
            $raw .= "\x00".str_repeat("\x20", $width * 3);
        }

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('N2C5', $width, $height, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress($raw))
            .$chunk('IEND', '');
    }
}

if (! function_exists('pdfEntitySentence')) {
    function pdfEntitySentence(Entity $entity, string $typeName, string $content, int $order, array $attributes = []): EntitySentence
    {
        return EntitySentence::create([
            'entity_id' => $entity->id,
            'sentence_type_id' => SentenceType::query()->where('name', $typeName)->value('id'),
            'content' => $content,
            'order' => $order,
            ...$attributes,
        ]);
    }
}

beforeEach(function () {
    createLanguages(['en']);
    app(SentenceTypeSeeder::class)->run();
    Storage::fake('local');
});

afterEach(function () {
    Carbon::setTestNow();
});

// ─── guards ──────────────────────────────────────────────────────────────────

test('guests are redirected from the pdf download', function () {
    $entity = createEntity('en');

    $this->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertRedirect(route('login'));
});

test('unapproved users are redirected from the pdf download', function () {
    $entity = createEntity('en');
    $user = User::factory()->create(['is_approved' => false]);

    $this->actingAs($user)
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertRedirect('/pending-approval');
});

test('the pdf route 404s when the URL names another work', function () {
    $entity = createEntity('en', null, ['name' => 'Detail']);
    $otherWork = createWork(['title' => 'Other Work']);

    $this->actingAs(approvedUser())
        ->get("/works/{$otherWork->id}/entities/{$entity->id}/pdf")
        ->assertNotFound();

    expect(PdfRender::query()->count())->toBe(0);
});

test('the pdf route 403s for an unreadable restricted entity until it is granted', function () {
    $entity = createEntity('en', null, ['is_restricted' => true]);
    $user = approvedUser();

    $this->actingAs($user)
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertForbidden();

    expect(PdfRender::query()->count())->toBe(0);

    grantPdfAccess($user, $entity);

    $this->actingAs($user)
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertOk();
});

// ─── download shape ──────────────────────────────────────────────────────────

test('the pdf download streams an attachment named after the work and entity', function () {
    $work = createWork(['title' => 'War and Peace']);
    $entity = createEntity('en', $work, ['name' => 'Chapter One']);
    pdfEntitySentence($entity, 'sentence', 'Plain text.', 1);

    $response = $this->actingAs(approvedUser())
        ->get("/works/{$work->id}/entities/{$entity->id}/pdf");

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'private');

    $expectedName = 'War and Peace — Chapter One.pdf';
    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toStartWith('attachment')
        ->and($disposition)->toContain("filename*=utf-8''".rawurlencode($expectedName))
        ->and($response->streamedContent())->toStartWith('%PDF');

    $render = PdfRender::query()->sole();

    expect($render->entity_id)->toBe($entity->id)
        ->and($render->entity_match_id)->toBeNull()
        ->and($render->content_hash)->toHaveLength(64)
        ->and($render->path)->toBe("pdfs/entities/{$entity->id}.pdf")
        ->and($render->size_bytes)->toBeGreaterThan(0)
        ->and($render->generated_at)->not->toBeNull();

    Storage::disk('local')->assertExists($render->path);
    expect(Storage::disk('local')->get($render->path))->toStartWith('%PDF');
});

test('the rendered pdf embeds cyrillic text with the dejavu serif body font', function () {
    $work = createWork(['title' => 'Война и мир']);
    $entity = createEntity('en', $work, ['name' => 'Глава первая']);
    pdfEntitySentence($entity, 'sentence', 'Привет, мир.', 1);

    $this->actingAs(approvedUser())
        ->get("/works/{$work->id}/entities/{$entity->id}/pdf")
        ->assertOk();

    $bytes = Storage::disk('local')->get("pdfs/entities/{$entity->id}.pdf");

    // The body renders in DejaVu Serif (bundled Cyrillic coverage); a
    // Helvetica fallback would mean the DejaVu resolution failed.
    expect($bytes)->toStartWith('%PDF')
        ->and($bytes)->toContain('DejaVuSerif')
        ->and($bytes)->not->toContain('Helvetica');
});

test('page numbers are drawn by the dompdf canvas footer callback', function () {
    $entity = createEntity('en');

    // Plain sentences only: nothing on the page uses the sans face except
    // the footer, so its embedded subset proves the footer drew.
    foreach (range(1, 12) as $i) {
        pdfEntitySentence(
            $entity,
            'sentence',
            'A reasonably long sentence so the document needs more than one page eventually, number '.$i.'.',
            $i,
        );
    }

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertOk();

    $bytes = Storage::disk('local')->get("pdfs/entities/{$entity->id}.pdf");

    expect($bytes)->toContain('DejaVuSans')
        ->and(substr_count($bytes, '/Type /Page'))->toBeGreaterThanOrEqual(2);
});

// ─── cache ───────────────────────────────────────────────────────────────────

test('a second request is served from storage without re-rendering', function () {
    // Freeze before creating anything: sentences_updated_at must live on
    // the frozen clock for the staleness comparisons below to be exact.
    Carbon::setTestNow('2026-10-28 10:00:00');

    $entity = createEntity('en');
    pdfEntitySentence($entity, 'sentence', 'Plain text.', 1);

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertOk();

    $first = PdfRender::query()->sole();

    expect($first->generated_at)->toEqual(Carbon::parse('2026-10-28 10:00:00'));

    Carbon::setTestNow('2026-10-28 10:05:00');

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertOk();

    $second = PdfRender::query()->sole();

    expect($second->id)->toBe($first->id)
        ->and($second->generated_at)->toEqual($first->generated_at)
        ->and($second->content_hash)->toBe($first->content_hash);
});

test('editing a sentence changes the digest and re-renders the row in place', function () {
    // Freeze before creating anything: sentences_updated_at must live on
    // the frozen clock for the staleness comparisons below to be exact.
    Carbon::setTestNow('2026-10-28 10:00:00');

    $entity = createEntity('en');
    $sentence = pdfEntitySentence($entity, 'sentence', 'Before.', 1);

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertOk();

    $first = PdfRender::query()->sole();

    Carbon::setTestNow('2026-10-28 10:05:00');

    // The Eloquent save bumps entities.sentences_updated_at (model events),
    // which makes the stored text hash stale.
    $sentence->update(['content' => 'After.']);

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertOk();

    $second = PdfRender::query()->sole();

    expect($second->id)->toBe($first->id)
        ->and($second->content_hash)->not->toBe($first->content_hash)
        ->and($second->generated_at)->toEqual(Carbon::parse('2026-10-28 10:05:00'));
});

test('a deleted cache file forces a re-render on the next request', function () {
    Carbon::setTestNow('2026-10-28 10:00:00');

    $entity = createEntity('en');
    pdfEntitySentence($entity, 'sentence', 'Plain text.', 1);

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertOk();

    $first = PdfRender::query()->sole();
    Storage::disk('local')->delete($first->path);

    Carbon::setTestNow('2026-10-28 10:05:00');

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertOk();

    $second = PdfRender::query()->sole();

    expect($second->id)->toBe($first->id)
        ->and($second->generated_at)->toEqual(Carbon::parse('2026-10-28 10:05:00'));

    Storage::disk('local')->assertExists($second->path);
});

// ─── digest ──────────────────────────────────────────────────────────────────

test('the digest tracks the text hash and the title-page inputs', function () {
    // Sentence-set staleness compares truncated (second-precision)
    // timestamps, so every mutation that must read as stale happens on the
    // frozen clock, a minute apart.
    Carbon::setTestNow('2026-10-28 10:00:00');

    $work = createWork(['title' => 'War and Peace', 'author' => 'Leo Tolstoy']);
    $entity = createEntity('en', $work, ['name' => 'Chapter One']);
    pdfEntitySentence($entity, 'sentence', 'Plain text.', 1);

    $service = app(BookPdfService::class);

    $digest = $service->entityDigest($entity);

    // Deterministic for unchanged inputs.
    expect($service->entityDigest($entity))->toBe($digest);

    // A sentence edit flows through the refreshed text hash...
    Carbon::setTestNow('2026-10-28 10:01:00');
    pdfEntitySentence($entity, 'sentence', 'Another.', 2);
    $afterSentence = $service->entityDigest($entity->refresh());

    // ...and each title-page input moves the digest on its own.
    Carbon::setTestNow('2026-10-28 10:02:00');
    $entity->refresh()->update(['name' => 'Renamed']);
    $afterName = $service->entityDigest($entity);

    Carbon::setTestNow('2026-10-28 10:03:00');
    $entity->refresh()->update(['label' => 'Volume I']);
    $afterLabel = $service->entityDigest($entity);

    Carbon::setTestNow('2026-10-28 10:04:00');
    Work::query()->whereKey($work->id)->update(['title' => 'Other Title']);
    $afterTitle = $service->entityDigest($entity->refresh());

    Carbon::setTestNow('2026-10-28 10:05:00');
    Work::query()->whereKey($work->id)->update(['author' => 'Someone Else']);
    $afterAuthor = $service->entityDigest($entity->refresh());

    expect($afterSentence)->not->toBe($digest)
        ->and($afterName)->not->toBe($afterSentence)
        ->and($afterLabel)->not->toBe($afterName)
        ->and($afterTitle)->not->toBe($afterLabel)
        ->and($afterAuthor)->not->toBe($afterTitle);
});

// ─── book composition ────────────────────────────────────────────────────────

test('body sentences concatenate into paragraphs split at the paragraph type', function () {
    $entity = createEntity('en');
    pdfEntitySentence($entity, 'sentence', '  First.  ', 1);
    pdfEntitySentence($entity, 'sentence', 'Second continues.  ', 2);
    pdfEntitySentence($entity, 'paragraph', 'Third opens fresh.', 3);
    pdfEntitySentence($entity, 'paragraph', 'Fourth opens another.', 4);

    $blocks = app(BookPdfService::class)->entityViewData($entity->refresh())['blocks'];

    // Trimmed sentences, single spaces between them; every paragraph-typed
    // sentence opens a new paragraph, even straight after another opener.
    expect($blocks)->toBe([
        ['type' => 'paragraph', 'content' => 'First. Second continues.'],
        ['type' => 'paragraph', 'content' => 'Third opens fresh.'],
        ['type' => 'paragraph', 'content' => 'Fourth opens another.'],
    ]);
});

test('a paragraph starts implicitly at the document start and after any non-body element', function () {
    $entity = createEntity('en');
    pdfEntitySentence($entity, 'sentence', 'Opening.', 1);
    pdfEntitySentence($entity, 'title', 'The Heading', 2);
    pdfEntitySentence($entity, 'sentence', 'After title one.', 3);
    pdfEntitySentence($entity, 'sentence', 'After title two.', 4);
    pdfEntitySentence($entity, 'subtitle', 'The Sub', 5);
    pdfEntitySentence($entity, 'sentence', 'After subtitle.', 6);
    pdfEntitySentence($entity, 'quote', 'The Quote.', 7);
    pdfEntitySentence($entity, 'sentence', 'After quote.', 8);
    pdfEntitySentence($entity, 'footnote', 'The Note.', 9);
    pdfEntitySentence($entity, 'sentence', 'After note.', 10);
    pdfEntitySentence($entity, 'caption', 'The Caption.', 11);
    pdfEntitySentence($entity, 'sentence', 'After caption.', 12);
    pdfEntitySentence($entity, 'illustration', 'The Picture.', 13);
    pdfEntitySentence($entity, 'sentence', 'After picture.', 14);
    // A paragraph-typed row somewhere: the entity is in typed mode, so the
    // implicit starts above are observable on plain sentences.
    pdfEntitySentence($entity, 'paragraph', 'Typed closer.', 15);

    $blocks = app(BookPdfService::class)->entityViewData($entity->refresh())['blocks'];

    expect($blocks)->toBe([
        ['type' => 'paragraph', 'content' => 'Opening.'],
        ['type' => 'title', 'content' => 'The Heading'],
        ['type' => 'paragraph', 'content' => 'After title one. After title two.'],
        ['type' => 'subtitle', 'content' => 'The Sub'],
        ['type' => 'paragraph', 'content' => 'After subtitle.'],
        ['type' => 'quote', 'content' => 'The Quote.'],
        ['type' => 'paragraph', 'content' => 'After quote.'],
        ['type' => 'footnote', 'content' => 'The Note.'],
        ['type' => 'paragraph', 'content' => 'After note.'],
        ['type' => 'caption', 'content' => 'The Caption.'],
        ['type' => 'paragraph', 'content' => 'After caption.'],
        ['type' => 'illustration', 'content' => 'The Picture.', 'image' => null],
        ['type' => 'paragraph', 'content' => 'After picture.'],
        ['type' => 'paragraph', 'content' => 'Typed closer.'],
    ]);
});

test('entities without paragraph-typed sentences fall back to per-sentence paragraphs', function () {
    $entity = createEntity('en');
    pdfEntitySentence($entity, 'sentence', 'One.', 1);
    pdfEntitySentence($entity, 'sentence', 'Two.', 2);
    pdfEntitySentence($entity, 'title', 'The Heading', 3);
    pdfEntitySentence($entity, 'sentence', 'Three.', 4);

    $blocks = app(BookPdfService::class)->entityViewData($entity->refresh())['blocks'];

    // Everything uploaded before the paragraph type existed: every body
    // sentence renders as its own paragraph with the new styling.
    expect($blocks)->toBe([
        ['type' => 'paragraph', 'content' => 'One.'],
        ['type' => 'paragraph', 'content' => 'Two.'],
        ['type' => 'title', 'content' => 'The Heading'],
        ['type' => 'paragraph', 'content' => 'Three.'],
    ]);
});

test('an empty body sentence neither opens nor continues a paragraph', function () {
    $entity = createEntity('en');
    pdfEntitySentence($entity, 'sentence', 'Before.', 1);
    pdfEntitySentence($entity, 'sentence', '   ', 2);
    pdfEntitySentence($entity, 'sentence', 'After.', 3);
    pdfEntitySentence($entity, 'paragraph', 'Typed closer.', 4);

    $blocks = app(BookPdfService::class)->entityViewData($entity->refresh())['blocks'];

    expect($blocks)->toBe([
        ['type' => 'paragraph', 'content' => 'Before. After.'],
        ['type' => 'paragraph', 'content' => 'Typed closer.'],
    ]);
});

// ─── book layout ─────────────────────────────────────────────────────────────

test('the book view lays out the title page and every block type in order', function () {
    $work = createWork(['title' => 'Layout Work', 'author' => 'Layout Author']);
    $entity = createEntity('en', $work, ['name' => 'Layout Entity', 'label' => 'Layout Label']);

    // Deliberately shuffled order values: document order must follow them.
    pdfEntitySentence($entity, 'sentence', 'First plain.', 2);
    pdfEntitySentence($entity, 'title', 'The Heading', 5);
    pdfEntitySentence($entity, 'subtitle', 'The Sub', 9);
    pdfEntitySentence($entity, 'quote', 'The Quote.', 12);
    pdfEntitySentence($entity, 'footnote', 'The Note.', 15);
    pdfEntitySentence($entity, 'caption', 'The Caption.', 18);

    $imagePath = 'illustrations/en/layout.png';
    Storage::disk('local')->put($imagePath, pdfTestPng());
    pdfEntitySentence($entity, 'illustration', 'The Picture.', 21, [
        'image_path' => $imagePath,
        'image_mime' => 'image/png',
        'image_width' => 3,
        'image_height' => 2,
    ]);

    $data = app(BookPdfService::class)->entityViewData($entity->refresh());
    $html = view('pdf.entity-book', $data)->render();

    // A4 portrait page + DejaVu faces.
    expect($html)->toContain('A4 portrait')
        ->and($html)->toContain("'dejavu serif'")
        ->and($html)->toContain("'dejavu sans'");

    // Title page: work title, author, entity name, label, language name,
    // then a page break.
    expect($html)->toContain('Layout Work')
        ->and($html)->toContain('Layout Author')
        ->and($html)->toContain('Layout Entity')
        ->and($html)->toContain('Layout Label')
        ->and($html)->toContain('English')
        ->and($html)->toContain('page-break-after');

    // Every block type in document order, each with its own treatment.
    $positions = [
        'plain body' => mb_strpos($html, 'First plain.'),
        'title' => mb_strpos($html, 'The Heading'),
        'subtitle' => mb_strpos($html, 'The Sub'),
        'quote' => mb_strpos($html, 'The Quote.'),
        'footnote' => mb_strpos($html, 'The Note.'),
        'caption' => mb_strpos($html, 'The Caption.'),
        'illustration img' => mb_strpos($html, '<img src='),
        'illustration caption' => mb_strpos($html, 'The Picture.'),
    ];

    expect($positions)->not->toContain(false);

    expect($positions['plain body'])->toBeLessThan($positions['title'])
        ->and($positions['title'])->toBeLessThan($positions['subtitle'])
        ->and($positions['subtitle'])->toBeLessThan($positions['quote'])
        ->and($positions['quote'])->toBeLessThan($positions['footnote'])
        ->and($positions['footnote'])->toBeLessThan($positions['caption'])
        ->and($positions['caption'])->toBeLessThan($positions['illustration img'])
        ->and($positions['illustration img'])->toBeLessThan($positions['illustration caption']);

    // Per-type markup: chapter heading, inline bold subtitle, justified
    // indented body paragraph, italic quote, smaller footnote, small
    // caption, embedded image from the private disk capped at ~70% of the
    // text width.
    expect($html)->toContain('<h2 class="sentence-title">The Heading</h2>')
        ->and($html)->toContain('<h3 class="sentence-subtitle">The Sub</h3>')
        ->and($html)->toContain('<p class="sentence-paragraph">First plain.</p>')
        ->and($html)->toContain('p.sentence-paragraph {')
        ->and($html)->toContain('text-align: justify;')
        ->and($html)->toContain('text-indent: 1em;')
        ->and($html)->toContain('<blockquote class="sentence-quote">The Quote.</blockquote>')
        ->and($html)->toContain('<p class="sentence-footnote">The Note.</p>')
        ->and($html)->toContain('<p class="sentence-caption">The Caption.</p>')
        ->and($html)->toContain('.sentence-illustration img {')
        ->and($html)->toContain('max-width: 70%;')
        ->and($html)->toContain('<img src="'.Storage::disk('local')->path($imagePath).'"');
});

test('chapter titles break the page centered while subtitles flow without a break', function () {
    $entity = createEntity('en');
    pdfEntitySentence($entity, 'title', 'The Heading', 1);
    pdfEntitySentence($entity, 'subtitle', 'The Sub', 2);
    pdfEntitySentence($entity, 'sentence', 'Body text.', 3);

    $html = view('pdf.entity-book', app(BookPdfService::class)->entityViewData($entity->refresh()))->render();

    $titleRule = pdfCssRule($html, 'h2.sentence-title');
    $subtitleRule = pdfCssRule($html, 'h3.sentence-subtitle');

    // A title opens a chapter: fresh page, centered, larger than body;
    // sans-serif comes from the shared h1/h2/h3 face rule. A subtitle is
    // an inline bold heading — no page break of its own.
    expect($titleRule)->not->toBeNull()
        ->and($titleRule)->toContain('page-break-before: always')
        ->and($titleRule)->toContain('text-align: center')
        ->and($titleRule)->toContain('font-size: 17pt')
        ->and($subtitleRule)->not->toBeNull()
        ->and($subtitleRule)->toContain('font-weight: bold')
        ->and($subtitleRule)->not->toContain('page-break-before');
});

test('a chapter title starts a fresh page without a blank page after the title page', function () {
    $entity = createEntity('en');
    pdfEntitySentence($entity, 'title', 'Chapter One', 1);
    pdfEntitySentence($entity, 'sentence', 'One short paragraph of body text.', 2);

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertOk();

    $bytes = Storage::disk('local')->get("pdfs/entities/{$entity->id}.pdf");

    // Title page + chapter page, exactly: the chapter's forced break must
    // collapse into the title page's own break-after, not add a blank page.
    expect(preg_match_all('#/Type /Page(?!s)#', $bytes))->toBe(2);
});

test('a stored cache row carrying the previous layout digest re-renders', function () {
    Carbon::setTestNow('2026-11-02 10:00:00');

    $entity = createEntity('en');
    pdfEntitySentence($entity, 'sentence', 'Plain text.', 1);

    // A row left behind by the pre-book-layout renderer: the subject's text
    // and title-page inputs are unchanged and the file is on disk, so only
    // the digest version can — and must — force the re-render.
    $stalePath = "pdfs/entities/{$entity->id}.pdf";
    Storage::disk('local')->put($stalePath, '%PDF stale');
    $stale = PdfRender::create([
        'entity_id' => $entity->id,
        'content_hash' => str_repeat('0', 64),
        'path' => $stalePath,
        'size_bytes' => strlen('%PDF stale'),
        'generated_at' => now(),
    ]);

    Carbon::setTestNow('2026-11-02 10:05:00');

    $this->actingAs(approvedUser())
        ->get("/works/{$entity->work_id}/entities/{$entity->id}/pdf")
        ->assertOk();

    $render = PdfRender::query()->sole();

    expect($render->id)->toBe($stale->id)
        ->and($render->content_hash)->toBe(app(BookPdfService::class)->entityDigest($entity->refresh()))
        ->and($render->content_hash)->not->toBe($stale->content_hash)
        ->and($render->generated_at)->toEqual(Carbon::parse('2026-11-02 10:05:00'));

    Storage::disk('local')->assertExists($render->path);
    expect(Storage::disk('local')->get($render->path))->toStartWith('%PDF')
        ->and(Storage::disk('local')->get($render->path))->not->toBe('%PDF stale');
});

test('a webp illustration degrades to its caption when gd lacks webp', function () {
    $entity = createEntity('en');

    $imagePath = 'illustrations/en/fake.webp';
    Storage::disk('local')->put($imagePath, 'not really a webp');
    pdfEntitySentence($entity, 'illustration', 'The Picture.', 1, [
        'image_path' => $imagePath,
        'image_mime' => 'image/webp',
        'image_width' => 3,
        'image_height' => 2,
    ]);

    $blocks = app(BookPdfService::class)->entityViewData($entity->refresh())['blocks'];

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['type'])->toBe('illustration')
        ->and($blocks[0]['content'])->toBe('The Picture.')
        // GD without webp support (this container) or undecodable bytes both
        // degrade: no embeddable image, caption only.
        ->and($blocks[0]['image'])->toBeNull();
});

test('a webp illustration is gd-converted to a temporary png', function () {
    if (! function_exists('imagecreatefromwebp')) {
        $this->markTestSkipped('GD was built without webp support (the dev container case).');
    }

    $entity = createEntity('en');

    $imagePath = 'illustrations/en/real.webp';
    $webp = imagecreatetruecolor(2, 1);
    imagewebp($webp, $tmp = tempnam(sys_get_temp_dir(), 'webptest').'.webp');
    imagedestroy($webp);
    Storage::disk('local')->put($imagePath, (string) file_get_contents($tmp));
    unlink($tmp);

    pdfEntitySentence($entity, 'illustration', 'The Picture.', 1, [
        'image_path' => $imagePath,
        'image_mime' => 'image/webp',
        'image_width' => 2,
        'image_height' => 1,
    ]);

    $blocks = app(BookPdfService::class)->entityViewData($entity->refresh())['blocks'];

    expect($blocks[0]['image'])->toBeString()
        ->and((string) file_get_contents((string) $blocks[0]['image']))->toStartWith("\x89PNG");
});
