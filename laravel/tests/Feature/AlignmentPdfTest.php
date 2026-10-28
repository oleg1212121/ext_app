<?php

use App\Classes\Pdf\BookPdfService;
use App\Models\Entity;
use App\Models\EntityMatch;
use App\Models\EntitySentence;
use App\Models\MeaningMatch;
use App\Models\PdfRender;
use App\Models\SentenceMeaningMatch;
use App\Models\SentenceType;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Database\Seeders\SentenceTypeSeeder;
use Illuminate\Support\Facades\Storage;

if (! function_exists('alignmentPdfPng')) {
    /**
     * A genuine PNG built chunk by chunk (CRCs correct by construction), so
     * dompdf's image sniffing accepts it.
     */
    function alignmentPdfPng(int $width = 3, int $height = 2): string
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

if (! function_exists('alignmentPdfSentence')) {
    function alignmentPdfSentence(Entity $entity, string $typeName, string $content, int $order, array $attributes = []): EntitySentence
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

if (! function_exists('alignmentPdfRow')) {
    /**
     * One meaning match on the pair, junctioning the given sentences per
     * side (side a / side b as given — the fixture controls the sides).
     *
     * @param  list<EntitySentence>  $aSentences
     * @param  list<EntitySentence>  $bSentences
     */
    function alignmentPdfRow(EntityMatch $match, int $order, array $aSentences, array $bSentences): MeaningMatch
    {
        $meaningMatch = MeaningMatch::query()->create([
            'entity_match_id' => $match->id,
            'order' => $order,
            'similarity' => 0.95,
            'alignment_chunk' => 0,
        ]);

        foreach ($aSentences as $sentence) {
            SentenceMeaningMatch::query()->create([
                'entity_sentence_id' => $sentence->id,
                'meaning_match_id' => $meaningMatch->id,
                'side' => 'a',
            ]);
        }

        foreach ($bSentences as $sentence) {
            SentenceMeaningMatch::query()->create([
                'entity_sentence_id' => $sentence->id,
                'meaning_match_id' => $meaningMatch->id,
                'side' => 'b',
            ]);
        }

        return $meaningMatch;
    }
}

if (! function_exists('alignmentPdfPair')) {
    /**
     * A completed en/ru pair on one work whose original language is en.
     * The en entity is created first, so it is the canonical a-side.
     *
     * @return array{work: Work, en: Entity, ru: Entity, match: EntityMatch}
     */
    function alignmentPdfPair(array $workAttributes = []): array
    {
        $work = createWork(['title' => 'Pair Work', 'author' => 'Pair Author', ...$workAttributes]);
        $en = createEntity('en', $work, ['name' => 'Alpha']);
        $ru = createEntity('ru', $work, ['name' => 'Beta']);
        $match = createEntityMatch($en, $ru, ['status' => 'completed']);

        return ['work' => $work, 'en' => $en, 'ru' => $ru, 'match' => $match];
    }
}

if (! function_exists('alignmentPdfPopulated')) {
    /**
     * The pair fixture with one aligned row: "Alpha text." / "Текст беты.".
     *
     * @return array{work: Work, en: Entity, ru: Entity, match: EntityMatch, enSentence: EntitySentence, ruSentence: EntitySentence}
     */
    function alignmentPdfPopulated(array $workAttributes = []): array
    {
        $pair = alignmentPdfPair($workAttributes);
        $enSentence = alignmentPdfSentence($pair['en'], 'sentence', 'Alpha text.', 1);
        $ruSentence = alignmentPdfSentence($pair['ru'], 'sentence', 'Текст беты.', 1);
        alignmentPdfRow($pair['match'], 0, [$enSentence], [$ruSentence]);

        return [...$pair, 'enSentence' => $enSentence, 'ruSentence' => $ruSentence];
    }
}

beforeEach(function () {
    createLanguages(['en', 'ru']);
    app(SentenceTypeSeeder::class)->run();
    Storage::fake('local');
});

afterEach(function () {
    Carbon::setTestNow();
});

// ─── guards ──────────────────────────────────────────────────────────────────

test('guests are redirected from the alignment pdf download', function () {
    $pair = alignmentPdfPair();

    $this->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertRedirect(route('login'));
});

test('unapproved users are redirected from the alignment pdf download', function () {
    $pair = alignmentPdfPair();
    $user = User::factory()->create(['is_approved' => false]);

    $this->actingAs($user)
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertRedirect('/pending-approval');
});

test('the alignment pdf route 404s when the URL names another work', function () {
    $pair = alignmentPdfPair();
    $otherWork = createWork(['title' => 'Other Work']);

    $this->actingAs(approvedUser())
        ->get("/works/{$otherWork->id}/alignments/{$pair['match']->id}/pdf")
        ->assertNotFound();

    expect(PdfRender::query()->count())->toBe(0);
});

test('the alignment pdf route 403s until both sides are readable', function () {
    $pair = alignmentPdfPair();
    // Side a is unrestricted; the restricted b side alone forbids the match.
    $pair['ru']->update(['is_restricted' => true]);
    $user = approvedUser();

    $this->actingAs($user)
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertForbidden();

    expect(PdfRender::query()->count())->toBe(0);

    $pair['ru']->grantedUsers()->attach($user->id);

    $this->actingAs($user)
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();
});

test('the alignment pdf 404s unless the match is completed', function () {
    foreach (['pending', 'stale', 'aligning', 'failed'] as $status) {
        $pair = alignmentPdfPair();
        $pair['match']->update(['status' => $status]);

        $this->actingAs(approvedUser())
            ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
            ->assertNotFound();
    }

    expect(PdfRender::query()->count())->toBe(0);
});

test('the alignment detail page carries the status that gates the pdf button', function () {
    $pair = alignmentPdfPair();

    // The button is a client-side conditional on match.status (no SSR in
    // tests, so the prop is the gate the JSX reads); the endpoint's own
    // completed gate is the 404 test above.
    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Library/ShowAlignment')
            ->where('match.status', 'completed'));

    $pair['match']->update(['status' => 'pending']);

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('match.status', 'pending'));
});

