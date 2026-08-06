<html class="h-full overflow-hidden">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,300..700;1,9..144,300..600&family=Figtree:wght@400;500;600;700&family=IBM+Plex+Sans:ital,wght@0,400;0,500;0,600;1,400&family=JetBrains+Mono:wght@400;500;600&family=Source+Serif+4:ital,opsz,wght@0,8..60,300..600;1,8..60,300..600&display=swap"
        rel="stylesheet">
    @viteReactRefresh
    @vite('resources/js/app.jsx')
    <x-inertia::head/>
    <link href="{{ asset('css/simulator.css') }}" rel="stylesheet" type="text/css">
    @csrf
</head>
<body class="font-sans antialiased h-full overflow-hidden dark:bg-gray-900 dark:text-gray-100">
<x-inertia::app/>
</body>
</html>
