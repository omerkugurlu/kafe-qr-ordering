<x-layouts.panel
    title="Canlı pano"
    :subtitle="$branch->cafe->name . ' · ' . $branch->name"
    active="board"
    :branch="$branch"
>
    <div
        class="w-full"
        data-staff-board
        data-endpoint="{{ route('staff.board.data', $branch) }}"
        data-status-url="{{ route('staff.orders.status', ['order' => '__ID__']) }}"
        data-resolve-url="{{ route('staff.service-requests.resolve', ['serviceRequest' => '__ID__']) }}"
        data-claim-url="{{ route('staff.service-requests.claim', ['serviceRequest' => '__ID__']) }}"
        data-release-url="{{ route('staff.service-requests.release', ['serviceRequest' => '__ID__']) }}"
        @if (auth()->user()?->hasRole('waiter', 'cafe_owner', 'cafe_manager'))
            data-open-table-url="{{ route('staff.sessions.open-table', ['branch' => $branch, 'table' => '__ID__']) }}"
        @endif
    >
        <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <nav class="flex flex-wrap items-center gap-2" aria-label="Şube seçimi">
                @foreach ($branches as $branchOption)
                    <a href="{{ route('staff.branch', $branchOption) }}" @class(['chip', 'chip-active' => $branchOption->id === $branch->id])>{{ $branchOption->name }}</a>
                @endforeach
            </nav>
            <div class="flex items-center gap-2">
                <button type="button" class="btn btn-secondary btn-sm" data-sound-toggle aria-pressed="true"><x-ui.icon name="bell" class="size-4" /><span data-sound-label>Ses açık</span></button>
                <button type="button" class="btn btn-primary btn-sm" data-board-refresh><x-ui.icon name="rotate" class="size-4" />Yenile</button>
            </div>
        </header>

        <section class="card p-4" aria-labelledby="staff-requests-title">
            <div class="flex items-center justify-between gap-2">
                <div><h2 id="staff-requests-title" class="font-display text-lg font-semibold text-ink">Bildirimler</h2><p class="text-xs text-muted">Hesap ve garson çağrıları</p></div>
                <span class="badge badge-pending" data-request-count>0</span>
            </div>
            <div class="mt-3 grid gap-2 sm:grid-cols-2" data-requests>
                <p class="rounded-2xl border border-dashed border-line px-4 py-4 text-center text-sm text-muted">Açık çağrı yok.</p>
            </div>
        </section>

        <section class="mt-4" aria-labelledby="staff-orders-title">
            <div class="mb-2 flex items-end justify-between gap-2">
                <div>
                    <h2 id="staff-orders-title" class="font-display text-xl font-semibold text-ink">Siparişler</h2>
                    <p class="text-xs text-muted">Hazır siparişleri teslim edildi olarak işaretleyin.</p>
                </div>
            </div>
            <div class="grid gap-3 lg:grid-cols-3">
                @foreach ([
                    ['key' => 'pending', 'title' => 'Gelen siparişler', 'dot' => 'bg-amber'],
                    ['key' => 'accepted', 'title' => 'Hazırlanıyor', 'dot' => 'bg-terracotta'],
                    ['key' => 'ready', 'title' => 'Hazır · teslim edilecek', 'dot' => 'bg-success'],
                ] as $lane)
                    <section class="card-flat flex min-h-40 flex-col overflow-hidden">
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
                <p class="lg:col-span-3 text-center text-[11px] font-medium text-muted">Son güncelleme: <span data-updated>şimdi</span> · Her 5 saniyede yenilenir</p>
            </div>
        </section>

        <section class="mt-4" aria-labelledby="staff-tables-title">
            <div class="mb-2 flex items-center justify-between gap-2">
                <div><h2 id="staff-tables-title" class="font-display text-xl font-semibold text-ink">Masalar</h2><p class="text-xs text-muted">Adisyon durumu ve ödeme</p></div>
                <div class="flex items-center gap-3">
                    @if (in_array(auth()->user()?->role, [\App\Models\User::ROLE_CAFE_OWNER, \App\Models\User::ROLE_CAFE_MANAGER], true))
                        <a href="{{ route('manage.tables.index', $branch) }}" class="text-xs font-bold text-forest">QR yönet</a>
                    @endif
                    <span class="text-[11px] text-muted">Güncel: <span data-updated>şimdi</span></span>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3" data-tables></div>
        </section>

        <noscript><p class="mt-4 rounded-2xl bg-amber-soft px-4 py-3 text-sm font-semibold text-ink">Canlı pano için JavaScript gerekir.</p></noscript>
    </div>

    <div data-cancel-dialog hidden class="fixed inset-0 z-[80] grid place-items-center p-4">
        <div class="absolute inset-0 bg-ink/50 backdrop-blur-sm" data-cancel-close></div>
        <form class="relative w-full max-w-md rounded-3xl border border-line bg-paper p-6 shadow-lift" data-cancel-form>
            <h2 class="font-display text-2xl font-semibold text-ink">Siparişi iptal et</h2>
            <p class="mt-1 text-sm text-muted">Müşteriye bilgi vermek için kısa bir gerekçe yazın.</p>
            <label class="label mt-4" for="cancel-reason">İptal gerekçesi</label>
            <textarea id="cancel-reason" name="reason" rows="3" minlength="3" maxlength="160" required class="input" placeholder="Örn. ürün tükendi"></textarea>
            <div class="mt-5 flex justify-end gap-2"><button type="button" class="btn btn-ghost" data-cancel-close>Vazgeç</button><button type="submit" class="btn btn-danger">Siparişi iptal et</button></div>
        </form>
    </div>
</x-layouts.panel>
