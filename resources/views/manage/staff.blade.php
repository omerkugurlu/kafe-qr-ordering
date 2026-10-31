<x-layouts.panel
    title="Ekip"
    :subtitle="$cafe->name . ' · personel ve şube erişimleri'"
    active="staff"
>
    @php
        $activeCount = $staff->where('is_active', true)->count();
        $roleLabels = [
            'waiter' => 'Garson',
            'kitchen' => 'Mutfak',
            'cafe_manager' => 'İşletme yöneticisi',
        ];
    @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Toplam personel</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $staff->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Garson, mutfak ve yönetici</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Etkin hesap</p>
            <p class="mt-2 font-display text-3xl font-semibold text-success">{{ $activeCount }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Giriş yapabilir</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Şube</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $branches->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Atama yapılabilir</p>
        </article>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)]">
        <section class="card p-5 xl:sticky xl:top-24 xl:self-start">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h2 class="font-display text-xl font-semibold text-ink">Çalışan ekle</h2>
                    <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Yeni hesap oluştur</p>
                </div>
                <span class="grid size-10 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="user" class="size-5" /></span>
            </div>

            <form method="post" action="{{ route('manage.staff.store') }}" class="mt-4 space-y-3">
                @csrf
                <label class="block">
                    <span class="label">Ad soyad</span>
                    <input name="name" maxlength="120" required class="input" autocomplete="name">
                </label>
                <label class="block">
                    <span class="label">E-posta</span>
                    <input type="email" name="email" required class="input" autocomplete="email">
                </label>
                <label class="block">
                    <span class="label">Geçici şifre</span>
                    <input type="password" name="password" minlength="12" required autocomplete="new-password" class="input" placeholder="En az 12 karakter">
                </label>
                <label class="block">
                    <span class="label">Görev</span>
                    <select name="role" class="input">
                        <option value="waiter" @selected(old('role') === 'waiter')>Garson</option>
                        <option value="kitchen" @selected(old('role') === 'kitchen')>Mutfak</option>
                        <option value="cafe_manager" @selected(old('role') === 'cafe_manager')>İşletme yöneticisi</option>
                    </select>
                </label>

                <fieldset>
                    <legend class="label">Erişeceği şubeler</legend>
                    <div class="space-y-1.5">
                        @forelse ($branches as $branch)
                            <label class="flex min-h-10 items-center gap-2 rounded-xl border border-line px-3 py-2 text-sm font-semibold text-ink transition hover:bg-cream/60">
                                <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" @checked(in_array($branch->id, old('branch_ids', []))) class="size-4 accent-forest">
                                {{ $branch->name }}
                            </label>
                        @empty
                            <p class="rounded-xl border border-dashed border-line px-3 py-3 text-xs font-medium text-muted">Önce bir şube oluşturun.</p>
                        @endforelse
                    </div>
                </fieldset>

                <p class="rounded-xl bg-cream px-3 py-2 text-[11px] leading-5 text-muted">
                    Garson ve mutfak hesapları en az bir şubeye atanmalıdır. Şifre ilk girişte değiştirilebilir.
                </p>

                <button class="btn btn-primary w-full">
                    <x-ui.icon name="plus" class="size-4" /> Hesap oluştur
                </button>
            </form>
        </section>

        <section class="space-y-3">
            @forelse ($staff as $person)
                <details class="card group p-5">
                    <summary class="flex cursor-pointer list-none flex-wrap items-start justify-between gap-3 rounded-xl outline-none focus-visible:ring-2 focus-visible:ring-forest focus-visible:ring-offset-2">
                        <div class="flex min-w-0 items-center gap-3">
                            <span class="grid size-11 shrink-0 place-items-center rounded-full bg-cream font-display text-lg font-semibold text-forest">
                                {{ mb_substr($person->name, 0, 1) }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate font-bold text-ink">{{ $person->name }}</p>
                                <p class="truncate text-xs font-medium text-muted">{{ $person->email }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="badge badge-neutral">{{ $roleLabels[$person->role] ?? $person->role }}</span>
                            <span class="badge {{ $person->is_active ? 'badge-ready' : 'badge-cancelled' }}">{{ $person->is_active ? 'Etkin' : 'Kapalı' }}</span>
                            <span class="ml-1 inline-flex min-h-10 items-center gap-1.5 rounded-full border border-line px-3 text-xs font-bold text-forest transition group-open:bg-sage/50">
                                <span class="group-open:hidden">Düzenle</span><span class="hidden group-open:inline">Kapat</span>
                                <svg class="size-4 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                            </span>
                        </div>
                    </summary>

                    <form method="post" action="{{ route('manage.staff.update', $person) }}" class="mt-4 space-y-4 border-t border-line pt-4">
                        @csrf
                        @method('PATCH')
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="block">
                                <span class="label">Ad soyad</span>
                                <input name="name" value="{{ $person->name }}" maxlength="120" required class="input" autocomplete="name">
                            </label>
                            <label class="block">
                                <span class="label">E-posta</span>
                                <input type="email" name="email" value="{{ $person->email }}" maxlength="255" required class="input" autocomplete="email">
                            </label>
                            <label class="block">
                                <span class="label">Yeni şifre</span>
                                <input type="password" name="password" minlength="12" maxlength="255" autocomplete="new-password" class="input" placeholder="Değiştirmek için girin">
                                <span class="mt-1 block text-xs text-muted">Boş bırakırsan mevcut şifre korunur.</span>
                            </label>
                            <label class="block">
                                <span class="label">Görev</span>
                                <select name="role" required class="input">
                                    <option value="waiter" @selected($person->role === 'waiter')>Garson</option>
                                    <option value="kitchen" @selected($person->role === 'kitchen')>Mutfak</option>
                                    <option value="cafe_manager" @selected($person->role === 'cafe_manager')>İşletme yöneticisi</option>
                                </select>
                            </label>
                            <label class="block sm:col-span-2">
                                <span class="label">Hesap durumu</span>
                                <select name="is_active" required class="input">
                                    <option value="1" @selected($person->is_active)>Etkin</option>
                                    <option value="0" @selected(! $person->is_active)>Kapalı</option>
                                </select>
                            </label>
                        </div>

                        <fieldset>
                            <legend class="label">Erişeceği şubeler</legend>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @forelse ($branches as $branch)
                                    <label class="chip cursor-pointer has-[:checked]:border-forest has-[:checked]:bg-sage/50">
                                        <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" @checked($person->branches->contains('id', $branch->id)) class="size-3.5 accent-forest">
                                        {{ $branch->name }}
                                    </label>
                                @empty
                                    <span class="text-xs text-muted">Şube yok</span>
                                @endforelse
                            </div>
                            <p class="mt-2 text-xs leading-5 text-muted">Garson ve mutfak hesapları etkin kalacaksa en az bir şubeye atanmalıdır. İşletme yöneticisi için şube seçimi isteğe bağlıdır.</p>
                        </fieldset>

                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="max-w-md text-xs leading-5 text-muted">Rol, e-posta, şifre veya erişim değiştiğinde personelin yeniden giriş yapması gerekir.</p>
                            <button class="btn btn-primary min-h-11">
                                <x-ui.icon name="check" class="size-4" />
                                Değişiklikleri kaydet
                            </button>
                        </div>
                    </form>
                </details>
            @empty
                <div class="card p-10 text-center">
                    <span class="mx-auto grid size-14 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="users" class="size-6" /></span>
                    <h2 class="mt-4 font-display text-xl font-semibold text-ink">Henüz çalışan hesabı yok</h2>
                    <p class="mt-1 text-sm text-muted">İlk çalışanı soldaki formdan ekleyin.</p>
                </div>
            @endforelse
        </section>
    </div>
</x-layouts.panel>