// ─── download shape ──────────────────────────────────────────────────────────

test('the alignment pdf download streams an attachment named after the work and pair', function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    $pair = alignmentPdfPopulated();

    $response = $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf");

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'private');

    $expectedName = 'Pair Work — Alpha ⇄ Beta.pdf';
    $disposition = $response->headers->get('Content-Disposition');

    expect($disposition)->toStartWith('attachment')
        ->and($disposition)->toContain("filename*=utf-8''".rawurlencode($expectedName))
        ->and($response->streamedContent())->toStartWith('%PDF');

    $render = PdfRender::query()->sole();

    expect($render->entity_id)->toBeNull()
        ->and($render->entity_match_id)->toBe($pair['match']->id)
        ->and($render->content_hash)->toHaveLength(64)
        ->and($render->path)->toBe("pdfs/matches/{$pair['match']->id}.pdf")
        ->and($render->size_bytes)->toBeGreaterThan(0)
        ->and($render->generated_at)->not->toBeNull();

    Storage::disk('local')->assertExists($render->path);
    expect(Storage::disk('local')->get($render->path))->toStartWith('%PDF');
});

test('the rendered bilingual pdf embeds cyrillic text with the dejavu fonts', function () {
    $pair = alignmentPdfPopulated();

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $bytes = Storage::disk('local')->get("pdfs/matches/{$pair['match']->id}.pdf");

    // The body renders in DejaVu Serif (bundled Cyrillic coverage); a
    // Helvetica fallback would mean the DejaVu resolution failed.
    expect($bytes)->toStartWith('%PDF')
        ->and($bytes)->toContain('DejaVuSerif')
        ->and($bytes)->not->toContain('Helvetica');
});

