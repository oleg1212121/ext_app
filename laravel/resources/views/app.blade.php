<html class="h-full overflow-hidden">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
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
