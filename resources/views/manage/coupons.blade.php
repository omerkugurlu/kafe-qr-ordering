<x-layouts.panel
    title="Kuponlar"
    :subtitle="$cafe->name . ' · kampanya kodları'"
    active="coupons"
>
    @php
        $activeCoupons = $coupons->where('is_active', true)->filter(fn ($coupon) => $coupon->ends_at === null || $coupon->ends_at->isFuture());
        $totalUses = $coupons->sum('used_count');
    @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Toplam kupon</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $coupons->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Kafenin tüm kodları</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Yayında</p>
            <p class="mt-2 font-display text-3xl font-semibold text-success">{{ $activeCoupons->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Şu an kullanılabilir</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Toplam kullanım</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $totalUses }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Tamamlanan siparişler</p>
        </article>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,.85fr)]">
        <div class="space-y-3">
            @forelse ($coupons as $coupon)
                @php
                    $expired = $coupon->ends_at !== null && $coupon->ends_at->isPast();
                    $exhausted = $coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit;
                    $usable = $coupon->is_active && ! $expired && ! $exhausted;
                @endphp
                <article class="card overflow-hidden">
                    <div class="flex flex-wrap items-start justify-between gap-3 p-5">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-lg border border-dashed border-forest/40 bg-sage/40 px-3 py-1 font-mono text-sm font-bold tracking-[.08em] text-forest">{{ $coupon->code }}</span>
                                @if (! $coupon->is_active)
                                    <span class="badge badge-neutral">Kapalı</span>
                                @elseif ($expired)
                                    <span class="badge badge-cancelled">Süresi doldu</span>
                                @elseif ($exhausted)
                                    <span class="badge badge-cancelled">Limit doldu</span>
                                @else
                                    <span class="badge badge-ready">Yayında</span>
                                @endif
                            </div>
                            <p class="mt-3 font-display text-2xl font-semibold text-ink">
                                @if ($coupon->type === 'percent')
                                    %{{ rtrim(rtrim(number_format((float) $coupon->value, 2, ',', '.'), '0'), ',') }} indirim
                                @else
                                    {{ \App\Services\Money::formatDecimal($coupon->value) }} indirim
                                @endif
                            </p>
                            <p class="mt-1 text-xs font-medium text-muted">
                                @if ((float) $coupon->min_subtotal > 0)
                                    Minimum {{ \App\Services\Money::formatDecimal($coupon->min_subtotal) }} sepet
                                @else
                                    Minimum sepet yok
                                @endif
                                · {{ $coupon->used_count }} kullanım{{ $coupon->usage_limit !== null ? ' / '.$coupon->usage_limit : '' }}
                            </p>
                            <p class="mt-1 text-[11px] font-medium text-muted">
                                @if ($coupon->starts_at || $coupon->ends_at)
                                    {{ $coupon->starts_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? 'Hemen' }}
                                    –
                                    {{ $coupon->ends_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? 'Süresiz' }}
                                @else
                                    Tarih sınırı yok
                                @endif
                            </p>
                        </div>
                        <span class="grid size-11 shrink-0 place-items-center rounded-full {{ $usable ? 'bg-amber-soft text-[#8a5a14]' : 'bg-cream text-muted' }}">
                            <x-ui.icon name="ticket" class="size-5" />
                        </span>
                    </div>

                    <details class="group border-t border-line">
                        <summary class="flex cursor-pointer items-center justify-between px-5 py-3 text-xs font-bold text-forest transition hover:bg-cream/50">
                            Düzenle
                            <x-ui.icon name="chevron-down" class="size-4 transition group-open:rotate-180" />
                        </summary>
                        <form method="post" action="{{ route('manage.coupons.update', $coupon) }}" class="grid gap-3 border-t border-line bg-cream/30 p-5 sm:grid-cols-2">
                            @csrf @method('PATCH')
                            <label class="block">
                                <span class="label">Kod</span>
                                <input name="code" value="{{ old('code', $coupon->code) }}" required maxlength="40" class="input font-mono uppercase">
                            </label>
                            <label class="block">
                                <span class="label">Tür</span>
                                <select name="type" class="input">
                                    <option value="percent" @selected(old('type', $coupon->type) === 'percent')>Yüzde (%)</option>
                                    <option value="fixed" @selected(old('type', $coupon->type) === 'fixed')>Sabit tutar (₺)</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="label">Değer</span>
                                <input type="number" name="value" value="{{ old('value', $coupon->value) }}" min="0.01" step="0.01" required class="input">
                            </label>
                            <label class="block">
                                <span class="label">Min. sepet (₺)</span>
                                <input type="number" name="min_subtotal" value="{{ old('min_subtotal', $coupon->min_subtotal) }}" min="0" step="0.01" class="input">
                            </label>
                            <label class="block">
                                <span class="label">Başlangıç</span>
                                <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $coupon->starts_at?->format('Y-m-d\TH:i')) }}" class="input">
                            </label>
                            <label class="block">
                                <span class="label">Bitiş</span>
                                <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $coupon->ends_at?->format('Y-m-d\TH:i')) }}" class="input">
                            </label>
                            <label class="block">
                                <span class="label">Kullanım limiti</span>
                                <input type="number" name="usage_limit" value="{{ old('usage_limit', $coupon->usage_limit) }}" min="1" class="input" placeholder="Sınırsız">
                            </label>
                            <div class="flex items-end">
                                <input type="hidden" name="is_active" value="0">
                                <label class="flex min-h-10 items-center gap-2 text-sm font-semibold text-ink">
                                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $coupon->is_active)) class="size-4 accent-forest">
                                    Yayında
                                </label>
                            </div>
                            <div class="flex items-center justify-between gap-2 sm:col-span-2">
                                <button class="btn btn-primary btn-sm">Değişiklikleri kaydet</button>
                            </div>
                        </form>
                        @if ($coupon->is_active)
                            <form method="post" action="{{ route('manage.coupons.destroy', $coupon) }}" class="border-t border-line px-5 py-3" data-confirm="{{ $coupon->code }} kuponunu kapatmak istiyor musunuz?">
                                @csrf @method('DELETE')
                                <button class="text-xs font-bold text-danger hover:underline">Kuponu kapat</button>
                            </form>
                        @endif
                    </details>
                </article>
            @empty
                <div class="card p-10 text-center">
                    <span class="mx-auto grid size-14 place-items-center rounded-full bg-amber-soft text-[#8a5a14]"><x-ui.icon name="ticket" class="size-6" /></span>
                    <h2 class="mt-4 font-display text-xl font-semibold text-ink">Henüz kupon yok</h2>
                    <p class="mt-1 text-sm text-muted">İlk kampanya kodunuzu sağdaki formdan oluşturun.</p>
                </div>
            @endforelse
        </div>

        <div class="xl:sticky xl:top-24 xl:self-start">
            <form method="post" action="{{ route('manage.coupons.store') }}" class="card p-5">
                @csrf
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-xl font-semibold text-ink">Yeni kupon</h2>
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Kampanya kodu oluştur</p>
                    </div>
                    <span class="grid size-10 place-items-center rounded-full bg-forest text-amber"><x-ui.icon name="sparkles" class="size-5" /></span>
                </div>

                <div class="mt-4 space-y-3">
                    <label class="block">
                        <span class="label">Kupon kodu</span>
                        <input name="code" value="{{ old('code') }}" required maxlength="40" class="input font-mono uppercase" placeholder="HOSGELDIN10">
                        <span class="mt-1 block text-[11px] text-muted">Büyük harfe çevrilir, kafe içinde tekil olmalıdır.</span>
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="label">Tür</span>
                            <select name="type" class="input">
                                <option value="percent" @selected(old('type') === 'percent')>Yüzde</option>
                                <option value="fixed" @selected(old('type') === 'fixed')>Sabit tutar</option>
                            </select>
                        </label>
                        <label class="block">
                            <span class="label">Değer</span>
                            <input type="number" name="value" value="{{ old('value') }}" min="0.01" step="0.01" required class="input" placeholder="10">
                        </label>
                    </div>
                    <label class="block">
                        <span class="label">Minimum sepet (₺)</span>
                        <input type="number" name="min_subtotal" value="{{ old('min_subtotal') }}" min="0" step="0.01" class="input" placeholder="0">
                    </label>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block">
                            <span class="label">Başlangıç</span>
                            <input type="datetime-local" name="starts_at" value="{{ old('starts_at') }}" class="input text-xs">
                        </label>
                        <label class="block">
                            <span class="label">Bitiş</span>
                            <input type="datetime-local" name="ends_at" value="{{ old('ends_at') }}" class="input text-xs">
                        </label>
                    </div>
                    <label class="block">
                        <span class="label">Kullanım limiti</span>
                        <input type="number" name="usage_limit" value="{{ old('usage_limit') }}" min="1" class="input" placeholder="Sınırsız">
                    </label>
                    <input type="hidden" name="is_active" value="1">
                    <button class="btn btn-primary w-full">
                        <x-ui.icon name="plus" class="size-4" /> Kupon oluştur
                    </button>
                </div>
            </form>

            <div class="card mt-4 p-4">
                <div class="flex gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-amber-soft text-[#8a5a14]"><x-ui.icon name="info" class="size-4" /></span>
                    <p class="text-xs leading-5 text-muted">
                        Kupon indirimi sipariş toplamına uygulanır. Aynı siparişte sadakat ödülü ile birlikte kullanılamaz.
                    </p>
                </div>
            </div>
        </div>
    </div>
</x-layouts.panel>
