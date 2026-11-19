<x-layouts.panel title="Paket yönetimi" subtitle="Paketleri, talepleri ve kafe aboneliklerini yönet" active="subscriptions">
    <div class="space-y-6">
        <section>
            <div class="mb-3">
                <p class="eyebrow">Yıllık planlar</p>
                <h1 class="mt-1 font-display text-2xl font-semibold">Paketleri düzenle</h1>
                <p class="mt-1 text-sm text-muted">Fiyatlar ve özellikler yeni aboneliklerde kullanılır. Daha önce etkinleşen paketlerin kayıtları korunur.</p>
            </div>
            <div class="grid gap-4 xl:grid-cols-3">
                @foreach ($plans as $plan)
                    <form method="post" action="{{ route('platform.subscriptions.plans.update', $plan) }}" class="card space-y-3 p-5">
                        @csrf @method('PATCH')
                        <div class="flex items-center justify-between gap-3">
                            <h2 class="font-display text-xl font-semibold">{{ $plan->name }}</h2>
                            <span class="badge {{ $plan->is_active ? 'badge-ready' : 'badge-cancelled' }}">{{ $plan->is_active ? 'Satışta' : 'Kapalı' }}</span>
                        </div>
                        <label class="block"><span class="label">Paket adı</span><input class="input" name="name" value="{{ $plan->name }}" required maxlength="80"></label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="block"><span class="label">Yıllık fiyat (₺)</span><input class="input" name="annual_price_tl" type="number" min="0" max="1000000" step="0.01" value="{{ number_format($plan->annual_price_cents / 100, 2, '.', '') }}" required></label>
                            <label class="block"><span class="label">Masa sınırı</span><input class="input" name="max_tables" type="number" min="1" max="5000" value="{{ $plan->max_tables }}" required></label>
                        </div>
                        <label class="block"><span class="label">Şube sınırı (boş = sınırsız)</span><input class="input" name="max_branches" type="number" min="1" max="500" value="{{ $plan->max_branches }}"></label>
                        <fieldset class="space-y-2 border-t border-line pt-3">
                            <legend class="label">Pakete dahil özellikler</legend>
                            @foreach ($features as $key => $label)
                                <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" name="features[{{ $key }}]" value="1" @checked(($plan->features_json[$key] ?? false)) class="size-4 accent-forest">{{ $label }}</label>
                            @endforeach
                        </fieldset>
                        <input type="hidden" name="is_active" value="0">
                        <label class="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" name="is_active" value="1" @checked($plan->is_active) class="size-4 accent-forest">Satışa açık</label>
                        <button class="btn btn-primary w-full">Paketi kaydet</button>
                    </form>
                @endforeach
            </div>
        </section>

        <section class="card overflow-hidden">
            <header class="border-b border-line px-5 py-4">
                <p class="eyebrow">Manuel ödeme</p>
                <h2 class="mt-1 font-display text-xl font-semibold">Bekleyen paket talepleri</h2>
            </header>
            <div class="divide-y divide-line">
                @forelse ($requests as $item)
                    <article class="grid gap-4 p-5 xl:grid-cols-[1fr_auto] xl:items-start">
                        <div>
                            <h3 class="font-semibold">{{ $item->cafe->name }} · {{ $item->plan_name_snapshot }}</h3>
                            <p class="mt-1 text-sm text-muted">{{ number_format($item->annual_price_snapshot_cents / 100, 2, ',', '.') }} ₺ / yıl · {{ $item->max_tables_snapshot }} masa · {{ $item->max_branches_snapshot ?? 'Sınırsız' }} şube</p>
                            <p class="mt-1 text-xs text-muted">Talep: {{ $item->requested_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }} · {{ $item->requester?->name }} ({{ $item->requester?->email }})</p>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <form method="post" action="{{ route('platform.subscriptions.requests.approve', $item) }}" class="space-y-2">
                                @csrf
                                <label class="flex items-center gap-2 text-xs font-semibold"><input type="checkbox" name="payment_confirmed" value="1" required class="size-4 accent-forest">Ödeme alındı</label>
                                <button class="btn btn-primary btn-sm w-full">Ödemeyi onayla ve etkinleştir</button>
                            </form>
                            <form method="post" action="{{ route('platform.subscriptions.requests.reject', $item) }}" class="space-y-2">
                                @csrf
                                <input class="input" name="review_note" minlength="3" maxlength="250" required placeholder="Ret açıklaması">
                                <button class="btn btn-secondary btn-sm w-full">Talebi reddet</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <p class="p-5 text-sm text-muted">Bekleyen paket talebi yok.</p>
                @endforelse
            </div>
        </section>

        <section>
            <div class="mb-3">
                <p class="eyebrow">Abonelik denetimi</p>
                <h2 class="mt-1 font-display text-2xl font-semibold">Kafe paketleri ve süreleri</h2>
                <p class="mt-1 text-sm text-muted">Kafe paketini, masa ve şube sınırlarını, özellikleri ve bitiş tarihini buradan elle düzenleyebilirsiniz.</p>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @forelse ($cafes as $record)
                    @php($cafe = $record['cafe'])
                    @php($current = $record['current'])
                    @php($pending = $record['pending'])
                    <button
                        type="button"
                        class="card group flex min-h-36 w-full flex-col justify-between gap-4 p-4 text-left transition duration-200 hover:-translate-y-0.5 hover:border-forest/30 hover:shadow-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-forest sm:p-5"
                        data-cafe-dialog-open="cafe-subscription-{{ $cafe->id }}"
                        aria-haspopup="dialog"
                        aria-controls="cafe-subscription-{{ $cafe->id }}"
                    >
                        <span class="flex w-full items-start justify-between gap-3">
                            <span class="min-w-0">
                                <span class="block truncate font-display text-lg font-semibold">{{ $cafe->name }}</span>
                                <span class="mt-1 block truncate text-sm text-muted">{{ $current?->plan_name_snapshot ?? 'Paket atanmamış' }}</span>
                            </span>
                            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-cream text-forest transition group-hover:bg-forest group-hover:text-white" aria-hidden="true">
                                <x-ui.icon name="arrow-right" class="size-4" />
                            </span>
                        </span>
                        <span class="flex w-full flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted">
                            <span>{{ $record['branches'] }} şube</span>
                            <span aria-hidden="true">·</span>
                            <span>{{ $record['tables'] }} masa</span>
                            @if($current)
                                <span aria-hidden="true">·</span>
                                <span>{{ max(0, today()->diffInDays($current->ends_at->timezone('Europe/Istanbul')->startOfDay(), false)) }} gün kaldı</span>
                            @endif
                        </span>
                        @if($pending)
                            <span class="badge-pending self-start">Bekleyen talep: {{ $pending->plan_name_snapshot }}</span>
                        @endif
                        <span class="sr-only">Paket ve abonelik ayrıntılarını düzenle</span>
                    </button>

                    <dialog id="cafe-subscription-{{ $cafe->id }}" data-cafe-subscription-dialog aria-labelledby="cafe-subscription-title-{{ $cafe->id }}" class="m-auto max-h-[min(90dvh,56rem)] w-[min(58rem,calc(100%-2rem))] max-w-none overflow-y-auto rounded-3xl border border-line bg-paper p-0 text-ink shadow-lift backdrop:bg-ink/50 backdrop:backdrop-blur-sm">
                        <header class="sticky top-0 z-10 flex items-start justify-between gap-4 border-b border-line bg-paper/95 px-5 py-4 backdrop-blur sm:px-7">
                            <div class="min-w-0">
                                <p class="eyebrow">Kafe aboneliği</p>
                                <h3 id="cafe-subscription-title-{{ $cafe->id }}" class="mt-1 truncate font-display text-xl font-semibold">{{ $cafe->name }}</h3>
                                <p class="mt-1 text-xs text-muted">{{ $record['branches'] }} şube · {{ $record['tables'] }} masa @if($record['owner']) · {{ $record['owner']->email }} @endif @if($current) · {{ $current->ends_at->timezone('Europe/Istanbul')->format('d.m.Y') }} bitiş @endif</p>
                            </div>
                            <button type="button" class="btn-icon shrink-0" data-cafe-dialog-close aria-label="Düzenleme panelini kapat">
                                <x-ui.icon name="x" class="size-5" />
                            </button>
                        </header>
                        <div class="p-5 sm:p-7">
                            @if($pending)<p class="badge-pending mb-4">Bekleyen talep: {{ $pending->plan_name_snapshot }}</p>@endif
                            <form method="post" action="{{ route('platform.subscriptions.cafes.assign', $cafe) }}" class="grid w-full gap-3 md:grid-cols-2 xl:w-full xl:grid-cols-4">
                                @csrf
                                <label><span class="label">Paket adı</span><select class="input" name="plan_id" required>@foreach($plans as $plan)<option value="{{ $plan->id }}" @selected(($current?->subscription_plan_id ?? $pending?->subscription_plan_id) === $plan->id)>{{ $plan->name }}</option>@endforeach</select></label>
                                <label><span class="label">Yıllık fiyat (₺)</span><input class="input" type="number" name="annual_price_tl" min="0" max="1000000" step="0.01" value="{{ number_format(($current?->annual_price_snapshot_cents ?? $pending?->annual_price_snapshot_cents ?? 0) / 100, 2, '.', '') }}" required></label>
                                <label><span class="label">Masa sınırı</span><input class="input" type="number" name="max_tables" min="1" max="5000" value="{{ $current?->max_tables_snapshot ?? $pending?->max_tables_snapshot ?? 10 }}" required></label>
                                <label><span class="label">Şube sınırı (boş = sınırsız)</span><input class="input" type="number" name="max_branches" min="1" max="500" value="{{ $current?->max_branches_snapshot ?? $pending?->max_branches_snapshot }}"></label>
                                <label><span class="label">Bitiş tarihi</span><input class="input" type="date" name="ends_on" value="{{ $current?->ends_at?->timezone('Europe/Istanbul')->toDateString() ?? today()->addYear()->toDateString() }}" required></label>
                                <fieldset class="space-y-2 rounded-xl border border-line p-3 md:col-span-2 xl:col-span-4">
                                    <legend class="label">Pakete dahil özellikler</legend>
                                    <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                                        @foreach($features as $key => $label)
                                            <label class="flex items-center gap-2 text-sm text-ink"><input type="checkbox" name="features[{{ $key }}]" value="1" @checked($current?->features_snapshot_json[$key] ?? $pending?->features_snapshot_json[$key] ?? false) class="size-4 accent-forest">{{ $label }}</label>
                                        @endforeach
                                    </div>
                                </fieldset>
                                <label><span class="label">İşlem notu</span><input class="input" name="reason" minlength="3" maxlength="250" placeholder="Ödeme teyidi" required></label>
                                <button class="btn btn-primary self-end">Paketi kaydet</button>
                            </form>
                            @if($current)
                                <form method="post" action="{{ route('platform.subscriptions.cafes.cancel', $cafe) }}" class="mt-5 flex flex-wrap gap-2 border-t border-line pt-5" onsubmit="return confirm('Bu kafenin etkin paketi iptal edilsin mi?')">
                                    @csrf @method('DELETE')
                                    <input class="input max-w-sm" name="reason" minlength="3" maxlength="250" placeholder="İptal nedeni" required>
                                    <button class="btn btn-secondary">Paketi iptal et</button>
                                </form>
                            @endif
                        </div>
                    </dialog>
                @empty
                    <div class="card p-5 text-sm text-muted">Henüz kafe kaydı yok.</div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.panel>
