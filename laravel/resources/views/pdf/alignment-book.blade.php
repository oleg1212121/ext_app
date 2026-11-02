<!DOCTYPE html>
<html lang="{{ $left['language']?->code ?? 'en' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $left['entity']?->name }} — {{ $right['entity']?->name }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 2.2cm 1.6cm 2.4cm;
        }

        body {
            font-family: 'dejavu serif';
            font-size: 10pt;
            line-height: 1.5;
            color: #1a1a1a;
        }

        h1, h2, h3 {
            font-family: 'dejavu sans';
        }

        /* Title page: work title, author, then the pair — the original
           side first (it is the left column), marked as such. The language
           names live here and nowhere else: the spread itself carries no
           repeating header row. */
        .title-page {
            page-break-after: always;
            text-align: center;
            padding-top: 5cm;
        }

        .title-page .work-title {
            font-size: 22pt;
            font-weight: bold;
            margin: 0;
        }

        .title-page .author {
            font-size: 13pt;
            margin-top: 0.6cm;
        }

        .title-page .pair {
            margin-top: 3.5cm;
        }

        .title-page .entity-name {
            font-size: 15pt;
            margin-top: 0.5cm;
        }

        .title-page .language-name {
            font-size: 10pt;
            margin-top: 0.2cm;
            color: #555555;
        }

        .title-page .between {
            font-family: 'dejavu sans';
            font-size: 12pt;
            margin-top: 0.5cm;
            color: #555555;
        }

        /* The two-column spread: borderless fixed 50/50 cells. */
        table.alignment {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.alignment td {
            width: 50%;
            vertical-align: top;
            padding: 0 8pt;
            text-align: left;
        }

        /* Best-effort: keep one alignment row on one page. */
        tr.row {
            page-break-inside: avoid;
        }

        /* A title row opens a chapter page shared by both languages: both
           sides' titles stack into one centered heading, the table closes
           around it (dompdf cannot page-break inside table rows) and the
           columns resume paired after it. */
        h2.chapter-heading {
            font-size: 17pt;
            font-weight: bold;
            text-align: center;
            page-break-before: always;
            margin: 0 0 20pt;
        }

        /* The first content sits right after the title page's own break; a
           chapter opening the book must not force another, blank page. */
        h2.chapter-heading.chapter-open {
            page-break-before: auto;
        }

        h2.chapter-heading .chapter-line {
            display: block;
        }

        /* Sentence types, in document order. */
        h3.sentence-subtitle {
            font-size: 11.5pt;
            font-weight: bold;
            margin: 10pt 0 5pt;
        }

        p.sentence-paragraph {
            text-align: justify;
            text-indent: 1em;
            margin: 0 0 6pt;
        }

        blockquote.sentence-quote {
            font-style: italic;
            margin: 6pt 0 6pt 18pt;
        }

        p.sentence-footnote {
            font-size: 8pt;
            color: #333333;
            margin: 0 0 4pt;
        }

        p.sentence-caption {
            font-size: 8.5pt;
            font-style: italic;
            color: #333333;
            margin: 2pt 0 6pt;
            text-align: center;
        }

        .sentence-illustration {
            margin: 10pt 0;
            text-align: center;
        }

        .sentence-illustration img {
            max-width: 70%;
        }
    </style>
</head>
<body>
    <div class="title-page">
        <h1 class="work-title">{{ $work?->title }}</h1>
        @if (($work?->author ?? '') !== '')
            <div class="author">{{ $work->author }}</div>
        @endif
        <div class="pair">
            <div class="entity-name">{{ $left['entity']?->name }}</div>
            <div class="language-name">
                {{ $left['language']?->name }}@if ($left['is_original']) (original)@endif
            </div>
            <div class="between">⇄</div>
            <div class="entity-name">{{ $right['entity']?->name }}</div>
            <div class="language-name">{{ $right['language']?->name }}</div>
        </div>
    </div>

    @php
        // Chapter bookkeeping: title-bearing rows stack both sides' titles
        // into one centered heading, consecutive title rows merging into it.
        // The heading flushes right before the next content renders (or at
        // the document end), with the table closed around it.
        $pendingTitles = [];
        $pageStarted = false;
    @endphp

    <table class="alignment">
        <tbody>
            @foreach ($rows as $row)
                @if ($row['chapter'])
                    @php
                        foreach (['left', 'right'] as $side) {
                            foreach ($row[$side] as $block) {
                                if ($block['type'] === 'title' && trim((string) $block['content']) !== '') {
                                    $pendingTitles[] = $block['content'];
                                }
                            }
                        }
                    @endphp
                @endif

                {{-- A pure title row renders no cells: it only feeds the
                     pending heading. --}}
                @php($rendersCells = array_filter(
                    array_merge($row['left'], $row['right']),
                    fn ($block) => $block['type'] !== 'title',
                ) !== [])

                @if ($rendersCells && $pendingTitles !== [])
                    </tbody>
                </table>
                    <h2 class="chapter-heading{{ $pageStarted ? '' : ' chapter-open' }}">
                        @foreach ($pendingTitles as $title)
                            <span class="chapter-line">{{ $title }}</span>
                        @endforeach
                    </h2>
                    @php($pendingTitles = [])
                    <table class="alignment">
                        <tbody>
                @endif

                @if ($rendersCells)
                    <tr class="row">
                        @foreach (['left', 'right'] as $side)
                            <td>
                                @foreach ($row[$side] as $block)
                                    @continue($block['type'] === 'title')
                                    @if ($block['type'] === 'subtitle')
                                        <h3 class="sentence-subtitle">{{ $block['content'] }}</h3>
                                    @elseif ($block['type'] === 'quote')
                                        <blockquote class="sentence-quote">{{ $block['content'] }}</blockquote>
                                    @elseif ($block['type'] === 'footnote')
                                        <p class="sentence-footnote">{{ $block['content'] }}</p>
                                    @elseif ($block['type'] === 'caption')
                                        <p class="sentence-caption">{{ $block['content'] }}</p>
                                    @elseif ($block['type'] === 'illustration')
                                        {{-- An illustration's plain content is
                                            its optional caption; when the image
                                            cannot be embedded (e.g. WebP without
                                            GD support) the caption stands alone. --}}
                                        <div class="sentence-illustration">
                                            @if ($block['image'] !== null)
                                                <img src="{{ $block['image'] }}" alt="{{ $block['content'] }}">
                                            @endif
                                            @if (trim((string) $block['content']) !== '')
                                                <p class="sentence-caption">{{ $block['content'] }}</p>
                                            @endif
                                        </div>
                                    @else
                                        <p class="sentence-paragraph">{{ $block['content'] }}</p>
                                    @endif
                                @endforeach
                            </td>
                        @endforeach
                    </tr>
                    @php($pageStarted = true)
                @endif
            @endforeach
        </tbody>
    </table>

    @if ($pendingTitles !== [])
        {{-- The document ends on a chapter heading: the table above is
             already closed; nothing follows to reopen for. --}}
        <h2 class="chapter-heading{{ $pageStarted ? '' : ' chapter-open' }}">
            @foreach ($pendingTitles as $title)
                <span class="chapter-line">{{ $title }}</span>
            @endforeach
        </h2>
    @endif
</body>
</html>
