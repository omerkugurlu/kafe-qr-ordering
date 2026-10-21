@php
    $brand = $cafe->brand_name ?: $cafe->name;
    $signature = implode('|', [
        $session['status'],
        $session['orders_count'],
        $session['due_cents'],
        implode(',', array_map(fn (array $order): string => $order['status'], $session['orders'])),
    ]);
    $isOpen = $session['status'] === 'open';
@endphp

<x-layouts.customer :title="__('customer.bill')" :cafe="$cafe">
    <div id="bill-page" class="min-h-dvh pb-16">
        <header class="border-b border-line bg-paper">
            <div class="mx-auto flex max-w-4xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
                <a href="{{ route('customer.menu', $token) }}" class="inline-flex items-center gap-2 text-sm font-bold text-ink-soft transition hover:text-forest">
                    <x-ui.icon name="arrow-left" class="size-4" />
                    {{ __('customer.back_to_menu') }}
                </a>
                <div class="min-w-0 text-right">
                    <p class="truncate font-display text-base font-semibold text-ink">{{ $brand }}</p>
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-muted">{{ $table->name }}</p>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-4xl space-y-4 px-4 py-6 sm:px-6">
            <x-ui.flash />

            <section class="card overflow-hidden">
                <div class="relative bg-forest px-5 py-6 text-white sm:px-7">
                    <div class="absolute -right-12 -top-14 size-44 rounded-full border border-white/10"></div>
                    <div class="flex flex-wrap items-start justify-between gap-5">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.22em] text-amber">{{ $isOpen ? __('customer.open_bill') : __('customer.session_closed') }}</p>
                            <h1 class="mt-1 font-display text-3xl font-semibold tracking-tight">{{ $table->name }}</h1>
                            <p class="mt-1 text-sm text-white/70">{{ $branch->name }} · {{ $session['opened_label'] }}</p>
                            <div class="mt-3 inline-flex items-center gap-1.5 rounded-full border border-white/15 px-2.5 py-1 text-[10px] font-bold text-white/75">
                                <span class="relative flex size-1.5"><span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60"></span><span class="relative inline-flex size-1.5 rounded-full bg-success"></span></span>
                                {{ __('customer.live') }}
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-4 text-right sm:gap-7">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[.14em] text-white/60">{{ __('customer.session_total') }}</p>
                                <p class="mt-1 font-display text-xl font-semibold">{{ $session['total'] }}</p>
                            </div>
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[.14em] text-white/60">{{ __('customer.remaining') }}</p>
                                <p class="mt-1 font-display text-2xl font-semibold text-amber" data-bill-due>{{ $session['due'] }}</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-3 divide-x divide-line border-t border-line text-center">
                    <div class="px-3 py-3">
                        <p class="text-[9px] font-bold uppercase tracking-[.14em] text-muted">{{ __('customer.your_orders') }}</p>
                        <p class="mt-0.5 font-display text-lg font-semibold text-ink">{{ $session['orders_count'] }}</p>
                    </div>
                    <div class="px-3 py-3">
                        <p class="text-[9px] font-bold uppercase tracking-[.14em] text-muted">{{ __('customer.paid') }}</p>
                        <p class="mt-0.5 font-display text-lg font-semibold text-success">{{ $session['paid'] }}</p>
                    </div>
                    <div class="px-3 py-3">
                        <p class="text-[9px] font-bold uppercase tracking-[.14em] text-muted">{{ __('customer.remaining') }}</p>
                        <p class="mt-0.5 font-display text-lg font-semibold text-forest">{{ $session['due'] }}</p>
                    </div>
                </div>
            </section>

            @if (! $isOpen)
                <div class="flex items-start gap-3 rounded-2xl border border-success/20 bg-success-soft px-4 py-3 text-sm text-forest">
                    <x-ui.icon name="check-circle" class="mt-0.5 size-5 shrink-0" />
                    <div>
                        <p class="font-bold">{{ __('customer.session_closed') }} · {{ __('customer.thank_you') }}</p>
                        <p class="mt-0.5 text-xs">{{ __('customer.table_closed_hint') }}</p>
                    </div>
                </div>
            @endif

            <section class="space-y-3">
                @forelse ($session['orders'] as $order)
                    <article class="card overflow-hidden">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-bold text-ink">{{ $order['number'] }}</p>
                                <p class="text-[11px] text-muted">{{ $order['created_label'] }} · {{ $order['items'] ? count($order['items']) : 0 }} {{ __('customer.items') }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <x-ui.status-badge :status="$order['status']" :label="__('customer.status_'.$order['status'])" />
                                <span class="font-display text-base font-semibold text-ink">{{ $order['total'] }}</span>
                            </div>
                        </div>
                        <div class="space-y-2 px-4 py-3">
                            @foreach ($order['items'] as $item)
                                <div class="flex items-start gap-3">
                                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-cream text-[10px] font-bold text-ink">{{ $item['quantity'] }}</span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-start justify-between gap-3">
                                            <p class="text-sm font-semibold text-ink">{{ $item['name'] }}</p>
                                            <p class="shrink-0 text-sm font-semibold text-ink">{{ $item['line_total'] }}</p>
                                        </div>
                                        @if (! empty($item['options']))
                                            <p class="text-[11px] text-muted">{{ collect($item['options'])->pluck('name')->implode(' · ') }}</p>
                                        @endif
                                        @if ($item['note'])
                                            <p class="text-[11px] italic text-muted">“{{ $item['note'] }}”</p>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                            @if ($order['note'])
                                <p class="rounded-xl bg-cream px-3 py-2 text-[11px] text-ink"><span class="font-bold">{{ __('customer.order_note') }}:</span> {{ $order['note'] }}</p>
                            @endif
                        </div>
                    </article>
                @empty
                    <x-ui.empty-state icon="receipt" :title="__('customer.no_open_session')" :description="__('customer.cart_empty_hint')">
                        <a href="{{ route('customer.menu', $token) }}" class="btn-primary">{{ __('customer.see_menu') }}</a>
                    </x-ui.empty-state>
                @endforelse
            </section>

            <section class="grid gap-4 lg:grid-cols-[1.4fr_1fr]">
                <div class="card p-5">
                    <h2 class="font-display text-lg font-semibold text-ink">{{ __('customer.payments') }}</h2>
                    <div class="mt-3 space-y-2">
                        @forelse ($session['payments'] as $payment)
                            <div class="flex items-center justify-between gap-3 rounded-2xl border border-line bg-cream/40 px-3 py-2.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="grid size-8 place-items-center rounded-full bg-paper text-forest"><x-ui.icon :name="$payment['method'] === 'cash' ? 'banknote' : 'card'" class="size-4" /></span>
                                    <div>
                                        <p class="text-xs font-bold text-ink">{{ $payment['method_label'] }} · {{ $payment['amount'] }}</p>
                                        <p class="text-[10px] text-muted">{{ $payment['recorded_label'] }}@if ($payment['recorded_by']) · {{ $payment['recorded_by'] }}@endif</p>
                                    </div>
                                </div>
                                @if ($payment['voided'])
                                    <span class="badge-cancelled">{{ __('customer.status_cancelled') }}</span>
                                @else
                                    <x-ui.icon name="check-circle" class="size-5 text-success" />
                                @endif
                            </div>
                        @empty
                            <p class="rounded-2xl border border-dashed border-line px-4 py-5 text-center text-xs text-muted">{{ __('customer.no_payments') }}</p>
                        @endforelse
                    </div>
                </div>

                <div class="card p-4">
                    <p class="text-xs font-semibold leading-relaxed text-muted">{{ __('customer.pay_at_counter') }}</p>
                    <div class="mt-3 grid gap-2">
                        @if ($isOpen)
                            <button type="button" class="btn-primary w-full" data-service-request="bill" data-service-url="{{ route('customer.call', $token) }}">{{ __('customer.request_bill') }}</button>
                            <button type="button" class="btn-secondary w-full" data-service-request="waiter" data-service-url="{{ route('customer.call', $token) }}">{{ __('customer.call_waiter') }}</button>
                        @endif
                        <a href="{{ route('customer.menu', $token) }}" class="btn-secondary w-full">{{ $isOpen ? __('customer.menu') : __('customer.menu_again') }}</a>
                    </div>
                </div>
            </section>
        </main>

        <script type="application/json" id="bill-data">{!! json_encode([
            'statusUrl' => route('customer.status', $token),
            'signature' => $signature,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
</x-layouts.customer>
