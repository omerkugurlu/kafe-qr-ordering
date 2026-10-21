<x-layouts.panel
    title="Kafeler"
    subtitle="Platform yönetimi · işletmeler ve erişimler"
    active="platform"
>
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Kafe toplamı', 'value' => $totalCafes, 'icon' => 'coffee', 'tone' => 'sage'],
            ['label' => 'Aktif kafe', 'value' => $activeCafes, 'icon' => 'check-circle', 'tone' => 'amber'],
            ['label' => 'Aktif masa', 'value' => $activeTables, 'icon' => 'table', 'tone' => 'sage'],
            ['label' => 'Aktif müşteri', 'value' => $activeCustomers, 'icon' => 'users', 'tone' => 'cream'],
        ] as $stat)
            <article class="kpi">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">{{ $stat['label'] }}</p>
                    <span class="grid size-9 place-items-center rounded-full {{ $stat['tone'] === 'amber' ? 'bg-amber-soft text-[#8a5a14]' : ($stat['tone'] === 'cream' ? 'bg-cream text-forest' : 'bg-sage text-forest') }}">
                        <x-ui.icon :name="$stat['icon']" class="size-4" />
                    </span>
                </div>
                <p class="mt-3 font-display text-4xl font-semibold text-ink">{{ number_format($stat['value'], 0, ',', '.') }}</p>
                <p class="mt-1 text-xs font-medium text-muted">Platform genelinde</p>
            </article>
        @endforeach
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,.65fr)] xl:items-start">
        <section class="card overflow-hidden">
            <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4">
                <div>
                    <h2 class="font-display text-xl font-semibold text-ink">Kayıtlı işletmeler</h2>
                    <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">{{ $cafes->total() }} kafe</p>
                </div>
                <span class="badge badge-ready"><span class="size-1.5 rounded-full bg-success"></span> Platform aktif</span>
            </header>

            <div class="divide-y divide-line">
                @forelse ($cafes as $cafe)
                    @php($owner = $cafe->users->firstWhere('role', \App\Models\User::ROLE_CAFE_OWNER))
                    <article class="p-5 transition hover:bg-cream/30">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-3">
                                @if ($cafe->logo_url)
                                    <img src="{{ $cafe->logo_url }}" alt="" class="size-12 shrink-0 rounded-2xl border border-line object-cover">
                                @else
                                    <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-forest font-display text-xl font-semibold text-amber">
                                        {{ mb_strtoupper(mb_substr($cafe->brand_name ?: $cafe->name, 0, 1)) }}
                                    </span>
                                @endif
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="truncate font-display text-lg font-semibold text-ink">{{ $cafe->name }}</h3>
                                        <span class="badge {{ $cafe->status === 'active' ? 'badge-ready' : 'badge-cancelled' }}">
                                            {{ $cafe->status === 'active' ? 'Aktif' : 'Askıda' }}
                                        </span>
                                    </div>
                                    <p class="mt-0.5 truncate text-xs font-medium text-muted">/{{ $cafe->slug }} · {{ $cafe->branches_count }} şube · {{ $cafe->users_count }} kullanıcı</p>
                                    @if ($owner)
                                        <p class="mt-1 truncate text-[11px] font-medium text-muted">Sahip: {{ $owner->name }} · {{ $owner->email }}</p>
                                    @endif
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('platform.cafes.show', $cafe) }}" class="btn btn-secondary btn-sm">
                                    Sayfayı aç
                                    <x-ui.icon name="arrow-right" class="size-3.5" />
                                </a>
                                <form method="post" action="{{ route('platform.cafes.status', $cafe) }}">
                                    @csrf @method('PATCH')
                                    <button class="btn {{ $cafe->status === 'active' ? 'btn-ghost' : 'btn-primary' }} btn-sm">
                                        {{ $cafe->status === 'active' ? 'Askıya al' : 'Etkinleştir' }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="px-5 py-14 text-center">
                        <span class="mx-auto grid size-14 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="coffee" class="size-6" /></span>
                        <h2 class="mt-4 font-display text-xl font-semibold text-ink">Henüz kafe eklenmedi</h2>
                        <p class="mt-1 text-sm text-muted">İlk işletmeyi sağdaki formdan oluşturun.</p>
                    </div>
                @endforelse
            </div>

            @if ($cafes->hasPages())
                <div class="border-t border-line px-5 py-4">{{ $cafes->links() }}</div>
            @endif
        </section>

        <aside class="space-y-4 xl:sticky xl:top-24 xl:self-start">
            <section class="card p-5">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-xl font-semibold text-ink">Yeni kafe</h2>
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">İlk şube ve sahip birlikte</p>
                    </div>
                    <span class="grid size-10 place-items-center rounded-full bg-forest text-amber"><x-ui.icon name="plus" class="size-5" /></span>
                </div>

                <form method="post" action="{{ route('platform.cafes.store') }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="block">
                        <span class="label">Kafe adı</span>
                        <input name="name" value="{{ old('name') }}" required maxlength="120" class="input" placeholder="Örn. Kıyı Kahve">
                    </label>
                    <label class="block">
                        <span class="label">Sayfa kısa adı</span>
                        <input name="slug" value="{{ old('slug') }}" required maxlength="120" pattern="[A-Za-z0-9_-]+" class="input font-mono" placeholder="kiyi-kahve">
                    </label>
                    <label class="block">
                        <span class="label">İlk şube</span>
                        <input name="initial_branch_name" value="{{ old('initial_branch_name', 'Merkez') }}" required maxlength="120" class="input">
                    </label>
                    <div class="border-t border-line pt-3">
                        <p class="text-[10px] font-bold uppercase tracking-[.16em] text-terracotta">İşletme sahibi</p>
                    </div>
                    <label class="block">
                        <span class="label">Ad soyad</span>
                        <input name="owner_name" value="{{ old('owner_name') }}" required maxlength="120" class="input" autocomplete="name">
                    </label>
                    <label class="block">
                        <span class="label">E-posta</span>
                        <input type="email" name="owner_email" value="{{ old('owner_email') }}" required class="input" autocomplete="email">
                    </label>
                    <label class="block">
                        <span class="label">Geçici şifre</span>
                        <input type="password" name="owner_password" minlength="12" required class="input" autocomplete="new-password" placeholder="En az 12 karakter">
                    </label>
                    <p class="rounded-xl bg-cream px-3 py-2 text-[11px] leading-5 text-muted">
                        Kafe oluşturulunca sahip hesabıyla /yonetim adresinden giriş yapılabilir. İlk şube otomatik açılır.
                    </p>
                    <button class="btn btn-primary w-full">
                        <x-ui.icon name="check" class="size-4" /> Kafeyi oluştur
                    </button>
                </form>
            </section>

            <section class="card p-4">
                <div class="flex gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-amber-soft text-[#8a5a14]"><x-ui.icon name="info" class="size-4" /></span>
                    <div class="text-xs leading-5 text-muted">
                        <p class="font-bold text-ink">Erişim yönetimi</p>
                        <p class="mt-0.5">Kafe sahibi giriş bilgileri ve askıya alma işlemleri kafe detay sayfasından yönetilir.</p>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</x-layouts.panel>
