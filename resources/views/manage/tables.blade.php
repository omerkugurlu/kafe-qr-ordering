<x-layouts.panel
    :title="$branch->name . ' · Masalar'"
    subtitle="Her masa için ayrı QR kod ve menü bağlantısı"
    active="tables"
    :branch="$branch"
>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('manage.branches.show', $branch) }}" class="chip">
                <x-ui.icon name="utensils" class="size-3.5" /> Menü
            </a>
            <span class="chip chip-active"><x-ui.icon name="qr" class="size-3.5" /> Masalar & QR</span>
        </div>
        <a href="{{ route('manage.orders.index') }}" class="btn btn-secondary btn-sm">
            <x-ui.icon name="panel" class="size-4" /> Canlı siparişler
        </a>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-3">
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Toplam masa</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $tables->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">QR kodu üretilmiş</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Müşteriye açık</p>
            <p class="mt-2 font-display text-3xl font-semibold text-success">{{ $tables->where('is_active', true)->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">QR ile erişilebilir</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Pasif masalar</p>
            <p class="mt-2 font-display text-3xl font-semibold text-terracotta">{{ $tables->where('is_active', false)->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Menü erişimi kapalı</p>
        </article>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.3fr)_minmax(0,.7fr)] xl:items-start">
        <section class="grid gap-4 sm:grid-cols-2">
            @forelse ($tables as $table)
                @php($menuUrl = route('customer.menu', $table->token()))
                <article class="card overflow-hidden">
                    <div class="flex items-start gap-4 p-4">
                        <img
                            src="{{ route('manage.tables.qr', [$branch, $table]) }}"
                            alt="{{ $table->name }} QR kodu"
                            width="112"
                            height="112"
                            class="size-28 shrink-0 rounded-2xl border border-line bg-white p-2"
                        >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-display text-xl font-semibold text-ink">{{ $table->name }}</h2>
                                <span class="badge {{ $table->is_active ? 'badge-ready' : 'badge-neutral' }}">{{ $table->is_active ? 'Aktif' : 'Kapalı' }}</span>
                            </div>
                            <p class="mt-1 text-[11px] leading-4 text-muted">Müşteri QR okutunca bu masanın menüsü açılır.</p>
                            <p class="mt-2 truncate rounded-lg bg-cream px-2 py-1.5 font-mono text-[10px] leading-4 text-ink-soft" title="{{ $menuUrl }}">{{ $menuUrl }}</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2 border-t border-line px-4 py-3">
                        <a href="{{ route('manage.tables.print', [$branch, $table]) }}" class="btn btn-secondary btn-sm w-full">
                            <x-ui.icon name="printer" class="size-3.5" /> Yazdır
                        </a>
                        <a target="_blank" rel="noopener" href="{{ $menuUrl }}" class="btn btn-primary btn-sm w-full">
                            <x-ui.icon name="external" class="size-3.5" /> Menü
                        </a>
                        <button type="button" data-copy="{{ $menuUrl }}" class="btn btn-secondary btn-sm w-full whitespace-nowrap">
                            <x-ui.icon name="list" class="size-3.5" /> Kopyala
                        </button>
                        <form method="post" action="{{ route('manage.tables.rotate', [$branch, $table]) }}" data-confirm="{{ $table->name }} için yeni QR üretilsin mi? Eski etiket çalışmayı durdurur.">
                            @csrf
                            <button class="btn btn-secondary btn-sm w-full">
                                <x-ui.icon name="rotate" class="size-3.5" /> QR yenile
                            </button>
                        </form>
                    </div>

                    <form method="post" action="{{ route('manage.tables.update', [$branch, $table]) }}" class="flex items-center justify-between gap-2 border-t border-line bg-cream/40 px-4 py-2.5">
                        @csrf @method('PATCH')
                        <input type="hidden" name="is_active" value="{{ $table->is_active ? 0 : 1 }}">
                        <p class="text-[11px] font-medium text-muted">{{ $table->is_active ? 'Müşteri siparişine açık' : 'Müşteri erişimine kapalı' }}</p>
                        <button class="text-[11px] font-bold {{ $table->is_active ? 'text-danger' : 'text-forest' }} hover:underline">
                            {{ $table->is_active ? 'Kapat' : 'Etkinleştir' }}
                        </button>
                    </form>
                </article>
            @empty
                <div class="card p-10 text-center sm:col-span-2">
                    <span class="mx-auto grid size-14 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="table" class="size-6" /></span>
                    <h2 class="mt-4 font-display text-xl font-semibold text-ink">Henüz masa yok</h2>
                    <p class="mx-auto mt-1 max-w-md text-sm leading-6 text-muted">İlk masayı sağdaki formdan oluşturun. QR kodu otomatik üretilir ve yazdırılabilir.</p>
                </div>
            @endforelse
        </section>

        <aside class="space-y-4 xl:sticky xl:top-24 xl:self-start">
            <section class="card p-5">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-xl font-semibold text-ink">Masa ekle</h2>
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted">QR otomatik üretilir</p>
                    </div>
                    <span class="grid size-10 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="plus" class="size-5" /></span>
                </div>
                <form method="post" action="{{ route('manage.tables.store', $branch) }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="block">
                        <span class="label">Masa adı</span>
                        <input name="name" required maxlength="80" class="input" placeholder="Örn. Masa 01">
                    </label>
                    <button class="btn btn-primary w-full">
                        <x-ui.icon name="qr" class="size-4" /> Masa ve QR oluştur
                    </button>
                </form>
                <p class="mt-3 rounded-xl bg-cream px-3 py-2 text-[11px] leading-5 text-muted">
                    QR anahtarı tahmin edilemeyecek şekilde üretilir. Yenilediğinizde masadaki eski etiketi de değiştirin.
                </p>
            </section>

            <section class="card p-4">
                <div class="flex gap-3">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-amber-soft text-[#8a5a14]"><x-ui.icon name="printer" class="size-4" /></span>
                    <div class="text-xs leading-5 text-muted">
                        <p class="font-bold text-ink">Yazdırma ipucu</p>
                        <p class="mt-0.5">QR sayfası yazdırma için optimize edilmiştir. Etiketi laminasyonla kaplayarak masa üstünde uzun süre kullanabilirsiniz.</p>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</x-layouts.panel>
