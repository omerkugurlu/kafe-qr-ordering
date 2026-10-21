<x-layouts.panel
    :title="$session->diningTable->name . ' · Adisyon'"
    :subtitle="$session->branch->cafe->name . ' · ' . $session->branch->name"
    active="board"
    :branch="$session->branch"
>
    @php
        $paidCents = (int) round(((float) $session->payments->whereNull('voided_at')->sum('amount')) * 100);
        $totalCents = (int) round(((float) $session->orders->whereNotIn('status', ['cancelled'])->sum('total')) * 100);
        $dueCents = $session->dueAmountCents();
        $dueLabel = $dueCents > 0 ? 'Tahsilat bekliyor' : ($totalCents === 0 ? 'Henüz sipariş yok' : 'Hesap kapandı');
    @endphp

    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <a href="{{ auth()->user()?->hasRole(\App\Models\User::ROLE_CAFE_OWNER, \App\Models\User::ROLE_CAFE_MANAGER) ? route('manage.orders.index', ['sube' => $session->branch_id]) : route('staff.branch', $session->branch_id) }}" class="inline-flex min-h-10 items-center gap-2 text-sm font-bold text-forest transition hover:text-ink">
            <x-ui.icon name="arrow-left" class="size-4" />
            Canlı panoya dön
        </a>
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.status-badge :status="$session->status === 'open' ? 'served' : 'paid'" :label="$session->status === 'open' ? 'Açık adisyon' : 'Kapandı'" />
            <span class="badge badge-neutral">
                <x-ui.icon name="clock" class="size-3" />
                {{ $session->opened_at->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}
            </span>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-3">
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Adisyon toplamı</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">₺{{ number_format($totalCents / 100, 2, ',', '.') }}</p>
            <p class="mt-1 text-xs font-medium text-muted">{{ $session->orders->whereNotIn('status', ['cancelled'])->count() }} sipariş</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Tahsil edilen</p>
            <p class="mt-2 font-display text-3xl font-semibold text-success">₺{{ number_format($paidCents / 100, 2, ',', '.') }}</p>
            <p class="mt-1 text-xs font-medium text-muted">{{ $session->payments->whereNull('voided_at')->count() }} ödeme kaydı</p>
        </article>
        <article class="kpi {{ $dueCents > 0 ? 'bg-forest text-white' : 'bg-success-soft' }}">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] {{ $dueCents > 0 ? 'text-white/60' : 'text-success' }}">Kalan tutar</p>
            <p class="mt-2 font-display text-3xl font-semibold {{ $dueCents > 0 ? 'text-white' : 'text-success' }}">₺{{ number_format($dueCents / 100, 2, ',', '.') }}</p>
            <p class="mt-1 text-xs font-medium {{ $dueCents > 0 ? 'text-white/60' : 'text-success/80' }}">{{ $dueLabel }}</p>
        </article>
    </div>

    @if ($errors->any())
        <div role="alert" class="mt-5 rounded-2xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm font-semibold text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,.9fr)]">
        <div class="space-y-5">
            <section class="card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-2xl font-semibold text-ink">Adisyon kalemleri</h2>
                        <p class="mt-0.5 text-xs font-medium text-muted">Siparişler, seçenekler ve ödeme durumu</p>
                    </div>
                    <span class="badge badge-neutral">{{ $session->orders->count() }} sipariş</span>
                </div>

                <div class="mt-4 space-y-4">
                    @forelse ($session->orders as $order)
                        <article class="rounded-2xl border border-line bg-cream/40 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-[.12em] text-muted">
                                        #{{ $order->order_number }} · {{ $order->source === 'waiter' ? 'Garson' : 'QR' }}
                                    </p>
                                    <p class="mt-0.5 text-xs font-medium text-muted">{{ $order->created_at->timezone('Europe/Istanbul')->format('H:i') }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <x-ui.status-badge :status="$order->status" />
                                    <span class="font-display text-lg font-semibold text-ink">₺{{ number_format((float) $order->total, 2, ',', '.') }}</span>
                                </div>
                            </div>

                            <div class="mt-3 space-y-2 border-t border-line pt-3">
                                @foreach ($order->items as $item)
                                    <div>
                                        <div class="flex items-start justify-between gap-3 text-sm">
                                            <span class="font-semibold text-ink"><span class="mr-1 text-muted">{{ $item->quantity }}×</span>{{ $item->name_snapshot }}</span>
                                            <span class="shrink-0 font-semibold text-ink-soft">₺{{ number_format((float) $item->line_total, 2, ',', '.') }}</span>
                                        </div>
                                        @if ($item->options->isNotEmpty())
                                            <p class="mt-0.5 pl-5 text-[11px] leading-5 text-muted">
                                                {{ $item->options->map(fn ($option) => $option->name_snapshot.((float) $option->price_delta > 0 ? ' (+₺'.number_format((float) $option->price_delta, 2, ',', '.').')' : ' (ücretsiz)'))->implode(' · ') }}
                                            </p>
                                        @endif
                                        @if ($item->customer_note)
                                            <p class="mt-0.5 pl-5 text-[11px] font-semibold text-terracotta">Not: {{ $item->customer_note }}</p>
                                        @endif
                                    </div>
                                @endforeach
                            </div>

                            @if ((float) $order->discount_total > 0)
                                <p class="mt-2 text-right text-xs font-semibold text-success">İndirim · −₺{{ number_format((float) $order->discount_total, 2, ',', '.') }}</p>
                            @endif
                            @if ($order->customer_note)
                                <p class="mt-2 rounded-xl bg-amber-soft px-3 py-2 text-xs font-semibold text-[#8a5a14]">Sipariş notu: {{ $order->customer_note }}</p>
                            @endif
                        </article>
                    @empty
                        <p class="rounded-2xl border border-dashed border-line px-4 py-8 text-center text-sm font-medium text-muted">Henüz sipariş yok.</p>
                    @endforelse
                </div>
            </section>

            <section class="card p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-2xl font-semibold text-ink">Adisyona ürün ekle</h2>
                        <p class="mt-0.5 text-xs font-medium text-muted">Seçilen ürünler güncel şube fiyatıyla servis edilmiş olarak kaydedilir.</p>
                    </div>
                    <span class="badge badge-neutral">{{ $categories->sum(fn ($category) => $category->products->count()) }} ürün</span>
                </div>

                <form method="post" action="{{ route('staff.sessions.items.store', $session->id) }}" class="mt-4 space-y-4" data-sparse-items>
                    @csrf
                    <div class="max-h-[38rem] space-y-4 overflow-y-auto pr-1">
                        @forelse ($categories as $category)
                            <div>
                                <p class="mb-2 text-[11px] font-bold uppercase tracking-[.18em] text-terracotta">{{ $category->name }}</p>
                                <div class="space-y-2">
                                    @foreach ($category->products as $product)
                                        <article class="rounded-2xl border border-line bg-paper p-3.5" data-product-card>
                                            <div class="flex items-center justify-between gap-3">
                                                <span class="min-w-0 text-sm font-bold text-ink">
                                                    {{ $product->name }}
                                                    <span class="font-medium text-muted">· ₺{{ number_format((float) $product->priceFor($session->branch), 2, ',', '.') }}</span>
                                                </span>
                                                <label class="flex shrink-0 items-center gap-2 text-[11px] font-bold uppercase tracking-[.1em] text-muted">
                                                    Adet
                                                    <input
                                                        aria-label="{{ $product->name }} adedi"
                                                        type="number"
                                                        name="items[{{ $product->id }}]"
                                                        data-product-quantity
                                                        value="0"
                                                        min="0"
                                                        max="20"
                                                        inputmode="numeric"
                                                        class="input w-16 px-2 py-2 text-center font-bold"
                                                    >
                                                </label>
                                            </div>

                                            @foreach ($product->optionGroups as $group)
                                                @php($availableOptions = $group->options->where('is_available', true))
                                                @if ($availableOptions->isNotEmpty())
                                                    <fieldset class="mt-3 rounded-xl bg-cream/70 p-3">
                                                        <legend class="mb-2 flex w-full items-center justify-between gap-2 text-xs font-bold text-ink">
                                                            <span>{{ $group->name }}</span>
                                                            <span class="text-[10px] font-semibold text-muted">{{ $group->min_selections > 0 ? 'Zorunlu' : 'İsteğe bağlı' }} · {{ $group->selection_mode === 'single' ? 'tek seçim' : 'çoklu seçim' }}</span>
                                                        </legend>
                                                        @if ($group->selection_mode === 'single')
                                                            <label class="sr-only" for="staff-option-{{ $product->id }}-{{ $group->id }}">{{ $group->name }}</label>
                                                            <select id="staff-option-{{ $product->id }}-{{ $group->id }}" name="options[{{ $product->id }}][{{ $group->id }}]" class="input py-2">
                                                                <option value="">{{ $group->min_selections > 0 ? 'Bir seçenek seçin' : 'Ekstra istemiyorum' }}</option>
                                                                @foreach ($availableOptions as $option)
                                                                    <option value="{{ $option->id }}">{{ $option->name }}@if ((float) $option->price_delta > 0) · +₺{{ number_format((float) $option->price_delta, 2, ',', '.') }}@else · Ücretsiz @endif</option>
                                                                @endforeach
                                                            </select>
                                                        @else
                                                            <div class="grid gap-1 sm:grid-cols-2">
                                                                @foreach ($availableOptions as $option)
                                                                    <label class="flex min-h-10 items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-xs">
                                                                        <span class="flex min-w-0 items-center gap-2">
                                                                            <input type="checkbox" name="options[{{ $product->id }}][{{ $group->id }}][]" value="{{ $option->id }}" class="size-4 shrink-0 accent-forest">
                                                                            <span class="font-semibold text-ink">{{ $option->name }}</span>
                                                                        </span>
                                                                        <span class="shrink-0 text-muted">{{ (float) $option->price_delta > 0 ? '+₺'.number_format((float) $option->price_delta, 2, ',', '.') : 'Ücretsiz' }}</span>
                                                                    </label>
                                                                @endforeach
                                                            </div>
                                                        @endif
                                                    </fieldset>
                                                @endif
                                            @endforeach
                                        </article>
                                    @endforeach
                                </div>
                            </div>
                        @empty
                            <p class="rounded-2xl border border-dashed border-line px-4 py-8 text-center text-sm font-medium text-muted">Bu şubede satışa açık ürün yok.</p>
                        @endforelse
                    </div>

                    <label class="block">
                        <span class="label">Not <span class="font-medium normal-case tracking-normal text-muted">(isteğe bağlı)</span></span>
                        <input name="note" maxlength="500" class="input" placeholder="Örn. buz istemiyor">
                    </label>

                    <p data-sparse-items-error class="hidden text-xs font-semibold text-danger" role="alert"></p>

                    <button
                        @disabled($session->status !== 'open' || $categories->sum(fn ($category) => $category->products->count()) === 0)
                        class="btn btn-primary w-full"
                        data-submit-once
                    >
                        <x-ui.icon name="plus" class="size-4" />
                        Seçilenleri adisyona ekle
                    </button>
                </form>
            </section>
        </div>

        <div class="space-y-5">
            <section class="card overflow-hidden" data-split-bill data-due-cents="{{ $dueCents }}">
                <div class="relative overflow-hidden bg-forest p-5 text-white">
                    <div class="pointer-events-none absolute -right-10 -top-12 size-32 rounded-full border-[22px] border-white/5"></div>
                    <div class="relative flex items-start justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.18em] text-amber">Hesap böl</p>
                            <p class="mt-1 text-xs font-medium text-white/60">Kalan tutar</p>
                            <p class="mt-1 font-display text-3xl font-semibold">₺{{ number_format($dueCents / 100, 2, ',', '.') }}</p>
                        </div>
                        <span class="grid size-11 place-items-center rounded-full bg-white/10 text-amber ring-1 ring-white/15">
                            <x-ui.icon name="users" class="size-5" />
                        </span>
                    </div>
                </div>

                <div class="space-y-4 p-5">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-bold text-ink">Kaç kişi?</p>
                            <p class="text-[11px] font-medium text-muted">Tutarı kişi sayısına bölün.</p>
                        </div>
                        <div class="flex items-center gap-1 rounded-full border border-line bg-cream p-1">
                            <button type="button" data-split-step="-1" class="grid size-8 place-items-center rounded-full bg-paper text-ink shadow-sm transition hover:bg-sage" aria-label="Kişi sayısını azalt">−</button>
                            <input type="number" min="1" max="20" value="2" inputmode="numeric" data-split-people class="w-10 border-0 bg-transparent p-0 text-center text-sm font-bold text-ink focus:ring-0" aria-label="Kişi sayısı">
                            <button type="button" data-split-step="1" class="grid size-8 place-items-center rounded-full bg-paper text-ink shadow-sm transition hover:bg-sage" aria-label="Kişi sayısını artır">+</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-2xl bg-cream px-3 py-2.5">
                            <p class="text-[10px] font-bold uppercase tracking-[.12em] text-muted">Kişi başı</p>
                            <p data-split-share class="mt-0.5 text-lg font-bold text-ink">₺0,00</p>
                        </div>
                        <div class="rounded-2xl bg-amber-soft px-3 py-2.5">
                            <p class="text-[10px] font-bold uppercase tracking-[.12em] text-[#8a5a14]/70">Son kişi</p>
                            <p data-split-last class="mt-0.5 text-lg font-bold text-[#8a5a14]">₺0,00</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <button type="button" data-split-apply="share" @disabled($dueCents <= 0) class="btn btn-primary btn-sm justify-center">Kişi başı</button>
                        <button type="button" data-split-apply="last" @disabled($dueCents <= 0) class="btn btn-secondary btn-sm justify-center">Son kişi</button>
                        <button type="button" data-split-apply="half" @disabled($dueCents <= 0) class="btn btn-secondary btn-sm justify-center">Yarısı</button>
                        <button type="button" data-split-apply="full" @disabled($dueCents <= 0) class="btn btn-secondary btn-sm justify-center">Kalanın tamamı</button>
                    </div>

                    <p data-split-status class="text-center text-[11px] font-semibold text-success" aria-live="polite"></p>
                </div>
            </section>

            <section class="card p-5">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-2xl font-semibold text-ink">Ödeme kaydet</h2>
                        <p class="mt-0.5 text-xs font-medium text-muted">Kart ödemesini fiziksel POS cihazında alın.</p>
                    </div>
                    <span class="grid size-10 place-items-center rounded-full bg-success-soft text-success"><x-ui.icon name="banknote" class="size-5" /></span>
                </div>

                <form method="post" action="{{ route('staff.sessions.payments.store', $session->id) }}" class="mt-4 space-y-4">
                    @csrf
                    <label class="block">
                        <span class="label">Tutar</span>
                        <input type="number" name="amount" step="0.01" min="0.01" max="1000000" required data-payment-amount class="input" value="{{ $dueCents > 0 ? \App\Services\Money::toDecimal($dueCents) : '' }}" placeholder="{{ number_format($dueCents / 100, 2, ',', '.') }}">
                    </label>
                    <label class="block">
                        <span class="label">Ödeme yöntemi</span>
                        <select name="method" required class="input">
                            <option value="cash">Nakit</option>
                            <option value="card">Kart (fiziksel POS)</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="label">Ödeme dağılımı</span>
                        <select name="order_item_id" class="input" data-payment-allocation>
                            <option value="" data-due-cents="{{ $dueCents }}">Adisyon geneli</option>
                            @foreach ($session->orders->whereIn('status', ['accepted', 'ready', 'served']) as $order)
                                @foreach ($order->items as $item)
                                    @if (($itemDueCents[$item->id] ?? 0) > 0)
                                        <option value="{{ $item->id }}" data-due-cents="{{ $itemDueCents[$item->id] }}">#{{ $order->order_number }} · {{ $item->quantity }}× {{ $item->name_snapshot }} · Kalan {{ \App\Services\Money::format($itemDueCents[$item->id]) }}</option>
                                    @endif
                                @endforeach
                            @endforeach
                        </select>
                    </label>
                    <label class="block">
                        <span class="label">Kişi / not <span class="font-medium normal-case tracking-normal text-muted">(isteğe bağlı)</span></span>
                        <input name="person_label" maxlength="80" data-payment-person class="input" placeholder="Örn. Ayşe / 2 kişi">
                    </label>
                    <button @disabled($session->status !== 'open' || $dueCents <= 0) class="btn btn-primary w-full" data-submit-once>
                        <x-ui.icon name="check" class="size-4" />
                        Ödemeyi kaydet
                    </button>
                </form>
            </section>

            <section class="card p-5">
                <h2 class="font-display text-xl font-semibold text-ink">Ödeme geçmişi</h2>
                <div class="mt-3 space-y-2">
                    @forelse ($session->payments as $payment)
                        <div class="rounded-2xl border border-line bg-cream/40 p-3 text-sm {{ $payment->voided_at ? 'opacity-60' : '' }}">
                            <div class="flex items-center justify-between gap-3">
                                <span class="flex items-center gap-2 font-semibold text-ink">
                                    <x-ui.icon :name="$payment->method === 'cash' ? 'banknote' : 'card'" class="size-4 text-muted" />
                                    {{ $payment->method === 'cash' ? 'Nakit' : 'Kart' }}@if ($payment->person_label) · {{ $payment->person_label }}@endif
                                </span>
                                <span class="font-bold text-ink">₺{{ number_format((float) $payment->amount, 2, ',', '.') }}</span>
                            </div>
                            <p class="mt-1 text-[11px] font-medium text-muted">{{ $payment->recorded_at?->timezone('Europe/Istanbul')->format('H:i') }} · {{ $payment->recorder?->name ?? 'Personel' }}</p>
                            @if ($payment->voided_at)
                                <p class="mt-1 text-xs font-semibold text-danger">İptal edildi · {{ $payment->void_reason }}</p>
                            @elseif ($session->status === 'open')
                                <form method="post" action="{{ route('staff.sessions.payments.void', [$session->id, $payment->id]) }}" class="mt-2 flex gap-2" data-confirm="Bu ödeme kaydını iptal etmek istiyor musunuz?">
                                    @csrf
                                    <input name="reason" required minlength="3" maxlength="160" placeholder="İptal gerekçesi" class="input min-w-0 flex-1 px-3 py-2 text-xs">
                                    <button class="btn btn-secondary btn-sm text-danger">İptal</button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="rounded-2xl border border-dashed border-line px-4 py-6 text-center text-sm font-medium text-muted">Henüz ödeme yok.</p>
                    @endforelse
                </div>
            </section>

            <form method="post" action="{{ route('staff.sessions.close', $session->id) }}" class="card p-5" data-confirm="Adisyonu kapatmak istediğinize emin misiniz?">
                @csrf
                <h2 class="font-display text-xl font-semibold text-ink">Adisyonu kapat</h2>
                <p class="mt-1 text-xs leading-5 text-muted">Kapatmadan önce siparişlerin teslim edildiğinden ve kalan tutarın tahsil edildiğinden emin olun.</p>
                <label class="mt-3 block">
                    <span class="label">Kapanış notu <span class="font-medium normal-case tracking-normal text-muted">(isteğe bağlı)</span></span>
                    <input name="closing_note" maxlength="160" class="input" placeholder="Örn. hesap tamamlandı">
                </label>
                <button @disabled($session->status !== 'open') class="btn btn-secondary mt-4 w-full">
                    <x-ui.icon name="check-circle" class="size-4" />
                    Adisyonu kapat
                </button>
            </form>
        </div>
    </div>
</x-layouts.panel>