// ─── layout ──────────────────────────────────────────────────────────────────

test('the bilingual book view lays out the title page and the repeating two-column table', function () {
    $pair = alignmentPdfPopulated();

    $data = app(BookPdfService::class)->alignmentViewData($pair['match']);
    $html = view('pdf.alignment-book', $data)->render();

    // A4 portrait page + DejaVu faces.
    expect($html)->toContain('A4 portrait')
        ->and($html)->toContain("'dejavu serif'")
        ->and($html)->toContain("'dejavu sans'");

    // Title page: work title, author, both entity names with their language
    // names, the original side marked, then a page break.
    expect($html)->toContain('Pair Work')
        ->and($html)->toContain('Pair Author')
        ->and($html)->toContain('Alpha')
        ->and($html)->toContain('Beta')
        ->and($html)->toContain('English')
        ->and($html)->toContain('Russian')
        ->and($html)->toContain('(original)')
        ->and($html)->toContain('page-break-after');

    // The two-column table: fixed 50/50 cells, the language labels in a
    // thead that repeats on every page, rows kept whole when possible.
    expect($html)->toContain('<table class="alignment">')
        ->and($html)->toContain('table-layout: fixed')
        ->and($html)->toContain('display: table-header-group')
        ->and($html)->toContain('page-break-inside: avoid')
        ->and($html)->toContain('<th class="language-label">English</th>')
        ->and($html)->toContain('<th class="language-label">Russian</th>');

    // Both cells carry their side's sentences.
    expect($html)->toContain('Alpha text.')
        ->and($html)->toContain('Текст беты.');
});

test('the original side sits in the left column', function () {
    // The en entity created first is the canonical a-side AND the work's
    // original: left = en.
    $pair = alignmentPdfPair();
    $data = app(BookPdfService::class)->alignmentViewData($pair['match']);

    expect($data['left']['entity']->id)->toBe($pair['en']->id)
        ->and($data['left']['is_original'])->toBeTrue()
        ->and($data['right']['entity']->id)->toBe($pair['ru']->id)
        ->and($data['right']['is_original'])->toBeFalse();

    // The same work with en created last: en is the canonical b-side but
    // still the original — it must sit on the left regardless.
    $work = createWork(['title' => 'Flipped Work']);
    $ru = createEntity('ru', $work, ['name' => 'Rus First']);
    $en = createEntity('en', $work, ['name' => 'Eng Second']);
    $match = createEntityMatch($ru, $en, ['status' => 'completed']);

    $data = app(BookPdfService::class)->alignmentViewData($match);
    $html = view('pdf.alignment-book', $data)->render();

    expect($data['left']['entity']->id)->toBe($en->id)
        ->and($data['left']['is_original'])->toBeTrue()
        ->and($data['right']['entity']->id)->toBe($ru->id)
        // Title page order and header order both follow the columns.
        ->and(mb_strpos($html, 'Eng Second'))->toBeLessThan(mb_strpos($html, 'Rus First'))
        ->and(mb_strpos($html, '<th class="language-label">English</th>'))
        ->toBeLessThan(mb_strpos($html, '<th class="language-label">Russian</th>'));
});

test('without a determinable original side the left column falls back to side a', function () {
    // The work's original is a third language: neither side is the
    // original, so the left column falls back to canonical side a (en).
    $de = createLanguages(['en', 'ru', 'de'])['de'];
    $work = createWork(['title' => 'Pair Work', 'author' => 'Pair Author', 'original_language_id' => $de->id]);
    $en = createEntity('en', $work, ['name' => 'Alpha']);
    $ru = createEntity('ru', $work, ['name' => 'Beta']);
    $match = createEntityMatch($en, $ru, ['status' => 'completed']);

    $data = app(BookPdfService::class)->alignmentViewData($match);
    $html = view('pdf.alignment-book', $data)->render();

    expect($data['left']['entity']->id)->toBe($en->id)
        ->and($data['left']['is_original'])->toBeFalse()
        ->and($data['right']['entity']->id)->toBe($ru->id)
        ->and($html)->not->toContain('(original)');
});

