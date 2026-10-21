<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Masaüstü' }} · SkyKite</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen">
    <header class="border-b border-ink/10 bg-paper">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-5 py-4 sm:px-8">
            <a href="{{ route('home') }}" class="flex items-center gap-3" aria-label="SkyKite ana sayfa">
                <span class="grid size-10 place-items-center rounded-2xl bg-leaf text-lime"><svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 4C12 4 6 7 6 14c0 3 2 5 5 5 7 0 9-7 9-15Z"/><path d="M4 20c2-5 6-8 11-11"/></svg></span>
                <span><span class="block font-display text-lg font-extrabold tracking-tight">SkyKite</span><span class="block text-[10px] font-bold uppercase tracking-[.2em] text-muted">Masa sipariş</span></span>
            </a>
            <nav class="flex items-center gap-3">
                @auth
                    <span class="hidden text-sm text-muted sm:block">{{ auth()->user()->name }}</span>
                    <form method="post" action="{{ route('logout') }}">@csrf<button class="rounded-xl border border-ink/10 px-4 py-2 text-sm font-semibold hover:bg-cream">Çıkış</button></form>
                @else
                    <a href="{{ route('login') }}" class="rounded-xl bg-leaf px-4 py-2.5 text-sm font-bold text-white hover:bg-ink">Giriş yap</a>
                @endauth
            </nav>
        </div>
    </header>
    <main class="mx-auto max-w-7xl px-5 py-8 sm:px-8 sm:py-12">
        @if (session('status'))<div class="mb-6 rounded-2xl border border-leaf/15 bg-lime/30 px-5 py-4 text-sm font-semibold text-leaf">{{ session('status') }}</div>@endif
        @if ($errors->any())<div role="alert" class="mb-6 rounded-2xl border border-clay/20 bg-clay/10 px-5 py-4 text-sm text-red-900"><p class="font-bold">Bilgileri kontrol edip tekrar deneyin.</p><ul class="mt-2 list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </main>
    <footer class="mx-auto max-w-7xl px-5 pb-8 text-xs text-muted sm:px-8">© {{ date('Y') }} SkyKite · Masada sipariş</footer>
</body>
</html>
