@props(['title' => 'Menü', 'cafe' => null])

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#173f34">
    <title>{{ $title }} · {{ $cafe?->name ?? 'SkyKite' }}</title>
    <meta name="description" content="{{ $cafe?->brand_name ?? $cafe?->name ?? 'SkyKite' }} — masanızdan QR ile sipariş verin.">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%23173f34'/%3E%3Cpath d='M16 5 8 11l8 16 8-16-8-6Z' fill='none' stroke='%23e9a94d' stroke-width='1.7' stroke-linejoin='round'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-cream text-ink antialiased">
    {{ $slot }}

    <div id="toast-root" class="pointer-events-none fixed inset-x-4 bottom-24 z-[90] flex flex-col items-center gap-2 sm:inset-x-auto sm:right-6 sm:bottom-28 sm:items-end" aria-live="polite" aria-atomic="true"></div>
    <div id="flash-data" hidden data-status="{{ session('status') }}" data-error="{{ $errors->first() }}"></div>
</body>
</html>
