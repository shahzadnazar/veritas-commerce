<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Only when SSR is off. With SSR on, @inertiaHead renders the page's
         own title, and a second one here would be the one a crawler reads. --}}
    @unless (config('inertia.ssr.enabled'))
        <title inertia>{{ config('veritas.identity.display_name') }}</title>
    @endunless

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=archivo:400,600,800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/storefront/main.tsx'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
