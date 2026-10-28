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

        h1, h2, h3, .language-label {
            font-family: 'dejavu sans';
        }

        /* Title page: work title, author, then the pair — the original
           side first (it is the left column), marked as such. */
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

        /* The two-column spread: borderless fixed 50/50 cells, and a
           thead the renderer repeats at the top of every page. */
        table.alignment {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        table.alignment th,
        table.alignment td {
            width: 50%;
            vertical-align: top;
            padding: 0 8pt;
            text-align: left;
        }

        table.alignment thead {
            display: table-header-group;
        }

        th.language-label {
            font-size: 10pt;
            font-weight: normal;
            color: #555555;
            padding: 0 8pt 8pt;
        }

        /* Best-effort: keep one alignment row on one page. */
        tr.row {
            page-break-inside: avoid;
        }

        /* Sentence types, in document order. */
        h2.sentence-title {
            font-size: 13pt;
            font-weight: bold;
            margin: 12pt 0 6pt;
        }

        h3.sentence-subtitle {
            font-size: 11.5pt;
            font-weight: bold;
            margin: 10pt 0 5pt;
        }

        p.sentence-body {
            text-align: justify;
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
            margin: 8pt 0;
            text-align: center;
        }

        .sentence-illustration img {
            max-width: 100%;
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

    <table class="alignment">
        <thead>
            <tr>
                <th class="language-label">{{ $left['language']?->name }}</th>
                <th class="language-label">{{ $right['language']?->name }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="row">
                    @foreach (['left', 'right'] as $side)
                        <td>
                            @foreach ($row[$side] as $sentence)
                                @if ($sentence['type'] === 'title')
                                    <h2 class="sentence-title">{{ $sentence['content'] }}</h2>
                                @elseif ($sentence['type'] === 'subtitle')
                                    <h3 class="sentence-subtitle">{{ $sentence['content'] }}</h3>
                                @elseif ($sentence['type'] === 'quote')
                                    <blockquote class="sentence-quote">{{ $sentence['content'] }}</blockquote>
                                @elseif ($sentence['type'] === 'footnote')
                                    <p class="sentence-footnote">{{ $sentence['content'] }}</p>
                                @elseif ($sentence['type'] === 'caption')
                                    <p class="sentence-caption">{{ $sentence['content'] }}</p>
                                @elseif ($sentence['type'] === 'illustration')
                                    {{-- An illustration's plain content is its
                                        optional caption; when the image cannot
                                        be embedded (e.g. WebP without GD
                                        support) the caption stands alone. --}}
                                    <div class="sentence-illustration">
                                        @if ($sentence['image'] !== null)
                                            <img src="{{ $sentence['image'] }}" alt="{{ $sentence['content'] }}">
                                        @endif
                                        @if (trim((string) $sentence['content']) !== '')
                                            <p class="sentence-caption">{{ $sentence['content'] }}</p>
                                        @endif
                                    </div>
                                @else
                                    <p class="sentence-body">{{ $sentence['content'] }}</p>
                                @endif
                            @endforeach
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