test('rows follow the meaning-match order and each side stacks its sentences in document order', function () {
    $pair = alignmentPdfPair();

    $enOne = alignmentPdfSentence($pair['en'], 'sentence', 'Alpha first.', 1);
    $enTwo = alignmentPdfSentence($pair['en'], 'sentence', 'Alpha second.', 2);
    $ruOne = alignmentPdfSentence($pair['ru'], 'sentence', 'Бета первая.', 1);
    $ruTwo = alignmentPdfSentence($pair['ru'], 'sentence', 'Бета вторая.', 2);

    // Rows created out of order; row 0's junctions created in reverse
    // document order.
    alignmentPdfRow($pair['match'], 10, [], [$ruTwo]);
    alignmentPdfRow($pair['match'], 5, [$enTwo, $enOne], [$ruOne]);

    $rows = app(BookPdfService::class)->alignmentViewData($pair['match'])['rows'];

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['order'])->toBe(5)
        ->and($rows[0]['left'][0]['content'])->toBe('Alpha first.')
        ->and($rows[0]['left'][1]['content'])->toBe('Alpha second.')
        ->and($rows[0]['right'][0]['content'])->toBe('Бета первая.')
        // Single-sided row: the other cell is empty.
        ->and($rows[1]['order'])->toBe(10)
        ->and($rows[1]['left'])->toBe([])
        ->and($rows[1]['right'][0]['content'])->toBe('Бета вторая.');
});

test('a single-sided row renders an empty opposite cell', function () {
    $pair = alignmentPdfPair();
    $ruSentence = alignmentPdfSentence($pair['ru'], 'sentence', 'Только бета.', 1);
    alignmentPdfRow($pair['match'], 0, [], [$ruSentence]);

    $html = view('pdf.alignment-book', app(BookPdfService::class)->alignmentViewData($pair['match']))->render();

    expect($html)->toContain('Только бета.')
        ->and($html)->toMatch('/<td>\s*<\/td>/');
});

test('empty-content non-image junctions contribute nothing to a cell', function () {
    $pair = alignmentPdfPair();
    $real = alignmentPdfSentence($pair['en'], 'sentence', 'Alpha real.', 1);
    $empty = alignmentPdfSentence($pair['en'], 'sentence', '', 2);
    alignmentPdfRow($pair['match'], 0, [$real, $empty], []);

    $rows = app(BookPdfService::class)->alignmentViewData($pair['match'])['rows'];

    expect($rows[0]['left'])->toHaveCount(1)
        ->and($rows[0]['left'][0]['content'])->toBe('Alpha real.');
});

test('illustrations embed inside their own side\'s cell', function () {
    $pair = alignmentPdfPair();
    $enSentence = alignmentPdfSentence($pair['en'], 'sentence', 'Alpha text.', 1);
    $imagePath = 'illustrations/ru/alignment.png';
    Storage::disk('local')->put($imagePath, alignmentPdfPng());
    $ruPicture = alignmentPdfSentence($pair['ru'], 'illustration', 'Картина.', 1, [
        'image_path' => $imagePath,
        'image_mime' => 'image/png',
        'image_width' => 3,
        'image_height' => 2,
    ]);
    alignmentPdfRow($pair['match'], 0, [$enSentence], [$ruPicture]);

    $data = app(BookPdfService::class)->alignmentViewData($pair['match']);

    expect($data['rows'][0]['right'][0]['image'])->toBe(Storage::disk('local')->path($imagePath))
        ->and($data['rows'][0]['left'][0]['image'])->toBeNull()
        ->and($data['rows'][0]['right'][0]['content'])->toBe('Картина.');

    $html = view('pdf.alignment-book', $data)->render();

    expect($html)->toContain('<img src="'.Storage::disk('local')->path($imagePath).'"');
});

