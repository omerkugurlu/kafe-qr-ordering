<x-layouts.panel
    title="Ayarlar"
    :subtitle="$cafe->name . ' · marka, operasyon ve şubeler'"
    active="settings"
>
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-2xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm font-semibold text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,.85fr)]">
        <form method="post" action="{{ route('manage.settings.update') }}" enctype="multipart/form-data" class="space-y-5">
            @csrf
            @method('PATCH')

            <section class="card overflow-hidden">
                <div class="relative h-40 overflow-hidden bg-forest">
                    @if ($cafe->cover_url)
                        <img src="{{ $cafe->cover_url }}" alt="" class="size-full object-cover opacity-80">
                    @else
                        <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(233,169,77,.28),transparent_55%),radial-gradient(circle_at_80%_0%,rgba(221,231,218,.2),transparent_45%)]"></div>
                    @endif
                    <div class="absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-ink/70 to-transparent"></div>
                    <div class="absolute bottom-4 left-5 flex items-end gap-3">
                        @if ($cafe->logo_url)
                            <img src="{{ $cafe->logo_url }}" alt="{{ $cafe->name }}" class="size-16 rounded-2xl border-2 border-paper object-cover shadow-soft">
                        @else
                            <span class="grid size-16 place-items-center rounded-2xl border-2 border-paper bg-amber font-display text-2xl font-semibold text-ink shadow-soft">
                                {{ mb_substr($cafe->brand_name ?: $cafe->name, 0, 1) }}
                            </span>
                        @endif
                        <div class="pb-1">
                            <p class="font-display text-2xl font-semibold text-white">{{ $cafe->brand_name ?: $cafe->name }}</p>
                            <p class="text-[11px] font-semibold uppercase tracking-[.16em] text-white/60">{{ $cafe->status === 'active' ? 'Hesap aktif' : 'Hesap askıda' }}</p>
                        </div>
                    </div>
                </div>

                <div class="p-5">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <h2 class="font-display text-xl font-semibold text-ink">Marka profili</h2>
                            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Müşteri menüsünde görünür</p>
                        </div>
                        <span class="grid size-10 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="sparkles" class="size-5" /></span>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="label">Kafe adı</span>
                            <input name="name" value="{{ old('name', $cafe->name) }}" required maxlength="120" class="input">
                        </label>
                        <label class="block">
                            <span class="label">Marka adı</span>
                            <input name="brand_name" value="{{ old('brand_name', $cafe->brand_name) }}" maxlength="120" class="input" placeholder="Menüde görünecek ad">
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="label">Açıklama</span>
                            <textarea name="description" rows="3" maxlength="1000" class="input" placeholder="Kafenizi birkaç cümleyle anlatın">{{ old('description', $cafe->description) }}</textarea>
                        </label>
                        <label class="block">
                            <span class="label">Telefon</span>
                            <input name="phone" value="{{ old('phone', $cafe->phone) }}" maxlength="40" class="input" placeholder="+90 392 ...">
                        </label>
                        <label class="block">
                            <span class="label">Instagram</span>
                            <input name="instagram" value="{{ old('instagram', $cafe->instagram) }}" maxlength="120" class="input" placeholder="cafeadi">
                        </label>
                        <label class="block sm:col-span-2">
                            <span class="label">Adres</span>
                            <input name="address" value="{{ old('address', $cafe->address) }}" maxlength="255" class="input">
                        </label>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-2xl border border-line p-3">
                            <p class="text-xs font-bold text-ink">Logo</p>
                            <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" class="mt-2 block w-full text-xs text-muted file:mr-2 file:rounded-lg file:border-0 file:bg-sage file:px-3 file:py-2 file:text-xs file:font-bold file:text-forest">
                            <input name="logo_url" value="{{ old('logo_url', str_starts_with((string) $cafe->logo_path, 'http') ? $cafe->logo_path : '') }}" class="input mt-2 text-xs" placeholder="veya görsel URL">
                            @if ($cafe->logo_path)
                                <label class="mt-2 flex items-center gap-2 text-[11px] font-semibold text-danger">
                                    <input type="checkbox" name="remove_logo" value="1" class="size-3.5 accent-danger"> Logoyu kaldır
                                </label>
                            @endif
                        </div>
                        <div class="rounded-2xl border border-line p-3">
                            <p class="text-xs font-bold text-ink">Kapak görseli</p>
                            <input type="file" name="cover" accept="image/jpeg,image/png,image/webp" class="mt-2 block w-full text-xs text-muted file:mr-2 file:rounded-lg file:border-0 file:bg-sage file:px-3 file:py-2 file:text-xs file:font-bold file:text-forest">
                            <input name="cover_url" value="{{ old('cover_url', str_starts_with((string) $cafe->cover_path, 'http') ? $cafe->cover_path : '') }}" class="input mt-2 text-xs" placeholder="veya görsel URL">
                            @if ($cafe->cover_path)
                                <label class="mt-2 flex items-center gap-2 text-[11px] font-semibold text-danger">
                                    <input type="checkbox" name="remove_cover" value="1" class="size-3.5 accent-danger"> Kapak görselini kaldır
                                </label>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            <section class="card p-5">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-xl font-semibold text-ink">Operasyon</h2>
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Müşteri kaydı ve sadakat</p>
                    </div>
                    <span class="grid size-10 place-items-center rounded-full bg-amber-soft text-[#8a5a14]"><x-ui.icon name="settings" class="size-5" /></span>
                </div>

                <fieldset class="mt-4">
                    <legend class="label">Müşteri kayıt politikası</legend>
                    <div class="grid gap-2 sm:grid-cols-3">
                        @foreach ([
                            ['value' => 'guest', 'title' => 'Misafir', 'text' => 'Kayıt olmadan sipariş'],
                            ['value' => 'optional', 'title' => 'Opsiyonel', 'text' => 'İsteyen kayıt olur'],
                            ['value' => 'required', 'title' => 'Zorunlu', 'text' => 'Sipariş için kayıt şart'],
                        ] as $policy)
                            <label class="relative flex cursor-pointer flex-col gap-1 rounded-2xl border border-line p-3 transition has-[:checked]:border-forest has-[:checked]:bg-sage/40">
                                <input type="radio" name="registration_policy" value="{{ $policy['value'] }}" @checked(old('registration_policy', $cafe->registration_policy) === $policy['value']) class="sr-only">
                                <span class="text-sm font-bold text-ink">{{ $policy['title'] }}</span>
                                <span class="text-[11px] leading-4 text-muted">{{ $policy['text'] }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div class="mt-4 rounded-2xl border border-line p-4">
                    <input type="hidden" name="loyalty_enabled" value="0">
                    <label class="flex items-center justify-between gap-3">
                        <span>
                            <span class="block text-sm font-bold text-ink">Sadakat puanı</span>
                            <span class="block text-[11px] text-muted">Harcama başına puan kazanımı</span>
                        </span>
                        <input type="checkbox" name="loyalty_enabled" value="1" @checked(old('loyalty_enabled', $cafe->loyalty_enabled)) class="size-5 accent-forest">
                    </label>
                    <label class="mt-3 block">
                        <span class="label">Her 100 ₺ için puan</span>
                        <input type="number" name="points_per_100" value="{{ old('points_per_100', $cafe->points_per_100) }}" min="1" max="100" required class="input">
                    </label>
                </div>

                <button class="btn btn-primary mt-4 w-full">
                    <x-ui.icon name="check" class="size-4" /> Ayarları kaydet
                </button>
            </section>
        </form>

        <div class="space-y-5">
            <section class="card p-5">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-xl font-semibold text-ink">Şubeler</h2>
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">{{ $branches->count() }} şube</p>
                    </div>
                    <span class="grid size-10 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="pin" class="size-5" /></span>
                </div>

                <div class="mt-4 space-y-2">
                    @forelse ($branches as $branch)
                        <div class="rounded-2xl border border-line p-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-bold text-ink">{{ $branch->name }}</p>
                                    <p class="mt-0.5 text-[11px] font-medium text-muted">{{ $branch->dining_tables_count }} masa · {{ $branch->is_active ? 'aktif' : 'kapalı' }} · {{ $branch->uses_shared_menu ? 'ortak menü' : 'özel menü' }}</p>
                                </div>
                                <span class="badge {{ $branch->is_active ? 'badge-ready' : 'badge-neutral' }}">{{ $branch->is_active ? 'Aktif' : 'Kapalı' }}</span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <a href="{{ route('manage.branches.show', $branch) }}" class="btn btn-secondary btn-sm">Menü</a>
                                <a href="{{ route('manage.tables.index', $branch) }}" class="btn btn-secondary btn-sm">Masalar</a>
                            </div>
                        </div>
                    @empty
                        <p class="rounded-2xl border border-dashed border-line px-4 py-6 text-center text-sm font-medium text-muted">Henüz şube yok.</p>
                    @endforelse
                </div>
            </section>

            <section class="card p-5">
                <h2 class="font-display text-xl font-semibold text-ink">Yeni şube</h2>
                <p class="mt-0.5 text-xs text-muted">Şube oluşturulduktan sonra masaları ve menüyü düzenleyebilirsiniz.</p>
                <form method="post" action="{{ route('manage.branches.store') }}" class="mt-3 space-y-3">
                    @csrf
                    <label class="block">
                        <span class="label">Şube adı</span>
                        <input name="name" required maxlength="120" class="input" placeholder="Örn. Girne Marina">
                    </label>
                    <label class="block">
                        <span class="label">Adres</span>
                        <input name="address" maxlength="255" class="input">
                    </label>
                    <label class="block">
                        <span class="label">Telefon</span>
                        <input name="phone" maxlength="40" class="input">
                    </label>
                    <button class="btn btn-secondary w-full">
                        <x-ui.icon name="plus" class="size-4" /> Şube oluştur
                    </button>
                </form>
            </section>

            <section class="card p-5">
                <div class="flex items-center gap-2">
                    <span class="grid size-9 place-items-center rounded-full bg-cream text-forest"><x-ui.icon name="lock" class="size-4" /></span>
                    <h2 class="font-display text-lg font-semibold text-ink">Güvenlik</h2>
                </div>
                <p class="mt-2 text-xs leading-5 text-muted">
                    Personel hesapları ekip sayfasından yönetilir. Şifre değişiklikleri tüm açık oturumları güvenli şekilde sonlandırır.
                </p>
                <a href="{{ route('manage.staff.index') }}" class="btn btn-secondary btn-sm mt-3 w-full">Ekibi yönet</a>
            </section>
        </div>
    </div>
</x-layouts.panel>
