@extends('components.layouts.crossword')

@section('content')
    <div>
        
        <div x-data="main" class="container">
            
            <div class="left">
                <template x-if="crossword">
                    <div>
                        <template x-for="row in crossword.newGrid">
                            <div class="row">
                                <template x-for="cell in row" :key="cell.y+cell.x">
                                    <div>
                                        <template x-if="cell.type === 1">
                                            @include('components.empty_cell')
                                            
                                        </template>
                                        <template x-if="cell.type === 2">
                                            @include('components.arrow_horizontal_cell')
                                            
                                        </template>
                                        <template x-if="cell.type === 3">
                                            @include('components.arrow_vertical_cell')
                                            
                                        </template>
                                        <template x-if="cell.type === 4">
                                            @include('components.symbol_cell')
                                            
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            <div class="right">
                <ul class='definitions' >
                    <template x-for="definition in definitions">  
                        <li x-text="definition"></li>
                    </template>
                </ul>
            </div>
        </div>
    </div>
@endsection
@push('styles')
    <link href="{{ asset('css/crossword.css') }}" rel="stylesheet" type="text/css">
@endpush

@push('scripts')
    <script src="{{ asset('js/crossword.js') }}" ></script>
@endpush
