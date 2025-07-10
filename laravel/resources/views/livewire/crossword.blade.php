<div>
    <div id="body">
        @foreach ($grid as $row)
            <div class="row">
                @foreach ($row as $cell)
                    @if ($cell == '*')
                        <div class="cell black">
                            {{-- <span></span> --}}
                        </div>
                    @elseif($cell == '.')
                        <div class="cell black">
                            {{-- <span>{{$cell}}</span> --}}
                        </div>
                    @else
                        <div class="cell symbol">
                            <input type="text" value="{{ $cell }}" maxlength="1">
                            {{-- <span>{{ $cell }}</span> --}}
                        </div>
                    @endif
                @endforeach
            </div>
        @endforeach
        @foreach ($words as $word)
            <div>{{ $word[0] ? 'horizontal' : 'vertical' }} {{ $word[3] }}</div>
        @endforeach
    </div>
</div>

@push('styles')
    <link href="{{ asset('css/crossword.css') }}" rel="stylesheet" type="text/css">
@endpush

@push('scripts')
    <script src="{{ asset('js/crossword.js') }}" defer></script>
@endpush
