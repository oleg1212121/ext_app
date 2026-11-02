<!DOCTYPE html>
<html lang="{{ $language->code }}">
<head>
    <meta charset="utf-8">
    <title>{{ $entity->name }}</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 2.2cm 2cm 2.4cm;
        }

        body {
            font-family: 'dejavu serif';
            font-size: 11pt;
            line-height: 1.55;
            color: #1a1a1a;
        }

        h1, h2, h3 {
            font-family: 'dejavu sans';
        }

        /* Title page: work title, author, then the entity itself. */
        .title-page {
            page-break-after: always;
            text-align: center;
            padding-top: 6cm;
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

        .title-page .entity-name {
            font-size: 17pt;
            margin-top: 4cm;
        }

        .title-page .entity-label {
            font-size: 12pt;
            font-style: italic;
            margin-top: 0.2cm;
        }

        .title-page .language-name {
            font-size: 10pt;
            margin-top: 1cm;
            color: #555555;
        }

        /* Book blocks, in document order. A title opens a chapter page:
           fresh page, centered, larger. A subtitle is an inline bold
           heading — it flows without a break of its own. */
        h2.sentence-title {
            font-size: 17pt;
            font-weight: bold;
            text-align: center;
            page-break-before: always;
            margin: 0 0 20pt;
        }

        /* The first block sits right after the title page's own break; a
           chapter opening the book must not force another, blank page. */
        h2.sentence-title.chapter-open {
            page-break-before: auto;
        }

        h3.sentence-subtitle {
            font-size: 12.5pt;
            font-weight: bold;
            margin: 12pt 0 6pt;
        }

        p.sentence-paragraph {
            text-align: justify;
            text-indent: 1em;
            margin: 0 0 6pt;
        }

        blockquote.sentence-quote {
            font-style: italic;
            margin: 7pt 0 7pt 26pt;
        }

        p.sentence-footnote {
            font-size: 8.5pt;
            color: #333333;
            margin: 0 0 5pt;
        }

        p.sentence-caption {
            font-size: 9pt;
            font-style: italic;
            color: #333333;
            margin: 2pt 0 8pt;
            text-align: center;
        }

        .sentence-illustration {
            margin: 12pt 0;
            text-align: center;
        }

        .sentence-illustration img {
            max-width: 70%;
        }
    </style>
</head>
<body>
    <div class="title-page">
        <h1 class="work-title">{{ $work->title }}</h1>
        @if (($work->author ?? '') !== '')
            <div class="author">{{ $work->author }}</div>
        @endif
        <div class="entity-name">{{ $entity->name }}</div>
        @if (($entity->label ?? '') !== '')
            <div class="entity-label">{{ $entity->label }}</div>
        @endif
        <div class="language-name">{{ $language->name }}</div>
    </div>

    @foreach ($blocks as $block)
        @if ($block['type'] === 'title')
            <h2 class="sentence-title{{ $loop->first ? ' chapter-open' : '' }}">{{ $block['content'] }}</h2>
        @elseif ($block['type'] === 'subtitle')
            <h3 class="sentence-subtitle">{{ $block['content'] }}</h3>
        @elseif ($block['type'] === 'quote')
            <blockquote class="sentence-quote">{{ $block['content'] }}</blockquote>
        @elseif ($block['type'] === 'footnote')
            <p class="sentence-footnote">{{ $block['content'] }}</p>
        @elseif ($block['type'] === 'caption')
            <p class="sentence-caption">{{ $block['content'] }}</p>
        @elseif ($block['type'] === 'illustration')
            {{-- An illustration's plain content is its optional caption;
                when the image cannot be embedded (e.g. WebP without GD
                support) the caption stands alone. --}}
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
</body>
</html>
