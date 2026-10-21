<x-layouts.panel
    title="Canlı pano"
    :subtitle="$branch->cafe->name . ' · ' . $branch->name . ' · 5 saniyede yenilenir'"
    active="board"
    :branch="$branch"
>
    <div
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
        <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-2">
                @foreach ($branches as $branchOption)
                    <a
                        href="{{ route('staff.branch', $branchOption) }}"
                        @class(['chip', 'chip-active' => $branchOption->id === $branch->id])
                    >
                        <x-ui.icon name="pin" class="size-3.5" />
                        {{ $branchOption->name }}
                    </a>
                @endforeach
            </div>
            <div class="flex items-center gap-2">
                <button type="button" class="btn btn-secondary btn-sm" data-sound-toggle aria-pressed="true">
                    <x-ui.icon name="bell" class="size-4" />
                    <span data-sound-label>Ses açık</span>
                </button>
                <a href="{{ route('staff.kitchen', $branch) }}" class="btn btn-secondary btn-sm">
                    <x-ui.icon name="chef" class="size-4" />
                    Mutfak ekranı
                </a>
                <button type="button" class="btn btn-primary btn-sm" data-board-refresh>
                    <x-ui.icon name="rotate" class="size-4" />
                    Yenile
                </button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <article class="kpi">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Bekleyen</p>
                    <span class="grid size-9 place-items-center rounded-full bg-amber-soft text-[#8a5a14]"><x-ui.icon name="bell" class="size-4" /></span>
                </div>
                <p class="mt-3 font-display text-3xl font-semibold text-ink" data-kpi="pending">0</p>
                <p class="mt-1 text-xs font-medium text-muted">Onay bekleyen sipariş</p>
            </article>
            <article class="kpi">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Hazırlanıyor</p>
                    <span class="grid size-9 place-items-center rounded-full bg-terracotta-soft text-[#8f3d22]"><x-ui.icon name="chef" class="size-4" /></span>
                </div>
                <p class="mt-3 font-display text-3xl font-semibold text-ink" data-kpi="accepted">0</p>
                <p class="mt-1 text-xs font-medium text-muted">Mutfakta işlemde</p>
            </article>
            <article class="kpi">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Hazır</p>
                    <span class="grid size-9 place-items-center rounded-full bg-success-soft text-success"><x-ui.icon name="check-circle" class="size-4" /></span>
                </div>
                <p class="mt-3 font-display text-3xl font-semibold text-ink" data-kpi="ready">0</p>
                <p class="mt-1 text-xs font-medium text-muted">Servis bekliyor</p>
            </article>
            <article class="kpi">
                <div class="flex items-center justify-between">
                    <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Açık masa</p>
                    <span class="grid size-9 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="table" class="size-4" /></span>
                </div>
                <p class="mt-3 font-display text-3xl font-semibold text-ink" data-kpi="open_tables">0</p>
                <p class="mt-1 text-xs font-medium text-muted">Adisyonu açık</p>
            </article>
        </div>

        <div class="mt-5 grid gap-5 min-[1400px]:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="grid gap-4 lg:grid-cols-3">
                @foreach ([
                    ['key' => 'pending', 'title' => 'Gelen siparişler', 'hint' => 'Onay bekliyor', 'accent' => 'bg-amber'],
                    ['key' => 'accepted', 'title' => 'Hazırlanıyor', 'hint' => 'Mutfakta', 'accent' => 'bg-terracotta'],
                    ['key' => 'ready', 'title' => 'Hazır', 'hint' => 'Servise çıkacak', 'accent' => 'bg-success'],
                ] as $lane)
                    <section class="card-flat flex min-h-[26rem] flex-col overflow-hidden">
                        <header class="flex items-center justify-between gap-2 border-b border-line px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="size-2 rounded-full {{ $lane['accent'] }}"></span>
                                <h2 class="font-display text-lg font-semibold text-ink">{{ $lane['title'] }}</h2>
                            </div>
                            <span class="badge badge-neutral" data-lane-count="{{ $lane['key'] }}">0</span>
                        </header>
                        <p class="px-4 pt-2 text-[11px] font-semibold uppercase tracking-[.14em] text-muted">{{ $lane['hint'] }}</p>
                        <div class="flex-1 space-y-3 p-3" data-lane-body="{{ $lane['key'] }}"></div>
                    </section>
                @endforeach
            </div>

            <aside class="space-y-5">
                <section class="card p-4">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <h2 class="font-display text-lg font-semibold text-ink">Masa çağrıları</h2>
                            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Garson & hesap</p>
                        </div>
                        <span class="badge badge-pending" data-request-count>0</span>
                    </div>
                    <div class="mt-3 space-y-2" data-requests>
                        <p class="rounded-2xl border border-dashed border-line px-4 py-5 text-center text-xs font-medium text-muted">Açık çağrı yok.</p>
                    </div>
                </section>

                <section class="card p-4">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <h2 class="font-display text-lg font-semibold text-ink">Masalar</h2>
                            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">Adisyon durumu</p>
                        </div>
                        @if (in_array(auth()->user()?->role, [\App\Models\User::ROLE_CAFE_OWNER, \App\Models\User::ROLE_CAFE_MANAGER], true))
                            <a href="{{ route('manage.tables.index', $branch) }}" class="text-xs font-bold text-forest hover:text-ink">QR yönet</a>
                        @endif
                    </div>
                    <div class="mt-3 grid grid-cols-2 gap-2" data-tables></div>
                </section>

                <p class="text-center text-[11px] font-medium text-muted">
                    Son güncelleme: <span data-updated>şimdi</span>
                </p>
            </aside>
        </div>

        <noscript>
            <p class="mt-5 rounded-2xl border border-amber/40 bg-amber-soft px-4 py-3 text-sm font-semibold text-[#8a5a14]">
                Canlı pano için JavaScript gerekir. Siparişleri adisyon üzerinden yönetebilirsiniz.
            </p>
        </noscript>
    </div>

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
