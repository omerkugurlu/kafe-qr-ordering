@use('App\Models\User')
@use('Illuminate\Support\Facades\Route')
@use('Illuminate\Support\Str')

@props(['title', 'subtitle' => null, 'active' => null, 'branch' => null])

@php
    $user = auth()->user();
    $role = $user?->role;
    $isPlatform = $role === User::ROLE_PLATFORM_ADMIN;
    $cafe = $isPlatform ? null : $user?->cafe;
    $branches = $cafe !== null
        ? $cafe->branches()->orderBy('name')->get()
        : collect();
    $activeBranch = $branch ?? $branches->first();

    $roleLabel = match ($role) {
        User::ROLE_PLATFORM_ADMIN => 'Süper admin',
        User::ROLE_CAFE_OWNER => 'Kafe sahibi',
        User::ROLE_CAFE_MANAGER => 'İşletme yöneticisi',
        User::ROLE_WAITER => 'Garson',
        User::ROLE_KITCHEN => 'Mutfak',
        default => 'Kullanıcı',
    };

    $initials = Str::of($user?->name ?? 'SK')
        ->explode(' ')
        ->filter()
        ->map(fn (string $part): string => mb_substr($part, 0, 1))
        ->take(2)
        ->implode('');

    $groups = [];

    if ($isPlatform) {
        $groups['Platform'] = [
            ['label' => 'Kafeler', 'route' => 'platform.dashboard', 'icon' => 'grid', 'key' => 'platform'],
        ];
    } else {
        $operations = [];

        if ($role === User::ROLE_WAITER || $role === User::ROLE_KITCHEN) {
            $operations[] = ['label' => 'Sipariş panosu', 'route' => 'staff.orders', 'icon' => 'receipt', 'key' => 'board'];
            $operations[] = ['label' => 'Mutfak ekranı', 'route' => 'staff.kitchen', 'icon' => 'chef', 'key' => 'kitchen'];
        } else {
            $operations[] = ['label' => 'Genel bakış', 'route' => 'manage.dashboard', 'icon' => 'grid', 'key' => 'dashboard'];
            $operations[] = ['label' => 'Canlı siparişler', 'route' => 'manage.orders.index', 'icon' => 'receipt', 'key' => 'orders'];
            $operations[] = ['label' => 'Mutfak ekranı', 'route' => 'staff.kitchen', 'icon' => 'chef', 'key' => 'kitchen'];

            if ($activeBranch !== null) {
                $operations[] = ['label' => 'Menü yönetimi', 'route' => 'manage.branches.show', 'params' => [$activeBranch], 'icon' => 'utensils', 'key' => 'menu'];
                $operations[] = ['label' => 'Masalar & QR', 'route' => 'manage.tables.index', 'params' => [$activeBranch], 'icon' => 'table', 'key' => 'tables'];
            }
        }

        $management = [];

        if ($role !== User::ROLE_WAITER && $role !== User::ROLE_KITCHEN) {
            $management[] = ['label' => 'Kuponlar', 'route' => 'manage.coupons.index', 'icon' => 'ticket', 'key' => 'coupons'];
            $management[] = ['label' => 'Duyurular', 'route' => 'manage.announcements.index', 'icon' => 'bell', 'key' => 'announcements'];
            $management[] = ['label' => 'Raporlar', 'route' => 'manage.reports.index', 'icon' => 'chart', 'key' => 'reports'];
            $management[] = ['label' => 'Personel', 'route' => 'manage.staff.index', 'icon' => 'users', 'key' => 'staff'];
            $management[] = ['label' => 'Sadakat', 'route' => 'manage.loyalty.index', 'icon' => 'star', 'key' => 'loyalty'];
            $management[] = ['label' => 'Ayarlar', 'route' => 'manage.settings.index', 'icon' => 'settings', 'key' => 'settings'];
        }

        $groups['Operasyon'] = $operations;

        if ($management !== []) {
            $groups['Yönetim'] = $management;
        }
    }

    foreach ($groups as $groupLabel => $items) {
        $groups[$groupLabel] = array_values(array_filter($items, fn (array $item): bool => Route::has($item['route'])));
    }

    $groups = array_filter($groups);
@endphp

