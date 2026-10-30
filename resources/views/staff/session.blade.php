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

    @if ($session->status === 'open')
        <section class="mt-5 flex flex-col gap-3 rounded-2xl border {{ $session->qr_ordering_enabled ? 'border-success/20 bg-success-soft' : 'border-amber/40 bg-amber-soft' }} p-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="text-sm font-bold {{ $session->qr_ordering_enabled ? 'text-success' : 'text-[#8a5a14]' }}">QR siparişi {{ $session->qr_ordering_enabled ? 'açık' : 'kapalı' }}</p>
                <p class="mt-1 text-xs leading-5 text-muted">Masa boşaldığında QR siparişini tek dokunuşla durdurun. Adisyon açık kalır; yeniden oturulduğunda tekrar açabilirsiniz.</p>
            </div>
            <form method="post" action="{{ route('staff.sessions.qr-ordering.update', $session->id) }}">
                @csrf
                <input type="hidden" name="enabled" value="{{ $session->qr_ordering_enabled ? '0' : '1' }}">
                <button class="btn {{ $session->qr_ordering_enabled ? 'btn-secondary' : 'btn-primary' }} w-full sm:w-auto" data-submit-once>
                    {{ $session->qr_ordering_enabled ? 'QR siparişini durdur' : 'QR siparişini yeniden aç' }}
                </button>
            </form>
        </section>
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
                                            <span class="font-semibold text-ink"><span class="mr-1 text-muted">{{ $item->quantity }}×</span>{{ $item->name_snapshot }} @if ($item->is_loyalty_reward) <span class="ml-1 rounded-full bg-success-soft px-2 py-0.5 text-[10px] font-bold text-success">Ücretsiz · normalde {{ \App\Services\Money::formatDecimal($item->list_unit_price) }}</span> @endif</span>
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

                            @php
                                $canCorrectOrder = auth()->user()?->role === \App\Models\User::ROLE_WAITER
                                    ? $order->status === 'pending'
                                    : auth()->user()?->hasRole(\App\Models\User::ROLE_CAFE_OWNER, \App\Models\User::ROLE_CAFE_MANAGER);
                            @endphp
                            @if ($session->status === 'open' && $canCorrectOrder && ! $activePayments->isNotEmpty() && $order->status !== 'paid' && $order->status !== 'cancelled' && (float) $order->discount_total === 0.0 && $order->coupon_id === null && $order->loyalty_campaign_id === null)
                                <details class="mt-3 rounded-xl border border-line bg-paper p-3">
                                    <summary class="cursor-pointer text-xs font-bold text-forest">Siparişi düzelt</summary>
                                    <form method="post" action="{{ route('staff.orders.correct', $order) }}" class="mt-3 space-y-3">
                                        @csrf
                                        <div class="space-y-2">
                                            @foreach ($order->items as $item)
                                                <label class="flex items-center justify-between gap-3 text-xs">
                                                    <span class="min-w-0 font-semibold text-ink">{{ $item->name_snapshot }} <span class="text-muted">· ₺{{ number_format((float) $item->unit_price, 2, ',', '.') }}</span></span>
                                                    <span class="flex shrink-0 items-center gap-2 text-muted">Adet
                                                        <input type="number" name="items[{{ $item->id }}][quantity]" min="1" max="20" required value="{{ $item->quantity }}" class="input w-16 px-2 py-2 text-center font-bold">
                                                    </span>
                                                </label>
                                            @endforeach
                                        </div>
                                        <label class="block">
                                            <span class="label">Sipariş notu</span>
                                            <input name="customer_note" maxlength="500" value="{{ $order->customer_note }}" class="input" placeholder="İsteğe bağlı not">
                                        </label>
                                        <label class="block">
                                            <span class="label">Düzeltme nedeni</span>
                                            <input name="reason" minlength="3" maxlength="160" required class="input" placeholder="Örn. adet yanlış girildi">
                                        </label>
                                        <button class="btn btn-secondary btn-sm w-full" data-submit-once>Siparişi kaydet</button>
                                    </form>
                                </details>
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
                        <p class="mt-0.5 text-xs font-medium text-muted">Müşterinin seçtiklerini girin. Normal sipariş mutfağa düşer; isterseniz servisi tamamlananları doğrudan adisyona ekleyin.</p>
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
                                                @php
                                                    $availableOptions = $group->options->where('is_available', true);
                                                @endphp
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

                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            type="submit"
                            name="mode"
                            value="send"
                            @disabled($session->status !== 'open' || $categories->sum(fn ($category) => $category->products->count()) === 0)
                            class="btn btn-primary w-full"
                            data-submit-once
                        >
                            <x-ui.icon name="send" class="size-4" />
                            Siparişi mutfağa gönder
                        </button>
                        <button
                            type="submit"
                            name="mode"
                            value="served"
                            @disabled($session->status !== 'open' || $categories->sum(fn ($category) => $category->products->count()) === 0)
                            class="btn btn-secondary w-full"
                            data-submit-once
                        >
                            <x-ui.icon name="check" class="size-4" />
                            Servis edileni adisyona ekle
                        </button>
                    </div>
                </form>
            </section>
        </div>

        <div class="space-y-5">
            <section class="card overflow-hidden" data-payment-picker data-due-cents="{{ $dueCents }}" data-split-due-cents="{{ $splitDueCents }}" data-split-target-cents="{{ $activeSplitGroup !== null ? \App\Services\Money::toCents((string) $activeSplitGroup->initial_amount) : $splitDueCents }}" data-split-paid-cents="{{ $activeSplitGroup !== null ? \App\Services\Money::toCents((string) $activeSplitGroup->payments->whereNull('voided_at')->sum('amount')) : 0 }}" data-split-count="{{ $activeSplitGroup?->people_count ?? 0 }}" data-split-paid="{{ $splitPaidCount }}" data-split-active="{{ $activeSplitGroup !== null ? 'true' : 'false' }}">
                <div class="bg-forest px-5 py-4 text-white">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-amber">Ödeme al</p>
                    <div class="mt-1 flex items-end justify-between gap-3">
                        <div>
                            <h2 class="font-display text-2xl font-semibold">Ödeme al</h2>
                            <p class="mt-0.5 text-xs text-white/70">Ürün seç veya hesabı kişi sayısına böl.</p>
                        </div>
                        <p class="shrink-0 text-right">
                            <span class="block text-[10px] font-semibold uppercase tracking-wider text-white/60">Kalan</span>
                            <span class="font-display text-lg font-semibold">{{ \App\Services\Money::format($dueCents) }}</span>
                        </p>
                    </div>
                </div>

                <form method="post" action="{{ route('staff.sessions.payments.store', $session->id) }}" class="space-y-4 p-4 sm:p-5">
                    @csrf
                    <fieldset class="space-y-3 rounded-2xl border border-line bg-cream/60 p-3" aria-label="Hesap paylaşımı">
                        <legend class="label px-1">Hesabı nasıl alacaksın?</legend>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-xl border border-line bg-paper px-3 text-xs font-bold has-[:checked]:border-forest has-[:checked]:bg-sage/40">
                                <input type="radio" name="payment_mode" value="items" data-payment-mode @checked($activeSplitGroup === null) @disabled($activeSplitGroup !== null) class="size-4 accent-forest">
                                Ürün seç
                            </label>
                            <label class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-xl border border-line bg-paper px-3 text-xs font-bold has-[:checked]:border-forest has-[:checked]:bg-sage/40">
                                <input type="radio" name="payment_mode" value="split" data-payment-mode @checked($activeSplitGroup !== null) class="size-4 accent-forest">
                                Kişi sayısına böl
                            </label>
                        </div>
                        <div data-payment-split-panel @if ($activeSplitGroup === null) hidden @endif>
                            <p class="text-xs leading-5 text-muted" data-split-description>
                                @if ($activeSplitGroup !== null)
                                    Bölme devam ediyor: {{ $splitPaidCount }} / {{ $activeSplitGroup->people_count }} kişi ödedi. Her ödeme sonrası kalan tutar yeniden hesaplanır.
                                @else
                                    Toplam hesabı 2 veya 4 kişiye böl. Kuruş farkı eşit ve doğru dağılır.
                                @endif
                            </p>
                            <div class="mt-2 grid grid-cols-2 gap-2" role="radiogroup" aria-label="Hesabı bölecek kişi sayısı">
                                @foreach ([2, 4] as $peopleCount)
                                    <label class="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-xl border border-line bg-paper px-3 text-sm font-bold has-[:checked]:border-forest has-[:checked]:bg-sage/40">
                                        <input type="radio" name="split_people" value="{{ $peopleCount }}" data-split-people @checked(($activeSplitGroup?->people_count ?? 2) === $peopleCount) @disabled($activeSplitGroup !== null && $activeSplitGroup->people_count !== $peopleCount) disabled class="size-4 accent-forest">
                                        {{ $peopleCount }} kişi
                                    </label>
                                @endforeach
                            </div>
                            <div class="mt-2 flex items-center justify-between rounded-xl bg-paper px-3 py-2" aria-live="polite">
                                <span class="text-xs font-semibold text-muted" data-split-remaining>Pay tutarı</span>
                                <strong class="font-display text-lg font-semibold text-ink" data-split-share>₺0,00</strong>
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="space-y-2" data-payment-items-fieldset @disabled($activeSplitGroup !== null)>
                        <legend class="label">Ödenecek ürünler</legend>
                        @php
                            $payableOrders = $session->orders->whereIn('status', ['accepted', 'ready', 'served']);
                            $hasPayableItem = collect($itemDueCents)->contains(fn ($balance) => $balance > 0);
                        @endphp
                        @if (! $hasPayableItem)
                            <p class="rounded-2xl border border-dashed border-line px-4 py-6 text-center text-sm text-muted">Ödeme alınabilecek ürün yok.</p>
                        @else
                        <div class="space-y-3">
                            <label class="block">
                                <span class="sr-only">Ürün ara</span>
                                <input type="search" data-payment-search autocomplete="off" class="input" placeholder="Ürün adıyla ara…">
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" data-payment-select-all class="btn-secondary w-full px-3 text-xs">Tüm kalan ürünler</button>
                                <button type="button" data-payment-clear class="btn-secondary w-full px-3 text-xs">Seçimi temizle</button>
                            </div>
                        </div>
                        @foreach ($payableOrders as $order)
                            @foreach ($order->items as $item)
                                @php
                                    $itemBalanceCents = $itemDueCents[$item->id] ?? 0;
                                    $unitCents = \App\Services\Money::toCents((string) $item->unit_price);
                                    $maxPayableQuantity = $unitCents > 0
                                        ? min($item->quantity, intdiv($itemBalanceCents + $unitCents - 1, $unitCents))
                                        : 0;
                                @endphp
                                @if ($maxPayableQuantity > 0)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-2xl border border-line bg-paper px-3 py-2.5 transition has-[:checked]:border-forest has-[:checked]:bg-sage/30" data-payment-item data-due-cents="{{ $itemBalanceCents }}" data-unit-cents="{{ $unitCents }}" data-max-quantity="{{ $maxPayableQuantity }}">
                                        <input type="checkbox" data-payment-item-check aria-label="{{ $item->name_snapshot }} seç" class="size-5 shrink-0 accent-forest">
                                        <div class="min-w-0 flex-1">
                                            <p data-payment-item-name class="truncate text-sm font-bold text-ink">{{ $item->name_snapshot }}</p>
                                            <p class="text-[11px] font-medium text-muted">#{{ $order->order_number }} · {{ \App\Services\Money::format($unitCents) }} / adet · {{ \App\Services\Money::format($itemBalanceCents) }} kaldı</p>
                                        </div>
                                        <span class="flex shrink-0 items-center gap-2 text-[10px] font-bold uppercase tracking-wider text-muted">
                                            Adet
                                            <input type="number" name="items[{{ $item->id }}]" value="1" min="1" max="{{ $maxPayableQuantity }}" inputmode="numeric" data-payment-item-quantity disabled aria-label="{{ $item->name_snapshot }} ödeme adedi" class="input w-[4.5rem] px-2 py-2 text-center text-sm font-bold disabled:opacity-40">
                                        </span>
                                    </label>
                                @endif
                            @endforeach
                        @endforeach
                        <p data-payment-empty-results hidden class="rounded-2xl border border-dashed border-line px-4 py-5 text-center text-sm text-muted">Bu aramayla eşleşen ürün yok.</p>
                        @endif
                    </fieldset>

                    <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Ödeme yöntemi">
                        <label class="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-2xl border border-line bg-paper px-3 py-2 text-sm font-bold transition has-[:checked]:border-forest has-[:checked]:bg-sage/40">
                            <input type="radio" name="method" value="cash" required checked class="size-4 accent-forest">
                            <x-ui.icon name="banknote" class="size-4" /> Nakit
                        </label>
                        <label class="flex min-h-12 cursor-pointer items-center justify-center gap-2 rounded-2xl border border-line bg-paper px-3 py-2 text-sm font-bold transition has-[:checked]:border-forest has-[:checked]:bg-sage/40">
                            <input type="radio" name="method" value="card" required class="size-4 accent-forest">
                            <x-ui.icon name="card" class="size-4" /> Kart
                        </label>
                    </div>
                    <p class="-mt-2 text-[11px] text-muted">Kart ödemesini fiziksel POS cihazından aldıktan sonra kaydet.</p>

                    <label class="block" data-cash-received-wrap>
                        <span class="label">Alınan nakit</span>
                        <input type="number" name="cash_received" min="0.01" step="0.01" inputmode="decimal" data-cash-received class="input" placeholder="Müşterinin verdiği tutar">
                    </label>

                    <label class="block">
                        <span class="label">Ödeyen kişi <span class="font-medium normal-case tracking-normal text-muted">(isteğe bağlı)</span></span>
                        <input name="person_label" maxlength="80" class="input" placeholder="Örn. Ayşe">
                    </label>

                    <div class="rounded-2xl bg-cream px-4 py-3" aria-live="polite">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs font-semibold text-muted" data-payment-selected-summary>0 adet seçildi</span>
                            <strong data-payment-total class="font-display text-xl font-semibold text-ink">₺0,00</strong>
                        </div>
                        <p data-payment-items-summary class="mt-1 truncate text-[11px] font-medium text-muted">Ödenecek ürünleri seç.</p>
                        <p data-payment-change class="mt-1 text-right text-xs font-semibold text-success">Para üstü: ₺0,00</p>
                    </div>

                    <button type="submit" @disabled($session->status !== 'open' || $dueCents <= 0 || $payableOrders->isEmpty()) class="btn-primary w-full" data-payment-submit data-submit-once>
                        <x-ui.icon name="check" class="size-4" />
                        Seçilen ürünlerin ödemesini kaydet
                    </button>
                </form>
            </section>

            <section class="card p-5">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-xl font-semibold text-ink">Ödeme geçmişi</h2>
                        <p class="mt-0.5 text-xs font-medium text-muted">Kaydedilen tahsilatlar</p>
                    </div>
                </div>
                <div class="mt-3 space-y-2">
                    @forelse ($session->payments as $payment)
                        <div class="rounded-2xl border border-line bg-cream/40 p-3 text-sm {{ $payment->voided_at ? 'opacity-60' : '' }}">
                            <div class="flex items-center justify-between gap-3">
                                <span class="flex items-center gap-2 font-semibold text-ink">
                                    <x-ui.icon :name="$payment->method === 'cash' ? 'banknote' : 'card'" class="size-4 text-muted" />
                                    {{ $payment->method === 'cash' ? 'Nakit' : 'Kart' }}
                                    @if ($payment->allocations->isNotEmpty())
                                        · {{ $payment->allocations->map(fn ($allocation) => $allocation->quantity.'× '.($allocation->orderItem?->name_snapshot ?? 'Ürün'))->implode(', ') }}
                                    @elseif ($payment->orderItem)
                                        · {{ $payment->orderItem->name_snapshot }}
                                    @endif
                                    @if ($payment->person_label) · {{ $payment->person_label }}@endif
                                    @if ($payment->splitGroup) · {{ $payment->split_person_number }} / {{ $payment->splitGroup->people_count }} kişi @endif
                                </span>
                                <span class="font-bold text-ink">₺{{ number_format((float) $payment->amount, 2, ',', '.') }}</span>
                            </div>
                            <p class="mt-1 text-[11px] font-medium text-muted">{{ $payment->recorded_at?->timezone('Europe/Istanbul')->format('H:i') }} · {{ $payment->recorder?->name ?? 'Personel' }}</p>
                            @if ($payment->method === 'cash' && $payment->cash_received !== null)
                                <p class="mt-1 text-[11px] font-semibold text-success">Verilen {{ \App\Services\Money::formatDecimal($payment->cash_received) }} · Para üstü {{ \App\Services\Money::formatDecimal($payment->change_amount) }}</p>
                            @endif
                            @if ($payment->voided_at)
                                <p class="mt-1 text-xs font-semibold text-danger">İptal edildi · {{ $payment->void_reason }}</p>
                            @elseif (auth()->user()?->role === \App\Models\User::ROLE_CAFE_OWNER)
                                <form method="post" action="{{ route('staff.sessions.payments.void', [$session->id, $payment->id]) }}" class="mt-2 flex gap-2" data-confirm="Bu ödeme kaydını iptal etmek istiyor musunuz?">
                                    @csrf
                                    <input name="reason" required minlength="3" maxlength="160" placeholder="Düzeltme nedeni" class="input min-w-0 flex-1 px-3 py-2 text-xs">
                                    <button class="btn btn-secondary btn-sm text-danger">Kaydı düzelt</button>
                                </form>
                                @if ($session->status === 'closed')
                                    <p class="mt-1 text-[10px] leading-4 text-muted">Düzeltme kaydı geçmişte kalır. Gerçek POS iadesi gerekiyorsa ayrıca POS cihazından yapın.</p>
                                @endif
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
