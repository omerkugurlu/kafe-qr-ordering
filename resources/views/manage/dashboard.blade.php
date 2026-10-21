<x-layouts.panel
    :title="'Merhaba, ' . auth()->user()->name"
    :subtitle="$cafe->name . ' · işletme özeti'"
    active="dashboard"
    :branch="$branch"
>
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('manage.dashboard') }}" @class(['chip', 'chip-active' => $branch === null])>
                <x-ui.icon name="grid" class="size-3.5" /> Tüm şubeler
            </a>
            @foreach ($branches as $branchOption)
                <a
                    href="{{ route('manage.dashboard', ['sube' => $branchOption->id]) }}"
                    @class(['chip', 'chip-active' => $branch?->id === $branchOption->id])
                >
                    <x-ui.icon name="pin" class="size-3.5" /> {{ $branchOption->name }}
                </a>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('manage.orders.index', $branch !== null ? ['sube' => $branch->id] : []) }}" class="btn btn-secondary btn-sm">
                <x-ui.icon name="receipt" class="size-4" /> Canlı siparişler
            </a>
            <a href="{{ route('manage.branches.show', $branch ?? $branches->first()) }}" class="btn btn-primary btn-sm">
                <x-ui.icon name="utensils" class="size-4" /> Menüyü düzenle
            </a>
        </div>
    </div>

    @if ($branches->isEmpty())
        <div class="card p-8 text-center">
            <h2 class="font-display text-2xl font-semibold text-ink">Henüz şube yok</h2>
            <p class="mt-2 text-sm text-muted">Menü ve masaları yönetmek için ilk şubenizi oluşturun.</p>
            <a href="{{ route('manage.settings.index') }}" class="btn btn-primary mt-5">Şube oluştur</a>
        </div>
    @else
        <div class="grid gap-4 xl:grid-cols-[minmax(0,1.45fr)_minmax(0,1fr)]">
            <section class="relative overflow-hidden rounded-3xl bg-forest p-6 text-white shadow-soft sm:p-8">
                <div class="absolute -right-16 -top-20 size-64 rounded-full bg-amber/15 blur-2xl" aria-hidden="true"></div>
                <div class="relative">
                    <div class="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[.2em] text-white/60">
                        <x-ui.icon name="banknote" class="size-4 text-amber" />
                        Bugünkü tahsilat
                    </div>
                    <p class="mt-3 font-display text-5xl font-semibold tracking-tight">{{ \App\Services\Money::format($todayRevenue) }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-3 text-sm">
                        @if ($revenueDelta === null)
                            <span class="text-white/60">Dün tahsilat yok</span>
                        @else
                            <span @class([
                                'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold',
                                'bg-success/20 text-[#9fe4c3]' => $revenueDelta >= 0,
                                'bg-terracotta/25 text-[#ffc9b8]' => $revenueDelta < 0,
                            ])>
                                <x-ui.icon :name="$revenueDelta >= 0 ? 'chart' : 'chart'" class="size-3.5" />
                                {{ $revenueDelta >= 0 ? '+' : '' }}{{ $revenueDelta }}%
                            </span>
                            <span class="text-white/50">düne göre</span>
                        @endif
                    </div>

                    <div class="mt-7 grid grid-cols-3 gap-3 border-t border-white/10 pt-5">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.16em] text-white/45">Sipariş</p>
                            <p class="mt-1 font-display text-2xl font-semibold">{{ $ordersTodayCount }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.16em] text-white/45">Ortalama sepet</p>
                            <p class="mt-1 font-display text-2xl font-semibold">{{ \App\Services\Money::format($averageBasket) }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.16em] text-white/45">Açık masa</p>
                            <p class="mt-1 font-display text-2xl font-semibold">{{ $openSessions }}</p>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card p-5">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-xl font-semibold text-ink">Son 7 gün</h2>
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Tahsilat eğilimi</p>
                    </div>
                    <span class="badge badge-neutral">{{ $dailySeries->sum('orders') }} sipariş</span>
                </div>

                <div class="mt-6 flex h-44 items-end gap-2">
                    @foreach ($dailySeries as $day)
                        <div class="group flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-2">
                            <div class="relative flex h-full w-full flex-1 items-end">
                                <div
                                    @class([
                                        'w-full rounded-t-xl transition group-hover:opacity-90',
                                        'bg-forest' => $day['is_today'],
                                        'bg-sage-deep/70' => ! $day['is_today'],
                                    ])
                                    style="height: {{ max(4, (int) round(($day['cents'] / $maxDaily) * 100)) }}%"
                                    title="{{ $day['short'] }} · {{ \App\Services\Money::format($day['cents']) }}"
                                ></div>
                            </div>
                            <span @class([
                                'text-[10px] font-bold uppercase',
                                'text-forest' => $day['is_today'],
                                'text-muted' => ! $day['is_today'],
                            ])>{{ $day['label'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex items-center justify-between rounded-2xl bg-cream px-4 py-3">
                    <span class="text-xs font-semibold text-muted">7 günlük toplam</span>
                    <strong class="font-display text-lg text-ink">{{ \App\Services\Money::format($dailySeries->sum('cents')) }}</strong>
                </div>
            </section>
        </div>

        <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <article class="kpi">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Bekleyen iş</p>
                    <span class="grid size-9 place-items-center rounded-full bg-amber-soft text-[#8a5a14]"><x-ui.icon name="bell" class="size-4" /></span>
                </div>
                <p class="mt-3 font-display text-3xl font-semibold text-ink">{{ $pendingOrders }}</p>
                <p class="mt-1 text-xs font-medium text-muted">Hazırlanan veya servis bekleyen</p>
            </article>
            <article class="kpi">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Aktif personel</p>
                    <span class="grid size-9 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="users" class="size-4" /></span>
                </div>
                <p class="mt-3 font-display text-3xl font-semibold text-ink">{{ $staffOnDuty }}</p>
                <p class="mt-1 text-xs font-medium text-muted">Garson + mutfak</p>
            </article>
            <article class="kpi">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Satıştaki ürün</p>
                    <span class="grid size-9 place-items-center rounded-full bg-terracotta-soft text-[#8f3d22]"><x-ui.icon name="utensils" class="size-4" /></span>
                </div>
                <p class="mt-3 font-display text-3xl font-semibold text-ink">{{ $productsCount }}</p>
                <p class="mt-1 text-xs font-medium text-muted">{{ $branches->count() }} şubede toplam</p>
            </article>
            <a href="{{ route('staff.kitchen', $branch ?? $branches->first()) }}" class="kpi group transition hover:-translate-y-0.5 hover:border-forest/30">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Mutfak ekranı</p>
                    <span class="grid size-9 place-items-center rounded-full bg-forest text-amber"><x-ui.icon name="chef" class="size-4" /></span>
                </div>
                <p class="mt-3 font-display text-2xl font-semibold text-ink">Ekranı aç</p>
                <p class="mt-1 flex items-center gap-1 text-xs font-semibold text-muted group-hover:text-forest">
                    Sipariş akışını mutfakta izleyin
                    <x-ui.icon name="arrow-right" class="size-3.5" />
                </p>
            </a>
        </div>

        <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,.9fr)]">
            <section class="card p-5">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-xl font-semibold text-ink">Son siparişler</h2>
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Canlı akış</p>
                    </div>
                    <a href="{{ route('manage.orders.index') }}" class="text-xs font-bold text-forest hover:text-ink">Tümü</a>
                </div>

                <div class="mt-4 divide-y divide-line">
                    @forelse ($recentOrders as $order)
                        <a href="{{ route('staff.sessions.show', $order->table_session_id) }}" class="flex items-center gap-3 py-3 transition hover:opacity-80">
                            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-cream text-forest">
                                <x-ui.icon name="table" class="size-4" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-bold text-ink">{{ $order->tableSession?->diningTable?->name ?? 'Masa' }}</p>
                                <p class="text-[11px] font-medium text-muted">#{{ $order->order_number }} · {{ $order->created_at->timezone('Europe/Istanbul')->format('H:i') }} · {{ $order->items->sum('quantity') }} ürün</p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-ink">{{ \App\Services\Money::formatDecimal($order->total) }}</p>
                                <x-ui.status-badge :status="$order->status" class="mt-1" />
                            </div>
                        </a>
                    @empty
                        <p class="py-10 text-center text-sm font-medium text-muted">Henüz sipariş yok.</p>
                    @endforelse
                </div>
            </section>

            <section class="card p-5">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-xl font-semibold text-ink">En çok satanlar</h2>
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Son 30 gün</p>
                    </div>
                    <a href="{{ route('manage.reports.index') }}" class="text-xs font-bold text-forest hover:text-ink">Rapor</a>
                </div>

                <div class="mt-4 space-y-3">
                    @forelse ($topProducts as $index => $product)
                        <div class="flex items-center gap-3">
                            <span class="grid size-7 shrink-0 place-items-center rounded-full {{ $index === 0 ? 'bg-amber text-ink' : 'bg-cream text-muted' }} text-[11px] font-bold">{{ $index + 1 }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-bold text-ink">{{ $product['name'] }}</p>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-cream">
                                    <div class="h-full rounded-full bg-forest" style="width: {{ max(6, (int) round(($product['quantity'] / max(1, $topProducts->max('quantity'))) * 100)) }}%"></div>
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <p class="text-xs font-bold text-ink">{{ $product['quantity'] }} adet</p>
                                <p class="text-[11px] font-medium text-muted">{{ \App\Services\Money::format($product['revenue_cents']) }}</p>
                            </div>
                        </div>
                    @empty
                        <p class="py-10 text-center text-sm font-medium text-muted">Satış verisi oluşmadı.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                ['route' => route('manage.branches.show', $branch ?? $branches->first()), 'icon' => 'utensils', 'title' => 'Menü yönetimi', 'text' => 'Kategori, ürün, görsel ve seçenekler'],
                ['route' => route('manage.tables.index', $branch ?? $branches->first()), 'icon' => 'qr', 'title' => 'Masa & QR', 'text' => 'QR üret, yazdır, yenile'],
                ['route' => route('manage.coupons.index'), 'icon' => 'ticket', 'title' => 'Kuponlar', 'text' => 'Kampanya kodu ve indirimler'],
                ['route' => route('manage.staff.index'), 'icon' => 'users', 'title' => 'Ekip', 'text' => 'Garson ve mutfak hesapları'],
            ] as $action)
                <a href="{{ $action['route'] }}" class="card-flat flex items-center gap-3 p-4 transition hover:-translate-y-0.5 hover:border-forest/25">
                    <span class="grid size-10 shrink-0 place-items-center rounded-full bg-sage text-forest"><x-ui.icon :name="$action['icon']" class="size-5" /></span>
                    <span class="min-w-0">
                        <span class="block text-sm font-bold text-ink">{{ $action['title'] }}</span>
                        <span class="block truncate text-[11px] font-medium text-muted">{{ $action['text'] }}</span>
                    </span>
                </a>
            @endforeach
        </div>
    @endif
</x-layouts.panel>
