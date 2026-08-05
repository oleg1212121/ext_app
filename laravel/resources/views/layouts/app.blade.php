<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)]">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..700;1,9..144,300..600&family=Figtree:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])

    @stack('styles')
    @stack('scripts')
</head>
<body class="font-sans antialiased h-full text-[var(--color-ink)] dark:text-[var(--color-vellum-night)]">

@include('layouts.navigation')
<div class="min-h-screen bg-[var(--color-vellum)] dark:bg-[var(--color-ink-night)]">
    @isset($header)
        <header class="border-b border-[var(--color-hairline)] dark:border-[var(--color-hairline-night)] bg-[var(--color-vellum-deep)] dark:bg-[var(--color-ink-night)]">
            <div class="px-4 sm:px-6 lg:px-10 py-4">
                {{ $header }}
            </div>
        </header>
    @endisset

    <main>
        {{ $slot }}
    </main>
</div>
</body>
</html>