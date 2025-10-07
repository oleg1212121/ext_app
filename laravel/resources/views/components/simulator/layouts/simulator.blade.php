<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" x-data="main" :style="`font-size: ${fontSize}px`">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title id="title">Simulator</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    {{-- @livewireStyles --}}
    <!-- Styles / Scripts -->
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite([
            'resources/css/app.css',
            'resources/js/app.js',
            // 'resources/css/crossword.css',
        ])
    @else
        <script src="{{ asset('js/alpine.js') }}"></script>
    @endif
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link href="{{ asset('css/simulator.css') }}" rel="stylesheet" type="text/css">

    <script src="{{ asset('js/simulator.js') }}"></script>
    @stack('styles')
    {{-- <script src="/livewire/livewire.js"></script> --}}
    {{-- <script src="{{ asset('js/simulator.js') }}"></script> --}}
</head>
<body class="h-full w-full overflow-hidden">

    {{ $slot }}

</body>

</html>
