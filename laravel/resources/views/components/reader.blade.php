@extends('components.layouts.crossword')

@section('content')
    <div>

        <x-navigation></x-navigation>

        <main>
            <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                @foreach($rows as $key => [$en, $ru])
                <div class="row @if($loop->index % 10 == 0) border @endif">
                    <input type="checkbox" class="checker hidden" id="check{{$key}}" />
                    <label for="check{{$key}}">
                    <p class="text">{{$en}}</p>
                    </label>
                    <p class="translation">{{$ru}}</p>
                    
                </div>
                @endforeach
            </div>
        
        </main>

    </div>
@endsection


{{-- @push('scripts')

@endpush --}}




@push('styles')
<style>
    
    .border {
        border: 2px solid green;
    }
    .hidden {
        display: none;
    }
    .text:hover {
        cursor: pointer;
    }
    .text {
        font-size: 30px;
    }
    .translation {
        opacity: 0;
        color: green;
        font-size: 25px;
        user-select: none;
        pointer-events: none;
    }



    .row:has(.checker:checked) > .translation {
        opacity: 100%;
        pointer-events: initial;
        user-select: initial;
    }

</style>

@endpush