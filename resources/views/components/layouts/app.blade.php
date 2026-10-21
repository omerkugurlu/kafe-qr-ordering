<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#173f34">
    <title>{{ $title ?? 'Masa Sipariş' }} · SkyKite</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-screen flex-col bg-cream text-ink">
    <header class="border-b border-line/80 bg-cream/85 backdrop-blur">
        <div class="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <x-ui.logo />

            <nav class="flex items-center gap-2 sm:gap-3">
                @auth
                    <span class="hidden text-sm font-medium text-muted sm:block">{{ auth()->user()->name }}</span>
                    @if (auth()->user()->role !== \App\Models\User::ROLE_CUSTOMER)
                        <a href="{{ auth()->user()->isPlatformAdmin() ? route('platform.dashboard') : (in_array(auth()->user()->role, [\App\Models\User::ROLE_WAITER, \App\Models\User::ROLE_KITCHEN], true) ? route('staff.orders') : route('manage.dashboard')) }}" class="btn btn-secondary btn-sm">
                            Panele git
                        </a>
                    @endif
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="btn btn-ghost btn-sm">Çıkış</button>
                    </form>
                @else
                    @unless (request()->routeIs('login'))
                        <a href="{{ route('login') }}" class="btn btn-primary btn-sm">Giriş yap</a>
                    @endunless
                @endauth
            </nav>
        </div>
    </header>

    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8 sm:px-6 sm:py-12">
        <x-ui.flash class="mb-6" />
        {{ $slot }}
    </main>

    <footer class="border-t border-line/70">
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-2 px-4 py-6 text-xs font-medium text-muted sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <p>© {{ date('Y') }} SkyKite · Masada sipariş</p>
            <p>Menüden masaya, siparişten ödemeye.</p>
        </div>
    </footer>
</body>
</html>
