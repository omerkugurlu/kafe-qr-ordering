<x-layouts.panel
    :title="$cafe->name"
    subtitle="Platform yönetimi · kafe detayı"
    active="platform"
>
    @php
        $roleLabels = [
            'cafe_owner' => 'Kafe sahibi',
            'cafe_manager' => 'İşletme yöneticisi',
            'waiter' => 'Garson',
            'kitchen' => 'Mutfak',
        ];
        $totalTables = $cafe->branches->sum('dining_tables_count');
        $totalOrders = $cafe->branches->sum('orders_count');
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <a href="{{ route('platform.dashboard') }}" class="btn btn-ghost btn-sm">
            <x-ui.icon name="arrow-left" class="size-4" /> Tüm kafeler
        </a>
        <form method="post" action="{{ route('platform.cafes.status', $cafe) }}">
            @csrf @method('PATCH')
            <button class="btn {{ $cafe->status === 'active' ? 'btn-secondary' : 'btn-primary' }} btn-sm">
                {{ $cafe->status === 'active' ? 'Kafeyi askıya al' : 'Kafeyi etkinleştir' }}
            </button>
        </form>
    </div>

    <section class="relative mt-4 overflow-hidden rounded-3xl bg-forest p-6 text-white shadow-soft sm:p-8">
        <div class="absolute -right-20 -top-24 size-72 rounded-full bg-amber/15 blur-3xl" aria-hidden="true"></div>
        <div class="relative flex flex-wrap items-end justify-between gap-5">
            <div class="flex min-w-0 items-center gap-4">
                @if ($cafe->logo_url)
                    <img src="{{ $cafe->logo_url }}" alt="" class="size-20 rounded-3xl border-2 border-white/20 object-cover">
                @else
                    <span class="grid size-20 shrink-0 place-items-center rounded-3xl bg-amber font-display text-3xl font-semibold text-ink">
                        {{ mb_strtoupper(mb_substr($cafe->brand_name ?: $cafe->name, 0, 1)) }}
                    </span>
                @endif
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="font-display text-3xl font-semibold tracking-tight">{{ $cafe->name }}</h1>
                        <span class="rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-[.12em] {{ $cafe->status === 'active' ? 'bg-success/20 text-[#9fe4c3]' : 'bg-terracotta/25 text-[#ffc9b8]' }}">
                            {{ $cafe->status === 'active' ? 'Aktif' : 'Askıda' }}
                        </span>
                    </div>
                    <p class="mt-1 text-sm text-white/60">/{{ $cafe->slug }} · {{ $cafe->branches->count() }} şube · {{ $customerCount }} kayıtlı müşteri</p>
                    @if ($cafe->phone)
                        <p class="mt-1 text-xs text-white/50">{{ $cafe->phone }}{{ $cafe->address ? ' · '.$cafe->address : '' }}</p>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-5 text-right sm:gap-8">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-white/45">Bugün sipariş</p>
                    <p class="mt-1 font-display text-3xl font-semibold">{{ $todayOrders }}</p>
                </div>
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-white/45">Bugün tahsilat</p>
                    <p class="mt-1 font-display text-3xl font-semibold text-amber">{{ \App\Services\Money::format($todayRevenue) }}</p>
                </div>
            </div>
        </div>
    </section>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Şube</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $cafe->branches->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">{{ $cafe->branches->where('is_active', true)->count() }} aktif</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Masa</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $totalTables }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Tüm şubelerde</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Toplam sipariş</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $totalOrders }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Tüm zamanlar</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Ekip</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $cafe->users->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Sahip dahil personel</p>
        </article>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,.8fr)] xl:items-start">
        <div class="space-y-5">
            <section class="card overflow-hidden">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4">
                    <div>
                        <h2 class="font-display text-xl font-semibold text-ink">Şubeler</h2>
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Menü, masa ve sipariş özeti</p>
                    </div>
                    <span class="badge badge-neutral">{{ $cafe->branches->count() }} şube</span>
                </header>
                <div class="divide-y divide-line">
                    @forelse ($cafe->branches as $branch)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="font-bold text-ink">{{ $branch->name }}</p>
                                    <span class="badge {{ $branch->is_active ? 'badge-ready' : 'badge-cancelled' }}">{{ $branch->is_active ? 'Aktif' : 'Kapalı' }}</span>
                                    <span class="badge badge-neutral">{{ $branch->uses_shared_menu ? 'Ortak menü' : 'Özel menü' }}</span>
                                </div>
                                <p class="mt-1 text-[11px] font-medium text-muted">
                                    {{ $branch->dining_tables_count }} masa · {{ $branch->orders_count }} sipariş · {{ $branch->table_sessions_count }} masa oturumu
                                </p>
                                @if ($branch->address)
                                    <p class="mt-0.5 truncate text-[11px] text-muted">{{ $branch->address }}</p>
                                @endif
                            </div>
                            <span class="font-mono text-[11px] font-semibold text-muted">/{{ $branch->slug }}</span>
                        </div>
                    @empty
                        <p class="px-5 py-10 text-center text-sm font-medium text-muted">Şube bulunmuyor.</p>
                    @endforelse
                </div>
            </section>

            <section class="card overflow-hidden">
                <header class="border-b border-line px-5 py-4">
                    <h2 class="font-display text-xl font-semibold text-ink">Ekip</h2>
                    <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Sahip ve personel hesapları</p>
                </header>
                <div class="divide-y divide-line">
                    @foreach ($cafe->users as $person)
                        <div class="flex items-center justify-between gap-3 px-5 py-3">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-cream font-display text-sm font-semibold text-forest">{{ mb_substr($person->name, 0, 1) }}</span>
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-ink">{{ $person->name }}</p>
                                    <p class="truncate text-[11px] font-medium text-muted">{{ $person->email }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span class="badge badge-neutral">{{ $roleLabels[$person->role] ?? $person->role }}</span>
                                <span class="badge {{ $person->is_active ? 'badge-ready' : 'badge-cancelled' }}">{{ $person->is_active ? 'Etkin' : 'Kapalı' }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="card overflow-hidden">
                <header class="border-b border-line px-5 py-4">
                    <h2 class="font-display text-xl font-semibold text-ink">Son işlemler</h2>
                    <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Denetim kaydı</p>
                </header>
                <div class="divide-y divide-line">
                    @forelse ($recentLogs as $log)
                        <div class="flex items-center justify-between gap-3 px-5 py-3">
                            <code class="truncate text-[11px] font-semibold text-ink-soft">{{ $log->action }}</code>
                            <span class="shrink-0 text-[11px] font-medium text-muted">{{ $log->created_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</span>
                        </div>
                    @empty
                        <p class="px-5 py-8 text-center text-sm font-medium text-muted">Kayıt yok.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <aside class="space-y-5">
            <section class="card p-5">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-xl font-semibold text-ink">Kafe ayarları</h2>
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Durum ve operasyon</p>
                    </div>
                    <span class="grid size-10 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="settings" class="size-5" /></span>
                </div>

                <form method="post" action="{{ route('platform.cafes.update', $cafe) }}" class="mt-4 space-y-3">
                    @csrf @method('PATCH')
                    <label class="block">
                        <span class="label">Kafe adı</span>
                        <input name="name" value="{{ $cafe->name }}" required maxlength="120" class="input">
                    </label>
                    <label class="block">
                        <span class="label">Sayfa kısa adı</span>
                        <input name="slug" value="{{ $cafe->slug }}" required maxlength="120" pattern="[A-Za-z0-9_-]+" class="input font-mono">
                    </label>
                    <label class="block">
                        <span class="label">Marka adı</span>
                        <input name="brand_name" value="{{ $cafe->brand_name }}" maxlength="120" class="input">
                    </label>
                    <label class="block">
                        <span class="label">Durum</span>
                        <select name="status" required class="input">
                            <option value="active" @selected($cafe->status === 'active')>Aktif</option>
                            <option value="suspended" @selected($cafe->status === 'suspended')>Askıda</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="label">Müşteri kayıt politikası</span>
                        <select name="registration_policy" required class="input">
                            <option value="guest" @selected($cafe->registration_policy === 'guest')>Hesapsız sipariş</option>
                            <option value="optional" @selected($cafe->registration_policy === 'optional')>Hesap isteğe bağlı</option>
                            <option value="required" @selected($cafe->registration_policy === 'required')>Hesap zorunlu</option>
                        </select>
                    </label>
                    <input type="hidden" name="loyalty_enabled" value="0">
                    <label class="flex min-h-10 items-center gap-2 text-xs font-semibold text-ink">
                        <input type="checkbox" name="loyalty_enabled" value="1" @checked($cafe->loyalty_enabled) class="size-4 accent-forest"> Sadakat programı açık
                    </label>
                    <label class="block">
                        <span class="label">Her 100 ₺ için puan</span>
                        <input type="number" name="points_per_100" value="{{ $cafe->points_per_100 }}" min="0" max="10000" required class="input">
                    </label>
                    <button class="btn btn-primary w-full">Kafe ayarlarını kaydet</button>
                </form>
            </section>

            @if ($owner)
                <section class="card p-5">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <h2 class="font-display text-xl font-semibold text-ink">Sahip girişi</h2>
                            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">{{ $owner->email }}</p>
                        </div>
                        <span class="grid size-10 place-items-center rounded-full bg-amber-soft text-[#8a5a14]"><x-ui.icon name="lock" class="size-5" /></span>
                    </div>

                    <form method="post" action="{{ route('platform.cafes.owner.update', $cafe) }}" class="mt-4 space-y-3">
                        @csrf @method('PATCH')
                        <label class="block">
                            <span class="label">Ad soyad</span>
                            <input name="owner_name" value="{{ $owner->name }}" required maxlength="120" autocomplete="name" class="input">
                        </label>
                        <label class="block">
                            <span class="label">Giriş e-postası</span>
                            <input type="email" name="owner_email" value="{{ $owner->email }}" required autocomplete="email" class="input">
                        </label>
                        <input type="hidden" name="owner_is_active" value="0">
                        <label class="flex min-h-10 items-center gap-2 text-xs font-semibold text-ink">
                            <input type="checkbox" name="owner_is_active" value="1" @checked($owner->is_active) class="size-4 accent-forest"> Sahip hesabı aktif
                        </label>
                        <div class="border-t border-line pt-3">
                            <p class="text-[10px] font-bold uppercase tracking-[.16em] text-terracotta">Şifre sıfırla</p>
                        </div>
                        <label class="block">
                            <span class="label">Yeni şifre</span>
                            <input type="password" name="owner_password" minlength="12" autocomplete="new-password" class="input" placeholder="Değişmeyecekse boş bırakın">
                        </label>
                        <label class="block">
                            <span class="label">Yeni şifre (tekrar)</span>
                            <input type="password" name="owner_password_confirmation" minlength="12" autocomplete="new-password" class="input">
                        </label>
                        <button class="btn btn-primary w-full">Sahip hesabını güncelle</button>
                        <p class="text-[11px] leading-4 text-muted">Şifre sıfırlanınca sahibin açık oturumları kapatılır. Denetim kaydında şifrenin kendisi tutulmaz.</p>
                    </form>
                </section>
            @endif
        </aside>
    </div>
</x-layouts.panel>