// ─── cache ───────────────────────────────────────────────────────────────────

test('a second request is served from storage without re-rendering', function () {
    // Freeze before creating anything: sentences_updated_at must live on
    // the frozen clock for the staleness comparisons below to be exact.
    Carbon::setTestNow('2026-10-28 10:00:00');
    $pair = alignmentPdfPopulated();

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $first = PdfRender::query()->sole();

    expect($first->generated_at)->toEqual(Carbon::parse('2026-10-28 10:00:00'));

    Carbon::setTestNow('2026-10-28 10:05:00');

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $second = PdfRender::query()->sole();

    expect($second->id)->toBe($first->id)
        ->and($second->generated_at)->toEqual($first->generated_at)
        ->and($second->content_hash)->toBe($first->content_hash);
});

test('an edit on either side changes the digest and re-renders the row in place', function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    $pair = alignmentPdfPopulated();

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $first = PdfRender::query()->sole();

    Carbon::setTestNow('2026-10-28 10:05:00');

    // The b-side edit must re-render too: both sides' text hashes are inputs.
    $pair['ruSentence']->update(['content' => 'Текст беты изменён.']);

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $second = PdfRender::query()->sole();

    expect($second->id)->toBe($first->id)
        ->and($second->content_hash)->not->toBe($first->content_hash)
        ->and($second->generated_at)->toEqual(Carbon::parse('2026-10-28 10:05:00'));

    Carbon::setTestNow('2026-10-28 10:10:00');

    // ...and so must the a-side.
    $pair['enSentence']->update(['content' => 'Alpha text edited.']);

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $third = PdfRender::query()->sole();

    expect($third->id)->toBe($first->id)
        ->and($third->content_hash)->not->toBe($second->content_hash)
        ->and($third->generated_at)->toEqual(Carbon::parse('2026-10-28 10:10:00'));
});

test('the pdf re-renders when the pairing changes', function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    $pair = alignmentPdfPopulated();
    $row = MeaningMatch::query()->where('entity_match_id', $pair['match']->id)->sole();

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $first = PdfRender::query()->sole();

    // Unlink: detach the b sentence from its row.
    Carbon::setTestNow('2026-10-28 10:05:00');
    SentenceMeaningMatch::query()
        ->where('meaning_match_id', $row->id)
        ->where('side', 'b')
        ->delete();

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $second = PdfRender::query()->sole();

    expect($second->id)->toBe($first->id)
        ->and($second->content_hash)->not->toBe($first->content_hash)
        ->and($second->generated_at)->toEqual(Carbon::parse('2026-10-28 10:05:00'));

    // Delete the whole row (its junctions cascade with it).
    Carbon::setTestNow('2026-10-28 10:10:00');
    $row->delete();

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $third = PdfRender::query()->sole();

    expect($third->id)->toBe($first->id)
        ->and($third->content_hash)->not->toBe($second->content_hash)
        ->and($third->generated_at)->toEqual(Carbon::parse('2026-10-28 10:10:00'));
});

test('updated_at churn alone does not re-render', function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    $pair = alignmentPdfPopulated();

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $first = PdfRender::query()->sole();

    Carbon::setTestNow('2026-10-28 10:05:00');

    // Approve/reject-style churn: entity_similarity and entity_matches.
    // updated_at move; nothing the book renders does.
    $pair['match']->update(['entity_similarity' => 0.4321]);

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $second = PdfRender::query()->sole();

    expect($second->id)->toBe($first->id)
        ->and($second->content_hash)->toBe($first->content_hash)
        ->and($second->generated_at)->toEqual($first->generated_at);
});

