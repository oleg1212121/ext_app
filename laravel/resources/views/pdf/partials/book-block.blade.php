{{-- One book block's type→markup cascade, shared by the entity and
    alignment books. Titles are deliberately not handled here: the two
    books' chapter machinery diverges (entity: page-break chapter pages;
    alignment: the table closes and reopens around a shared heading). --}}
@if ($block['type'] === 'subtitle')
    <h3 class="sentence-subtitle">{{ $block['content'] }}</h3>
@elseif ($block['type'] === 'quote')
    <blockquote class="sentence-quote">{{ $block['content'] }}</blockquote>
@elseif ($block['type'] === 'footnote')
    <p class="sentence-footnote">{{ $block['content'] }}</p>
@elseif ($block['type'] === 'caption')
    <p class="sentence-caption">{{ $block['content'] }}</p>
@elseif ($block['type'] === 'illustration')
    {{-- An illustration's plain content is its optional caption; when the
        image cannot be embedded (e.g. WebP without GD support) the caption
        stands alone. --}}
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
