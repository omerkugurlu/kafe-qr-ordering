<x-layouts.panel
    title="Sadakat"
    :subtitle="$cafe->name . ' · ödül kampanyaları'"
    active="loyalty"
>
    @unless ($cafe->loyalty_enabled)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-amber/40 bg-amber-soft px-4 py-3">
            <p class="text-sm font-semibold text-[#8a5a14]">Sadakat programı kapalı. Puan birikmesi için ayarlardan etkinleştirin.</p>
            <a href="{{ route('manage.settings.index') }}" class="btn btn-secondary btn-sm">Ayarlara git</a>
        </div>
    @endunless

    @php
        $activeCampaigns = $campaigns->where('is_active', true)->count();
        $totalRedemptions = $campaigns->sum('transactions_count');
    @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Kampanya</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $campaigns->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">{{ $activeCampaigns }} tanesi yayında</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Ödül kullanımı</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $totalRedemptions }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Toplam işlem</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Puan oranı</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $cafe->points_per_100 }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Her 100 ₺ için puan</p>
        </article>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)]">
        <section class="card p-5 xl:sticky xl:top-24 xl:self-start">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h2 class="font-display text-xl font-semibold text-ink">Kampanya ekle</h2>
                    <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Ödül kuralı tanımla</p>
                </div>
                <span class="grid size-10 place-items-center rounded-full bg-amber-soft text-[#8a5a14]"><x-ui.icon name="star" class="size-5" /></span>
            </div>

            <form method="post" action="{{ route('manage.loyalty.store') }}" class="mt-4 space-y-3">
                @csrf
                <label class="block">
                    <span class="label">Kampanya adı</span>
                    <input name="name" maxlength="120" required class="input" placeholder="10 kahvede %10 indirim">
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="label">Kazanma koşulu</span>
                        <select name="type" class="input">
                            <option value="order_count" @selected(old('type') === 'order_count')>Sipariş sayısı</option>
                            <option value="points" @selected(old('type') === 'points')>Puan</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="label">Gerekli sayı</span>
                        <input type="number" name="threshold" min="1" max="100000" value="{{ old('threshold', 10) }}" required class="input">
                    </label>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="label">Ödül türü</span>
                        <select name="reward_type" class="input">
                            <option value="percent" @selected(old('reward_type') === 'percent')>Yüzde</option>
                            <option value="fixed" @selected(old('reward_type') === 'fixed')>Sabit tutar</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="label">İndirim</span>
                        <input type="number" name="reward_value" min="0.01" max="100000" step="0.01" value="{{ old('reward_value', 10) }}" required class="input">
                    </label>
                </div>
                <label class="block">
                    <span class="label">Minimum sipariş (₺)</span>
                    <input type="number" name="minimum_order_total" min="0" max="1000000" step="0.01" value="{{ old('minimum_order_total', 0) }}" class="input">
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
                <input type="hidden" name="is_active" value="1">
                <button class="btn btn-primary w-full">
                    <x-ui.icon name="plus" class="size-4" /> Kampanya oluştur
                </button>
            </form>
        </section>

        <section class="space-y-3">
            @forelse ($campaigns as $campaign)
                <article class="card p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-display text-xl font-semibold text-ink">{{ $campaign->name }}</h2>
                                <span class="badge {{ $campaign->is_active ? 'badge-ready' : 'badge-neutral' }}">{{ $campaign->is_active ? 'Yayında' : 'Kapalı' }}</span>
                            </div>
                            <p class="mt-1 text-sm font-medium text-muted">
                                {{ $campaign->type === 'order_count' ? $campaign->threshold.' tamamlanmış sipariş' : $campaign->threshold.' puan' }}
                                karşılığında
                                <strong class="text-ink">{{ $campaign->reward_type === 'percent' ? '%'.rtrim(rtrim(number_format((float) $campaign->reward_value, 2, ',', '.'), '0'), ',') : \App\Services\Money::formatDecimal($campaign->reward_value) }}</strong>
                                indirim
                            </p>
                            <p class="mt-1 text-[11px] font-medium text-muted">
                                {{ $campaign->transactions_count }} işlem · min. {{ \App\Services\Money::formatDecimal($campaign->minimum_order_total) }} sepet
                                @if ($campaign->ends_at)
                                    · {{ $campaign->ends_at->timezone('Europe/Istanbul')->format('d.m.Y') }} bitiş
                                @endif
                            </p>
                        </div>
                        <span class="grid size-11 shrink-0 place-items-center rounded-full {{ $campaign->is_active ? 'bg-amber-soft text-[#8a5a14]' : 'bg-cream text-muted' }}">
                            <x-ui.icon name="star" class="size-5" />
                        </span>
                    </div>

                    <details class="group mt-4 border-t border-line pt-3">
                        <summary class="flex cursor-pointer items-center justify-between text-xs font-bold text-forest">
                            Düzenle
                            <x-ui.icon name="chevron-down" class="size-4 transition group-open:rotate-180" />
                        </summary>
                        <form method="post" action="{{ route('manage.loyalty.update', $campaign) }}" class="mt-3 grid gap-3 sm:grid-cols-2">
                            @csrf @method('PATCH')
                            <label class="block sm:col-span-2">
                                <span class="label">Kampanya adı</span>
                                <input name="name" value="{{ $campaign->name }}" required maxlength="120" class="input">
                            </label>
                            <label class="block">
                                <span class="label">Koşul</span>
                                <select name="type" class="input">
                                    <option value="order_count" @selected($campaign->type === 'order_count')>Sipariş sayısı</option>
                                    <option value="points" @selected($campaign->type === 'points')>Puan</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="label">Gerekli sayı</span>
                                <input type="number" name="threshold" value="{{ $campaign->threshold }}" min="1" required class="input">
                            </label>
                            <label class="block">
                                <span class="label">Ödül türü</span>
                                <select name="reward_type" class="input">
                                    <option value="percent" @selected($campaign->reward_type === 'percent')>Yüzde</option>
                                    <option value="fixed" @selected($campaign->reward_type === 'fixed')>Sabit tutar</option>
                                </select>
                            </label>
                            <label class="block">
                                <span class="label">İndirim</span>
                                <input type="number" name="reward_value" value="{{ $campaign->reward_value }}" min="0.01" step="0.01" required class="input">
                            </label>
                            <label class="block">
                                <span class="label">Min. sipariş (₺)</span>
                                <input type="number" name="minimum_order_total" value="{{ $campaign->minimum_order_total }}" min="0" step="0.01" class="input">
                            </label>
                            <label class="block">
                                <span class="label">Bitiş</span>
                                <input type="datetime-local" name="ends_at" value="{{ $campaign->ends_at?->format('Y-m-d\TH:i') }}" class="input text-xs">
                            </label>
                            <input type="hidden" name="starts_at" value="{{ $campaign->starts_at?->format('Y-m-d\TH:i') }}">
                            <input type="hidden" name="is_active" value="{{ $campaign->is_active ? 1 : 0 }}">
                            <div class="flex items-end gap-2 sm:col-span-2">
                                <button class="btn btn-primary btn-sm">Kaydet</button>
                            </div>
                        </form>
                        <form method="post" action="{{ route('manage.loyalty.update', $campaign) }}" class="mt-2" data-confirm="{{ $campaign->name }} kampanyasını {{ $campaign->is_active ? 'kapatmak' : 'açmak' }} istiyor musunuz?">
                            @csrf @method('PATCH')
                            <input type="hidden" name="name" value="{{ $campaign->name }}">
                            <input type="hidden" name="type" value="{{ $campaign->type }}">
                            <input type="hidden" name="threshold" value="{{ $campaign->threshold }}">
                            <input type="hidden" name="points_required" value="{{ $campaign->points_required }}">
                            <input type="hidden" name="reward_type" value="{{ $campaign->reward_type }}">
                            <input type="hidden" name="reward_value" value="{{ $campaign->reward_value }}">
                            <input type="hidden" name="minimum_order_total" value="{{ $campaign->minimum_order_total }}">
                            <input type="hidden" name="starts_at" value="{{ $campaign->starts_at?->format('Y-m-d\TH:i') }}">
                            <input type="hidden" name="ends_at" value="{{ $campaign->ends_at?->format('Y-m-d\TH:i') }}">
                            <input type="hidden" name="is_active" value="{{ $campaign->is_active ? 0 : 1 }}">
                            <button class="text-xs font-bold {{ $campaign->is_active ? 'text-danger' : 'text-forest' }} hover:underline">
                                {{ $campaign->is_active ? 'Kampanyayı kapat' : 'Kampanyayı aç' }}
                            </button>
                        </form>
                    </details>
                </article>
            @empty
                <div class="card p-10 text-center">
                    <span class="mx-auto grid size-14 place-items-center rounded-full bg-amber-soft text-[#8a5a14]"><x-ui.icon name="star" class="size-6" /></span>
                    <h2 class="mt-4 font-display text-xl font-semibold text-ink">Henüz kampanya yok</h2>
                    <p class="mt-1 text-sm text-muted">İlk ödül kampanyanızı soldaki formdan oluşturun.</p>
                </div>
            @endforelse

            <div class="card p-4">
                <div class="flex gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-cream text-forest"><x-ui.icon name="info" class="size-4" /></span>
                    <p class="text-xs leading-5 text-muted">
                        Ödüller yalnızca hesabına giriş yapan müşterilerin ödemesi tamamlanan siparişlerinde birikir. Aynı siparişte kupon ve sadakat ödülü birlikte kullanılamaz.
                    </p>
                </div>
            </div>
        </section>
    </div>
</x-layouts.panel>