test('a deleted cache file forces a re-render on the next request', function () {
    Carbon::setTestNow('2026-10-28 10:00:00');
    $pair = alignmentPdfPopulated();

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $first = PdfRender::query()->sole();
    Storage::disk('local')->delete($first->path);

    Carbon::setTestNow('2026-10-28 10:05:00');

    $this->actingAs(approvedUser())
        ->get("/works/{$pair['work']->id}/alignments/{$pair['match']->id}/pdf")
        ->assertOk();

    $second = PdfRender::query()->sole();

    expect($second->id)->toBe($first->id)
        ->and($second->generated_at)->toEqual(Carbon::parse('2026-10-28 10:05:00'));

    Storage::disk('local')->assertExists($second->path);
});

// ─── digest ──────────────────────────────────────────────────────────────────

test('the match digest tracks both sides, the title-page inputs, and the pairing', function () {
    // Sentence-set staleness compares truncated (second-precision)
    // timestamps, so every mutation that must read as stale happens on the
    // frozen clock, a minute apart.
    Carbon::setTestNow('2026-10-28 10:00:00');
    $pair = alignmentPdfPopulated();
    $row = MeaningMatch::query()->where('entity_match_id', $pair['match']->id)->sole();

    $service = app(BookPdfService::class);
    $match = $pair['match'];

    $digest = $service->matchDigest($match);

    // Deterministic for unchanged inputs.
    expect($service->matchDigest($match))->toBe($digest);

    // Sentence edits flow through the refreshed text hashes — either side.
    Carbon::setTestNow('2026-10-28 10:01:00');
    $pair['enSentence']->update(['content' => 'Alpha text edited.']);
    $afterAEdit = $service->matchDigest($match->refresh());

    Carbon::setTestNow('2026-10-28 10:02:00');
    $pair['ruSentence']->update(['content' => 'Текст беты изменён.']);
    $afterBEdit = $service->matchDigest($match->refresh());

    // Pairing moves: a junction relink, a row move, a row delete.
    Carbon::setTestNow('2026-10-28 10:03:00');
    $enExtra = alignmentPdfSentence($pair['en'], 'sentence', 'Alpha extra.', 2);
    SentenceMeaningMatch::query()->create([
        'entity_sentence_id' => $enExtra->id,
        'meaning_match_id' => $row->id,
        'side' => 'a',
    ]);
    $afterRelink = $service->matchDigest($match->refresh());

    Carbon::setTestNow('2026-10-28 10:04:00');
    $row->update(['order' => 7]);
    $afterMove = $service->matchDigest($match->refresh());

    Carbon::setTestNow('2026-10-28 10:05:00');
    $row->delete();
    $afterDelete = $service->matchDigest($match->refresh());

    // Title-page inputs move it too: both entity names/labels, work title.
    Carbon::setTestNow('2026-10-28 10:06:00');
    $pair['ru']->refresh()->update(['name' => 'Beta Renamed']);
    $afterBName = $service->matchDigest($match->refresh());

    Carbon::setTestNow('2026-10-28 10:07:00');
    $pair['en']->refresh()->update(['label' => 'Vol. I']);
    $afterALabel = $service->matchDigest($match->refresh());

    Carbon::setTestNow('2026-10-28 10:08:00');
    Work::query()->whereKey($pair['work']->id)->update(['title' => 'Other Title']);
    $afterTitle = $service->matchDigest($match->refresh());

    expect($afterAEdit)->not->toBe($digest)
        ->and($afterBEdit)->not->toBe($afterAEdit)
        ->and($afterRelink)->not->toBe($afterBEdit)
        ->and($afterMove)->not->toBe($afterRelink)
        ->and($afterDelete)->not->toBe($afterMove)
        ->and($afterBName)->not->toBe($afterDelete)
        ->and($afterALabel)->not->toBe($afterBName)
        ->and($afterTitle)->not->toBe($afterALabel);

    // entity_matches.updated_at churn (approve/reject, similarity) is
    // deliberately not an input.
    Carbon::setTestNow('2026-10-28 10:09:00');
    $match->refresh()->update(['entity_similarity' => 0.4321]);
    expect($service->matchDigest($match))->toBe($afterTitle);
});
