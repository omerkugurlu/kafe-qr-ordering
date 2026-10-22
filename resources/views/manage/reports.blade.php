<x-layouts.panel
    title="Raporlar"
    :subtitle="$cafe->name . ' · satış ve tahsilat analizi'"
    active="reports"
>
    <div class="flex flex-wrap items-end justify-between gap-3">
        <form method="get" action="{{ route('manage.reports.index') }}" class="flex flex-wrap items-end gap-2">
            <label class="block">
                <span class="label">Başlangıç</span>
                <input type="date" name="baslangic" value="{{ $from->format('Y-m-d') }}" class="input py-2 text-sm">
            </label>
            <label class="block">
                <span class="label">Bitiş</span>
                <input type="date" name="bitis" value="{{ $to->format('Y-m-d') }}" class="input py-2 text-sm">
            </label>
            <label class="block">
                <span class="label">Şube</span>
                <select name="sube" class="input py-2 text-sm">
                    <option value="">Tüm şubeler</option>
                    @foreach ($branches as $branchOption)
                        <option value="{{ $branchOption->id }}" @selected($branch?->id === $branchOption->id)>{{ $branchOption->name }}</option>
                    @endforeach
                </select>
            </label>
            <button class="btn btn-secondary btn-sm">
                <x-ui.icon name="filter" class="size-4" /> Uygula
            </button>
        </form>

        <a href="{{ route('manage.reports.export', array_filter(['baslangic' => $from->format('Y-m-d'), 'bitis' => $to->format('Y-m-d'), 'sube' => $branch?->id])) }}" class="btn btn-primary btn-sm">
            <x-ui.icon name="download" class="size-4" /> CSV indir
        </a>
    </div>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        @foreach ([['Son 7 gün', 6], ['Son 30 gün', 29], ['Son 90 gün', 89]] as [$label, $days])
            <a href="{{ route('manage.reports.index', array_filter(['baslangic' => today()->subDays($days)->format('Y-m-d'), 'bitis' => today()->format('Y-m-d'), 'sube' => $branch?->id])) }}" class="chip">
                {{ $label }}
            </a>
        @endforeach
        <span class="badge badge-neutral">{{ $from->format('d.m.Y') }} – {{ $to->format('d.m.Y') }} · {{ $report['days'] }} gün</span>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Tahsilat</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ \App\Services\Money::format($report['revenue_cents']) }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Nakit + kart, iptaller hariç</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Sipariş</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $report['order_count'] }}</p>
            <p class="mt-1 text-xs font-medium text-muted">{{ $report['cancelled_count'] }} iptal</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Ortalama sepet</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ \App\Services\Money::format($report['average_cents']) }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Sipariş başına</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">İndirim</p>
            <p class="mt-2 font-display text-3xl font-semibold text-terracotta">{{ \App\Services\Money::format($report['discount_cents']) }}</p>
            <p class="mt-1 text-xs font-medium text-muted">{{ $report['coupon_orders'] }} kuponlu sipariş</p>
        </article>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,.6fr)]">
        <section class="card min-w-0 p-5">
            <div class="flex items-center justify-between gap-2">
                <div>
                    <h2 class="font-display text-xl font-semibold text-ink">Günlük tahsilat</h2>
                    <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Seçilen dönem</p>
                </div>
                <span class="badge badge-neutral">Maks. {{ \App\Services\Money::format($report['max_daily']) }}</span>
            </div>

            <div class="mt-5 flex h-52 items-end gap-1 overflow-x-auto pb-1">
                @php($labelStep = max(1, (int) ceil(count($report['daily']) / 7)))
                @foreach ($report['daily'] as $day)
                    <div class="group flex h-full min-w-[10px] flex-1 flex-col items-center justify-end gap-1.5" title="{{ $day['label'] }} · {{ \App\Services\Money::format($day['cents']) }} · {{ $day['orders'] }} sipariş">
                        <div class="w-full rounded-t-md bg-forest/80 transition group-hover:bg-forest" style="height: {{ max(2, (int) round(($day['cents'] / $report['max_daily']) * 100)) }}%"></div>
                        @if ($loop->index % $labelStep === 0 || $loop->last)
                            <span class="whitespace-nowrap text-[10px] font-semibold text-muted">{{ $day['label'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <section class="card min-w-0 p-5">
            <h2 class="font-display text-xl font-semibold text-ink">Ödeme dağılımı</h2>
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Tahsilat yöntemi</p>

            <div class="mt-4 space-y-3">
                @forelse ($report['payments'] as $payment)
                    <div class="rounded-2xl border border-line bg-cream/40 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="flex items-center gap-2 text-sm font-bold text-ink">
                                <x-ui.icon :name="$payment['method'] === 'cash' ? 'banknote' : 'card'" class="size-4 text-muted" />
                                {{ $payment['label'] }}
                            </span>
                            <span class="font-display text-lg font-semibold text-ink">{{ \App\Services\Money::format($payment['cents']) }}</span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-line">
                            <div class="h-full rounded-full {{ $payment['method'] === 'cash' ? 'bg-forest' : 'bg-amber' }}" style="width: {{ max(3, (int) round(($payment['cents'] / max(1, $report['revenue_cents'])) * 100)) }}%"></div>
                        </div>
                        <p class="mt-1 text-[11px] font-medium text-muted">{{ $payment['count'] }} ödeme kaydı</p>
                    </div>
                @empty
                    <p class="rounded-2xl border border-dashed border-line px-4 py-8 text-center text-sm font-medium text-muted">Bu dönemde tahsilat yok.</p>
                @endforelse
            </div>

            <div class="mt-4 rounded-2xl bg-forest p-4 text-white">
                <p class="text-[10px] font-bold uppercase tracking-[.18em] text-white/60">Sipariş toplamı</p>
                <p class="mt-1 font-display text-2xl font-semibold">{{ \App\Services\Money::format($report['orders_total_cents']) }}</p>
                <p class="mt-1 text-[11px] text-white/60">Tahsilatla fark: {{ \App\Services\Money::format($report['orders_total_cents'] - $report['revenue_cents']) }}</p>
            </div>
        </section>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-2">
        <section class="card overflow-hidden">
            <div class="border-b border-line px-5 py-4">
                <h2 class="font-display text-xl font-semibold text-ink">En çok gelir getiren ürünler</h2>
                <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">İlk 10 ürün</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[32rem] text-left text-sm">
                    <thead>
                        <tr class="border-b border-line bg-cream/60 text-[10px] font-bold uppercase tracking-[.14em] text-muted">
                            <th class="px-5 py-3">Ürün</th>
                            <th class="px-3 py-3 text-right">Adet</th>
                            <th class="px-5 py-3 text-right">Gelir</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @forelse ($report['top_products'] as $product)
                            <tr class="transition hover:bg-cream/40">
                                <td class="px-5 py-3 font-semibold text-ink">{{ $product['name'] }}</td>
                                <td class="px-3 py-3 text-right font-bold text-ink-soft">{{ $product['quantity'] }}</td>
                                <td class="px-5 py-3 text-right font-bold text-ink">{{ \App\Services\Money::format($product['cents']) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-5 py-10 text-center text-sm font-medium text-muted">Satış verisi yok.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card p-5">
            <h2 class="font-display text-xl font-semibold text-ink">Operasyon özeti</h2>
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Dönem karşılaştırması</p>

            <div class="mt-4 space-y-2">
                @foreach ([
                    ['Kuponlu sipariş', $report['coupon_orders'].' / '.max(1, $report['order_count'])],
                    ['İptal oranı', $report['order_count'] > 0 ? number_format(($report['cancelled_count'] / max(1, $report['order_count'] + $report['cancelled_count'])) * 100, 1, ',', '').'%' : '0%'],
                    ['Günlük ortalama ciro', \App\Services\Money::format(intdiv($report['revenue_cents'], max(1, $report['days'])))],
                    ['Günlük ortalama sipariş', number_format($report['order_count'] / max(1, $report['days']), 1, ',', '')],
                ] as [$label, $value])
                    <div class="flex items-center justify-between gap-3 rounded-2xl border border-line px-4 py-3">
                        <span class="text-sm font-semibold text-muted">{{ $label }}</span>
                        <span class="text-sm font-bold text-ink">{{ $value }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 rounded-2xl bg-amber-soft p-4">
                <div class="flex gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-paper text-[#8a5a14]"><x-ui.icon name="info" class="size-4" /></span>
                    <p class="text-xs leading-5 text-[#7a4f12]">
                        Tahsilat, adisyonda kaydedilen ödemeleri gösterir. Sipariş toplamı ile fark, açık adisyonlardan kaynaklanabilir.
                    </p>
                </div>
            </div>
        </section>
    </div>
</x-layouts.panel>
