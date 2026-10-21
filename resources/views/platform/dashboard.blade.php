<x-layouts.app title="Süper admin">
    <div class="space-y-8 sm:space-y-10">
        <header class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-clay">Platform yönetimi</p>
                <h1 class="mt-2 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">Kafeler</h1>
                <p class="mt-2 max-w-xl text-sm leading-6 text-muted sm:text-base">İşletmeleri, şube sahiplerini ve kullanım durumunu tek yerden yönetin.</p>
            </div>
            <span class="inline-flex w-fit items-center gap-2 rounded-full bg-lime/45 px-4 py-2 text-sm font-bold text-leaf"><span class="size-2 rounded-full bg-leaf"></span> Platform aktif</span>
        </header>

        <section aria-label="Platform istatistikleri" class="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4">
            @foreach ([['label' => 'Kafe toplamı', 'value' => $totalCafes, 'icon' => '☕'], ['label' => 'Aktif kafeler', 'value' => $activeCafes, 'icon' => '↗'], ['label' => 'Aktif masalar', 'value' => $activeTables, 'icon' => '▦'], ['label' => 'Aktif müşteriler', 'value' => $activeCustomers, 'icon' => '◉']] as $stat)
                <article class="rounded-2xl border border-ink/10 bg-paper p-4 panel-shadow sm:rounded-3xl sm:p-5">
                    <div class="flex items-start justify-between gap-2"><span class="grid size-9 place-items-center rounded-xl bg-cream text-lg text-leaf sm:size-10">{{ $stat['icon'] }}</span><span class="text-[10px] font-bold uppercase tracking-[.12em] text-muted sm:text-xs">Canlı</span></div>
                    <p class="mt-4 font-display text-2xl font-extrabold sm:text-3xl">{{ number_format($stat['value'], 0, ',', '.') }}</p>
                    <h2 class="mt-1 text-xs font-semibold text-muted sm:text-sm">{{ $stat['label'] }}</h2>
                </article>
            @endforeach
        </section>

        <div class="grid items-start gap-6 xl:grid-cols-[minmax(18rem,.72fr)_minmax(0,1.28fr)]">
            <section class="rounded-3xl border border-ink/10 bg-paper p-5 panel-shadow sm:p-7">
                <div class="mb-6">
                    <p class="text-xs font-bold uppercase tracking-[.14em] text-clay">Yeni işletme</p>
                    <h2 class="mt-1 font-display text-xl font-extrabold sm:text-2xl">Kafe ekle</h2>
                    <p class="mt-1 text-sm text-muted">İlk şube ve sahibi birlikte oluşturulur.</p>
                </div>
                <form method="post" action="{{ route('platform.cafes.store') }}" class="space-y-4">@csrf
                    <label class="block text-sm font-bold">Kafe adı<input name="name" value="{{ old('name') }}" required maxlength="120" class="mt-1.5 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3 outline-none focus:border-leaf" placeholder="Örn. Kıyı Kahve"></label>
                    <label class="block text-sm font-bold">Sayfa kısa adı<input name="slug" value="{{ old('slug') }}" required maxlength="120" pattern="[A-Za-z0-9_-]+" class="mt-1.5 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3 outline-none focus:border-leaf" placeholder="kiyi-kahve"></label>
                    <label class="block text-sm font-bold">İlk şube<input name="initial_branch_name" value="{{ old('initial_branch_name', 'Merkez') }}" required maxlength="120" class="mt-1.5 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3 outline-none focus:border-leaf"></label>
                    <label class="block text-sm font-bold">İşletme sahibi<input name="owner_name" value="{{ old('owner_name') }}" required maxlength="120" class="mt-1.5 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3 outline-none focus:border-leaf"></label>
                    <label class="block text-sm font-bold">Sahip e-posta adresi<input type="email" name="owner_email" value="{{ old('owner_email') }}" required autocomplete="email" class="mt-1.5 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3 outline-none focus:border-leaf"></label>
                    <label class="block text-sm font-bold">Geçici şifre <span class="font-normal text-muted">(en az 12 karakter)</span><input type="password" name="owner_password" minlength="12" required autocomplete="new-password" class="mt-1.5 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3 outline-none focus:border-leaf"></label>
                    <button class="w-full rounded-xl bg-leaf px-5 py-3.5 font-bold text-white transition hover:bg-ink">Kafeyi oluştur <span aria-hidden="true">→</span></button>
                </form>
            </section>

            <section class="min-w-0 overflow-hidden rounded-3xl border border-ink/10 bg-paper panel-shadow">
                <header class="flex flex-col gap-2 border-b border-ink/10 px-5 py-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <div><p class="text-xs font-bold uppercase tracking-[.14em] text-clay">İşletmeler</p><h2 class="mt-1 font-display text-xl font-extrabold">Kayıtlı kafeler</h2></div>
                    <span class="w-fit rounded-full bg-cream px-3 py-1.5 text-xs font-bold">{{ number_format($totalCafes, 0, ',', '.') }} toplam</span>
                </header>
                <div class="divide-y divide-ink/10">
                    @forelse ($cafes as $cafe)
                        @php($owner = $cafe->users->firstWhere('role', \App\Models\User::ROLE_CAFE_OWNER))
                        <article class="min-w-0 p-4 sm:p-6">
                            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                <div class="flex min-w-0 items-center gap-3 sm:gap-4">
                                    <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-lime/45 font-display text-lg font-extrabold text-leaf sm:size-12">{{ mb_strtoupper(mb_substr($cafe->name, 0, 1)) }}</span>
                                    <div class="min-w-0"><h3 class="truncate font-display text-lg font-extrabold">{{ $cafe->name }}</h3><p class="mt-0.5 truncate text-xs text-muted sm:text-sm">/{{ $cafe->slug }} · {{ $cafe->branches_count }} şube · {{ $cafe->users_count }} kullanıcı</p>@if($owner)<p class="mt-1 truncate text-xs text-muted">Sahip: {{ $owner->name }} · {{ $owner->email }}</p>@endif</div>
                                </div>
                                <div class="flex items-center justify-between gap-3 sm:justify-end">
                                    <span class="rounded-full px-3 py-1.5 text-xs font-bold {{ $cafe->status === 'active' ? 'bg-lime/40 text-leaf' : 'bg-clay/15 text-clay' }}">{{ $cafe->status === 'active' ? 'Aktif' : 'Askıda' }}</span>
                                    <form method="post" action="{{ route('platform.cafes.status', $cafe) }}">@csrf @method('PATCH')<button class="min-h-10 rounded-lg border border-ink/10 px-3 py-2 text-xs font-bold">{{ $cafe->status === 'active' ? 'Askıya al' : 'Etkinleştir' }}</button></form>
                                </div>
                            </div>

                            <details class="group mt-4 rounded-2xl bg-cream/65">
                                <summary class="flex min-h-12 cursor-pointer list-none items-center justify-between gap-3 rounded-2xl px-4 py-3 text-sm font-bold marker:hidden focus-visible:outline focus-visible:outline-2 focus-visible:outline-leaf">
                                    <span>Kafe ve hesap ayarları</span><span class="text-leaf transition group-open:rotate-180" aria-hidden="true">⌄</span>
                                </summary>
                                <div class="grid gap-5 border-t border-ink/10 p-4 lg:grid-cols-2">
                                    <form method="post" action="{{ route('platform.cafes.update', $cafe) }}" class="space-y-3">@csrf @method('PATCH')
                                        <h4 class="font-display font-extrabold">Kafe bilgileri</h4>
                                        <label class="block text-xs font-bold">Kafe adı<input name="name" value="{{ $cafe->name }}" required maxlength="120" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2.5 text-sm"></label>
                                        <label class="block text-xs font-bold">Sayfa kısa adı<input name="slug" value="{{ $cafe->slug }}" required maxlength="120" pattern="[A-Za-z0-9_-]+" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2.5 text-sm"></label>
                                        <label class="block text-xs font-bold">Marka adı<input name="brand_name" value="{{ $cafe->brand_name }}" maxlength="120" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2.5 text-sm"></label>
                                        <label class="block text-xs font-bold">Durum<select name="status" required class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2.5 text-sm"><option value="active" @selected($cafe->status === 'active')>Aktif</option><option value="suspended" @selected($cafe->status === 'suspended')>Askıda</option></select></label>
                                        <label class="block text-xs font-bold">Müşteri kayıt politikası<select name="registration_policy" required class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2.5 text-sm"><option value="guest" @selected($cafe->registration_policy === 'guest')>Hesapsız sipariş</option><option value="optional" @selected($cafe->registration_policy === 'optional')>Hesap isteğe bağlı</option><option value="required" @selected($cafe->registration_policy === 'required')>Hesap zorunlu</option></select></label>
                                        <label class="flex min-h-10 items-center gap-2 text-xs font-bold"><input type="hidden" name="loyalty_enabled" value="0"><input type="checkbox" name="loyalty_enabled" value="1" @checked($cafe->loyalty_enabled) class="size-4 accent-leaf"> Sadakat programı açık</label>
                                        <label class="block text-xs font-bold">Her 100 TL için puan<input type="number" name="points_per_100" value="{{ $cafe->points_per_100 }}" min="0" max="10000" required class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2.5 text-sm"></label>
                                        <button class="min-h-11 w-full rounded-lg bg-ink px-4 py-2.5 text-sm font-bold text-white">Kafe ayarlarını kaydet</button>
                                    </form>

                                    @if($owner)
                                        <form method="post" action="{{ route('platform.cafes.owner.update', $cafe) }}" class="space-y-3">@csrf @method('PATCH')
                                            <h4 class="font-display font-extrabold">Sahip giriş bilgileri</h4>
                                            <label class="block text-xs font-bold">Ad soyad<input name="owner_name" value="{{ $owner->name }}" required maxlength="120" autocomplete="name" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2.5 text-sm"></label>
                                            <label class="block text-xs font-bold">Giriş e-postası<input type="email" name="owner_email" value="{{ $owner->email }}" required autocomplete="email" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2.5 text-sm"></label>
                                            <label class="flex min-h-10 items-center gap-2 text-xs font-bold"><input type="hidden" name="owner_is_active" value="0"><input type="checkbox" name="owner_is_active" value="1" @checked($owner->is_active) class="size-4 accent-leaf"> Sahip hesabı aktif</label>
                                            <label class="block text-xs font-bold">Yeni şifre <span class="font-normal text-muted">(sıfırlamak için doldur, en az 12 karakter)</span><input type="password" name="owner_password" minlength="12" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2.5 text-sm"></label>
                                            <label class="block text-xs font-bold">Yeni şifreyi tekrar gir<input type="password" name="owner_password_confirmation" minlength="12" autocomplete="new-password" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2.5 text-sm"></label>
                                            <button class="min-h-11 w-full rounded-lg bg-leaf px-4 py-2.5 text-sm font-bold text-white">Sahip hesabını güncelle</button>
                                            <p class="text-xs leading-5 text-muted">Mevcut şifre görüntülenmez. Yeni şifre yalnızca kaydedilir; denetim kaydında şifrenin kendisi tutulmaz.</p>
                                        </form>
                                    @else
                                        <div class="rounded-xl border border-dashed border-ink/20 p-4"><h4 class="font-display font-extrabold">Sahip hesabı yok</h4><p class="mt-1 text-sm text-muted">Bu kafe için yetkili sahibi hesabı bulunamadı.</p></div>
                                    @endif
                                </div>
                            </details>
                        </article>
                    @empty
                        <div class="px-6 py-16 text-center"><span class="text-3xl">☕</span><p class="mt-3 font-bold">Henüz kafe eklenmedi</p><p class="mt-1 text-sm text-muted">İlk işletmeyi yukarıdaki formdan oluşturun.</p></div>
                    @endforelse
                </div>
                <div class="border-t border-ink/10 px-5 py-4 sm:px-6">{{ $cafes->links() }}</div>
            </section>
        </div>
    </div>
</x-layouts.app>
