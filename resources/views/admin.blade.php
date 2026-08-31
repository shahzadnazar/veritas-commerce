<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Behind auth: never indexed, never crawled, so no SSR either. --}}
    <meta name="robots" content="noindex, nofollow">

    <title inertia>{{ config('veritas.identity.display_name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=archivo:400,600,800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/admin/main.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
