<x-layouts.panel
    title="Siparişler"
    :subtitle="$cafe->name . ' · canlı akış ve geçmiş'"
    active="orders"
    :branch="$branch"
>
    @php($liveBranch = $branch ?? $branches->first())

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('manage.orders.index') }}" @class(['chip', 'chip-active' => $status === null])>Tümü</a>
            @foreach (['pending' => 'Alındı', 'accepted' => 'Hazırlanıyor', 'ready' => 'Hazır', 'served' => 'Teslim', 'paid' => 'Ödendi', 'cancelled' => 'İptal'] as $key => $label)
                <a
                    href="{{ route('manage.orders.index', array_filter(['durum' => $key, 'sube' => $branch?->id, 'q' => $search !== '' ? $search : null])) }}"
                    @class(['chip', 'chip-active' => $status === $key])
                >
                    {{ $label }}
                    <span class="ml-1 text-[10px] opacity-60">{{ (int) ($counts[$key] ?? 0) }}</span>
                </a>
            @endforeach
        </div>

        <form method="get" action="{{ route('manage.orders.index') }}" class="flex flex-wrap items-end gap-2">
            @if ($status !== null)
                <input type="hidden" name="durum" value="{{ $status }}">
            @endif
            <label class="block">
                <span class="sr-only">Şube</span>
                <select name="sube" class="input py-2 text-sm" onchange="this.form.submit()">
                    <option value="">Tüm şubeler</option>
                    @foreach ($branches as $branchOption)
                        <option value="{{ $branchOption->id }}" @selected($branch?->id === $branchOption->id)>{{ $branchOption->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="relative block">
                <span class="sr-only">Sipariş ara</span>
                <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted" />
                <input type="search" name="q" value="{{ $search }}" placeholder="Sipariş no veya masa" class="input py-2 pl-9 text-sm">
            </label>
            <button class="btn btn-secondary btn-sm">Ara</button>
        </form>
    </div>

    @if ($liveBranch !== null)
        <section class="mt-5">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 class="font-display text-xl font-semibold text-ink">Canlı pano · {{ $liveBranch->name }}</h2>
                    <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Bekleyen, hazırlanan ve servise hazır siparişler</p>
                </div>
                <a href="{{ route('staff.kitchen', $liveBranch) }}" class="btn btn-secondary btn-sm">
                    <x-ui.icon name="chef" class="size-4" /> Mutfak ekranı
                </a>
            </div>

            <div
                data-staff-board
                data-endpoint="{{ route('manage.orders.data', $liveBranch) }}"
                data-status-url="{{ route('manage.orders.status', ['order' => '__ID__']) }}"
                data-resolve-url="{{ route('manage.orders.requests.resolve', ['serviceRequest' => '__ID__']) }}"
                class="grid gap-3 lg:grid-cols-3"
            >
                @foreach ([
                    ['key' => 'pending', 'title' => 'Gelen siparişler', 'dot' => 'bg-amber'],
                    ['key' => 'accepted', 'title' => 'Hazırlanıyor', 'dot' => 'bg-terracotta'],
                    ['key' => 'ready', 'title' => 'Hazır', 'dot' => 'bg-success'],
                ] as $lane)
                    <section class="card-flat flex min-h-[18rem] flex-col overflow-hidden">
                        <header class="flex items-center justify-between gap-2 border-b border-line px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="size-2 rounded-full {{ $lane['dot'] }}"></span>
                                <h3 class="font-display text-base font-semibold text-ink">{{ $lane['title'] }}</h3>
                            </div>
                            <span class="badge badge-neutral" data-lane-count="{{ $lane['key'] }}">0</span>
                        </header>
                        <div class="flex-1 space-y-2 p-3" data-lane-body="{{ $lane['key'] }}"></div>
                    </section>
                @endforeach

                <p class="lg:col-span-3 text-center text-[11px] font-medium text-muted">
                    Son güncelleme: <span data-updated>şimdi</span> · Her 5 saniyede yenilenir
                </p>
            </div>
        </section>
    @endif

    <section class="card mt-5 overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-4">
            <div>
                <h2 class="font-display text-xl font-semibold text-ink">Sipariş geçmişi</h2>
                <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">{{ $orders->total() }} kayıt</p>
            </div>
            <a href="{{ route('manage.reports.export', array_filter(['sube' => $branch?->id])) }}" class="btn btn-secondary btn-sm">
                <x-ui.icon name="download" class="size-4" /> CSV indir
            </a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[54rem] text-left text-sm">
                <thead>
                    <tr class="border-b border-line bg-cream/60 text-[10px] font-bold uppercase tracking-[.14em] text-muted">
                        <th class="px-5 py-3">Sipariş</th>
                        <th class="px-3 py-3">Masa</th>
                        <th class="px-3 py-3">Ürünler</th>
                        <th class="px-3 py-3">Tutar</th>
                        <th class="px-3 py-3">Durum</th>
                        <th class="px-5 py-3 text-right">İşlem</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @forelse ($orders as $order)
                        <tr class="align-top transition hover:bg-cream/40">
                            <td class="px-5 py-4">
                                <p class="font-bold text-ink">#{{ $order->order_number }}</p>
                                <p class="mt-0.5 text-[11px] font-medium text-muted">{{ $order->created_at->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}</p>
                                <p class="mt-0.5 text-[11px] font-medium text-muted">{{ $order->source === 'waiter' ? 'Garson' : 'QR' }}</p>
                            </td>
                            <td class="px-3 py-4">
                                <p class="font-semibold text-ink">{{ $order->tableSession?->diningTable?->name ?? '—' }}</p>
                                <p class="mt-0.5 text-[11px] text-muted">{{ $order->branch?->name }}</p>
                            </td>
                            <td class="px-3 py-4">
                                <p class="max-w-[16rem] truncate text-xs font-medium text-ink-soft">
                                    {{ $order->items->map(fn ($item) => $item->quantity.'× '.$item->name_snapshot)->implode(', ') }}
                                </p>
                                @if ($order->customer_note)
                                    <p class="mt-1 max-w-[16rem] truncate text-[11px] font-semibold text-terracotta">Not: {{ $order->customer_note }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-4">
                                <p class="font-bold text-ink">{{ \App\Services\Money::formatDecimal($order->total) }}</p>
                                @if ((float) $order->discount_total > 0)
                                    <p class="mt-0.5 text-[11px] font-semibold text-success">−{{ \App\Services\Money::formatDecimal($order->discount_total) }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-4"><x-ui.status-badge :status="$order->status" /></td>
                            <td class="px-5 py-4 text-right">
                                <a href="{{ route('staff.sessions.show', $order->table_session_id) }}" class="text-xs font-bold text-forest hover:text-ink">Adisyon</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-5 py-12 text-center text-sm font-medium text-muted">Bu filtrede sipariş bulunamadı.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($orders->hasPages())
            <div class="border-t border-line px-5 py-4">{{ $orders->links() }}</div>
        @endif
    </section>

    <div data-cancel-dialog hidden class="fixed inset-0 z-[80] grid place-items-center p-4">
        <div class="absolute inset-0 bg-ink/50 backdrop-blur-sm" data-cancel-close></div>
        <form class="relative w-full max-w-md rounded-3xl border border-line bg-paper p-6 shadow-lift" data-cancel-form>
            <h2 class="font-display text-2xl font-semibold text-ink">Siparişi iptal et</h2>
            <p class="mt-1 text-sm text-muted">Müşteriye bilgi vermek için kısa bir gerekçe yazın.</p>
            <label class="label mt-4" for="cancel-reason">İptal gerekçesi</label>
            <textarea id="cancel-reason" name="reason" rows="3" minlength="3" maxlength="160" required class="input" placeholder="Örn. ürün tükendi"></textarea>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="btn btn-ghost" data-cancel-close>Vazgeç</button>
                <button type="submit" class="btn btn-danger">Siparişi iptal et</button>
            </div>
        </form>
    </div>
</x-layouts.panel>