<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#173f34">
    <title>{{ $title }} · SkyKite</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%23173f34'/%3E%3Cpath d='M16 5 8 11l8 16 8-16-8-6Z' fill='none' stroke='%23e9a94d' stroke-width='1.7' stroke-linejoin='round'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-dvh bg-cream text-ink">
    <div data-panel-backdrop class="fixed inset-0 z-40 hidden bg-ink/45 backdrop-blur-[2px] lg:hidden"></div>

    <aside data-panel-sidebar class="fixed inset-y-0 left-0 z-50 flex w-[min(19rem,86vw)] -translate-x-full flex-col border-r border-line bg-paper transition-transform duration-300 ease-out lg:translate-x-0" aria-label="Ana menü">
        <div class="flex items-center justify-between px-5 pt-5">
            <x-ui.logo :href="$isPlatform ? route('platform.dashboard') : route('manage.dashboard')" />
            <button type="button" class="btn-icon lg:hidden" data-panel-close aria-label="Menüyü kapat">
                <x-ui.icon name="x" class="size-5" />
            </button>
        </div>

        @if ($cafe !== null)
            <div class="mx-4 mt-5 rounded-2xl border border-line bg-cream/70 px-4 py-3">
                <p class="truncate text-sm font-bold text-ink">{{ $cafe->brand_name ?: $cafe->name }}</p>
                <p class="mt-0.5 truncate text-[11px] font-semibold uppercase tracking-[.14em] text-muted">{{ $activeBranch?->name ?? 'Şube seçilmedi' }}</p>
            </div>
        @endif

        <nav class="mt-4 flex-1 overflow-y-auto px-3 pb-4">
            @foreach ($groups as $groupLabel => $items)
                <p class="mb-1 mt-4 px-3.5 text-[10px] font-bold uppercase tracking-[.22em] text-muted/80">{{ $groupLabel }}</p>
                <div class="space-y-1">
                    @foreach ($items as $item)
                        @php
                            $isActive = $active !== null
                                ? $active === $item['key']
                                : request()->routeIs($item['route']);
                        @endphp
                        <a href="{{ route($item['route'], $item['params'] ?? []) }}" @class(['nav-link', 'nav-link-active' => $isActive])>
                            <x-ui.icon :name="$item['icon']" class="size-5 shrink-0" />
                            <span class="truncate">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endforeach

            @if ($branches->count() > 1)
                <p class="mb-1 mt-6 px-3.5 text-[10px] font-bold uppercase tracking-[.22em] text-muted/80">Şubeler</p>
                <div class="space-y-1">
                    @foreach ($branches as $branchOption)
                        <a
                            href="{{ route('manage.branches.show', $branchOption) }}"
                            @class(['nav-link', 'nav-link-active' => $activeBranch?->id === $branchOption->id])
                        >
                            <span class="grid size-5 shrink-0 place-items-center rounded-full {{ $branchOption->is_active ? 'bg-success-soft text-success' : 'bg-danger-soft text-danger' }}">
                                <x-ui.icon name="pin" class="size-3" />
                            </span>
                            <span class="truncate">{{ $branchOption->name }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </nav>

        <div class="border-t border-line p-4">
            <div class="flex items-center gap-3 rounded-2xl bg-cream/70 p-3">
                <span class="grid size-10 shrink-0 place-items-center rounded-full bg-forest text-sm font-bold uppercase text-amber">{{ $initials }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-bold text-ink">{{ $user?->name }}</p>
                    <p class="truncate text-[11px] font-semibold text-muted">{{ $roleLabel }}</p>
                </div>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="grid size-9 place-items-center rounded-full text-muted transition hover:bg-ink/5 hover:text-danger" title="Çıkış yap" aria-label="Çıkış yap">
                        <x-ui.icon name="logout" class="size-4" />
                    </button>
                </form>
            </div>
        </div>
    </aside>

    <div class="lg:pl-[19rem]">
        <header class="sticky top-0 z-30 border-b border-line/80 bg-cream/85 backdrop-blur-xl">
            <div class="flex items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
                <button type="button" class="btn-icon shrink-0 lg:hidden" data-panel-toggle aria-label="Menüyü aç">
                    <x-ui.icon name="panel" class="size-5" />
                </button>
                <div class="min-w-0 flex-1">
                    <h1 class="truncate font-display text-xl font-semibold tracking-tight text-ink sm:text-2xl">{{ $title }}</h1>
                    @if ($subtitle)
                        <p class="mt-0.5 truncate text-xs font-medium text-muted">{{ $subtitle }}</p>
                    @endif
                </div>
                <div class="hidden items-center gap-2 rounded-full border border-line bg-paper px-3 py-1.5 text-[11px] font-bold text-forest sm:flex">
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60"></span>
                        <span class="relative inline-flex size-2 rounded-full bg-success"></span>
                    </span>
                    Canlı
                </div>
                <span class="grid size-10 shrink-0 place-items-center rounded-full border border-line bg-paper text-xs font-bold uppercase text-forest">{{ $initials }}</span>
            </div>
        </header>

        <main class="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            <x-ui.flash class="mb-6" />
            {{ $slot }}
        </main>
    </div>

    <div id="toast-root" class="pointer-events-none fixed inset-x-4 bottom-4 z-[90] flex flex-col items-center gap-2 sm:inset-x-auto sm:right-6 sm:bottom-6 sm:items-end" aria-live="polite" aria-atomic="true"></div>
    <div id="flash-data" hidden data-status="{{ session('status') }}" data-error="{{ $errors->first() }}"></div>
</body>
</html>
