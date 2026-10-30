<x-layouts.panel title="Paket ve abonelik" subtitle="Kafe planınızı ve kullanım sınırlarınızı görüntüleyin" active="subscription">
    <div class="space-y-5">
        @if ($errors->any())
            <div role="alert" class="rounded-2xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm font-semibold text-danger">{{ $errors->first() }}</div>
        @endif
        @if (session('status'))
            <x-ui.flash>{{ session('status') }}</x-ui.flash>
        @endif
        <section class="card p-5 sm:p-6">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="eyebrow">Mevcut paket</p>
                    <h1 class="mt-1 font-display text-2xl font-semibold">{{ $current?->plan_name_snapshot ?? $expired?->plan_name_snapshot ?? 'Henüz paket yok' }}</h1>
                </div>
                <span class="badge {{ $current ? 'badge-ready' : 'badge-cancelled' }}">{{ $current ? max(0, now()->diffInDays($current->ends_at, false)).' gün kaldı' : ($expired ? 'Süresi doldu' : 'Paket bekleniyor') }}</span>
            </div>
            @if ($current)
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl bg-cream p-3 text-sm"><span class="text-muted">Bitiş</span><p class="mt-1 font-semibold">{{ $current->ends_at->timezone('Europe/Istanbul')->format('d.m.Y') }}</p></div>
                    <div class="rounded-xl bg-cream p-3 text-sm"><span class="text-muted">Masa kullanımı</span><p class="mt-1 font-semibold">{{ $tables }} / {{ $current->max_tables_snapshot }}</p></div>
                    <div class="rounded-xl bg-cream p-3 text-sm"><span class="text-muted">Şube kullanımı</span><p class="mt-1 font-semibold">{{ $branches }} / {{ $current->max_branches_snapshot ?? 'Sınırsız' }}</p></div>
                </div>
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach (\App\Services\SubscriptionService::FEATURES as $key => $label)
                        <span class="badge {{ ($current->features_snapshot_json[$key] ?? false) ? 'badge-ready' : 'badge-neutral' }}">{{ $label }}: {{ ($current->features_snapshot_json[$key] ?? false) ? 'Dahil' : 'Dahil değil' }}</span>
                    @endforeach
                </div>
            @elseif ($expired)
                <p class="mt-3 text-sm text-muted">Son paket {{ $expired->ends_at->timezone('Europe/Istanbul')->format('d.m.Y') }} tarihinde sona erdi. Yeni paket talebi gönderebilirsiniz.</p>
            @else
                <p class="mt-3 text-sm text-muted">Bir paket seçip talep gönderin. Süper yönetici ödeme kontrolünden sonra paketi etkinleştirir.</p>
            @endif
        </section>

        @if ($pending)
            <section class="card border border-amber/40 p-5">
                <p class="eyebrow">Bekleyen talep</p>
                <h2 class="mt-1 font-display text-xl font-semibold">{{ $pending->plan_name_snapshot }} · {{ number_format($pending->annual_price_snapshot_cents / 100, 2, ',', '.') }} ₺ / yıl</h2>
                <p class="mt-1 text-sm text-muted">Talebiniz ödeme kontrolü için yöneticiye iletildi. Durum: Yönetici incelemesinde.</p>
            </section>
        @endif

        <section>
            <div class="mb-3"><p class="eyebrow">Yıllık planlar</p><h2 class="mt-1 font-display text-2xl font-semibold">İşletmene uygun planı seç</h2></div>
            <div class="grid gap-4 lg:grid-cols-3">
                @foreach ($plans as $plan)
                    <article class="card flex flex-col p-5">
                        <div class="flex items-center justify-between gap-2"><h3 class="font-display text-xl font-semibold">{{ $plan->name }}</h3><span class="badge badge-neutral">{{ $plan->max_tables }} masa</span></div>
                        <p class="mt-2 font-display text-3xl font-semibold">{{ number_format($plan->annual_price_cents / 100, 0, ',', '.') }} ₺<span class="text-sm font-medium text-muted"> / yıl</span></p>
                        <p class="mt-1 text-sm text-muted">{{ $plan->max_branches ?? 'Sınırsız' }} şube</p>
                        <ul class="mt-4 flex-1 space-y-2 text-sm">
                            @foreach (\App\Services\SubscriptionService::FEATURES as $key => $label)
                                <li class="flex items-center gap-2"><span class="font-bold {{ ($plan->features_json[$key] ?? false) ? 'text-success' : 'text-muted' }}">{{ ($plan->features_json[$key] ?? false) ? '✓' : '—' }}</span><span>{{ $label }}</span></li>
                            @endforeach
                        </ul>
                        <form method="post" action="{{ route('manage.subscription.request') }}" class="mt-5">
                            @csrf<input type="hidden" name="plan_id" value="{{ $plan->id }}">
                            <button class="btn btn-primary w-full" @disabled($pending || $scheduled)>Paket talebi gönder</button>
                        </form>
                    </article>
                @endforeach
            </div>
        </section>

        @if ($requests->isNotEmpty())
            <section class="card overflow-hidden">
                <header class="border-b border-line px-5 py-4"><p class="eyebrow">Geçmiş</p><h2 class="mt-1 font-display text-xl font-semibold">Paket talepleri</h2></header>
                <div class="divide-y divide-line">
                    @foreach ($requests as $item)
                        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <div><p class="font-semibold">{{ $item->plan_name_snapshot }} · {{ number_format($item->annual_price_snapshot_cents / 100, 2, ',', '.') }} ₺ / yıl</p><p class="mt-1 text-xs text-muted">{{ $item->requested_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') }} @if($item->review_note) · {{ $item->review_note }} @endif</p></div>
                            <span class="badge {{ $item->status === 'approved' ? 'badge-ready' : ($item->status === 'pending' ? 'badge-pending' : 'badge-cancelled') }}">{{ ['pending' => 'İncelemede', 'approved' => 'Onaylandı', 'rejected' => 'Reddedildi'][$item->status] ?? $item->status }}</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts.panel>
