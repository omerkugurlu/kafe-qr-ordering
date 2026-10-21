@php
    $brand = $cafe->brand_name ?: $cafe->name;
    $steps = [
        'pending' => __('customer.status_pending'),
        'accepted' => __('customer.status_accepted'),
        'ready' => __('customer.status_ready'),
        'served' => __('customer.status_served'),
    ];
    $index = ['pending' => 0, 'accepted' => 1, 'ready' => 2, 'served' => 3, 'paid' => 4][$order['status']] ?? 0;
    $heroIcon = match ($order['status']) {
        'pending' => 'clock',
        'accepted' => 'chef',
        'ready' => 'check-circle',
        'served' => 'utensils',
        'paid' => 'wallet',
        'cancelled' => 'alert',
        default => 'receipt',
    };
@endphp

<x-layouts.customer :title="__('customer.order_tracking')" :cafe="$cafe">
    <div id="order-track" class="min-h-dvh pb-16">
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
                    <div class="absolute -right-10 -top-12 size-40 rounded-full border border-white/10"></div>
                    <div class="absolute -bottom-16 right-16 size-48 rounded-full border border-amber/20"></div>
                    <div class="relative flex items-start justify-between gap-4">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.22em] text-amber">{{ __('customer.order_number') }}</p>
                            <p class="mt-1 font-display text-3xl font-semibold tracking-tight">{{ $order['number'] }}</p>
                            <p class="mt-2 max-w-md text-sm leading-relaxed text-white/75" data-track-help>{{ __('customer.status_help_'.$order['status']) }}</p>
                            <div class="mt-4 flex items-center gap-2">
                                <span data-track-badge class="badge bg-white/15 text-white">{{ __('customer.status_'.$order['status']) }}</span>
                                <span class="inline-flex items-center gap-1.5 rounded-full border border-white/15 px-2.5 py-1 text-[10px] font-bold text-white/70">
                                    <span class="relative flex size-1.5"><span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60"></span><span class="relative inline-flex size-1.5 rounded-full bg-success"></span></span>
                                    {{ __('customer.live') }}
                                </span>
                            </div>
                        </div>
                        <span class="grid size-14 shrink-0 place-items-center rounded-2xl bg-white/10 text-amber ring-1 ring-white/15">
                            <x-ui.icon :name="$heroIcon" class="size-7" />
                        </span>
                    </div>
                </div>

                <div class="px-4 py-5 sm:px-7">
                    <ol class="flex items-start justify-between gap-1" aria-label="{{ __('customer.order_tracking') }}">
                        @foreach ($steps as $key => $label)
                            @php
                                $stepIndex = array_search($key, array_keys($steps), true);
                                $done = $index > $stepIndex;
                                $active = $index === $stepIndex;
                                $dotClass = $order['status'] === 'cancelled' ? 'timeline-dot' : ($done ? 'timeline-dot-done' : ($active ? 'timeline-dot-active' : 'timeline-dot'));
                            @endphp
                            <li class="timeline-step" data-step="{{ $key }}" data-state="{{ $done ? 'done' : ($active ? 'active' : '') }}">
                                <span class="{{ $dotClass }}" data-step-dot>
                                    @if ($done)
                                        <x-ui.icon name="check" class="size-4" />
                                    @elseif ($active)
                                        <span class="size-2 rounded-full bg-current"></span>
                                    @else
                                        <span class="size-1.5 rounded-full bg-current opacity-40"></span>
                                    @endif
                                </span>
                                <span class="{{ $active ? 'text-xs font-bold text-ink' : 'text-xs font-semibold text-muted' }}" data-step-label>{{ $label }}</span>
                            </li>
                        @endforeach
                    </ol>

                    <div data-track-cancelled @class(['mt-5 rounded-2xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm text-danger', 'hidden' => $order['status'] !== 'cancelled'])>
                        <p class="font-bold">{{ __('customer.order_cancelled') }}</p>
                        @if ($order['cancel_reason'])
                            <p class="mt-1 text-xs">{{ __('customer.cancel_reason') }}: {{ $order['cancel_reason'] }}</p>
                        @endif
                    </div>

                    <div data-track-paid @class(['mt-5 rounded-2xl border border-success/20 bg-success-soft px-4 py-3 text-sm font-bold text-forest', 'hidden' => $order['status'] !== 'paid'])>
                        {{ __('customer.status_paid') }} · {{ __('customer.thank_you') }}
                    </div>
                </div>
            </section>

            <section class="grid items-start gap-4 lg:grid-cols-[1.4fr_1fr]">
                <div class="card p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 class="font-display text-lg font-semibold text-ink">{{ __('customer.items') }}</h2>
                        <span class="badge-neutral">{{ $order['number'] }}</span>
                    </div>
                    <div class="mt-4 space-y-3">
                        @foreach ($order['items'] as $item)
                            <div class="flex items-start gap-3 rounded-2xl border border-line bg-cream/40 p-3">
                                <span class="grid size-7 shrink-0 place-items-center rounded-full bg-forest text-[11px] font-bold text-white">{{ $item['quantity'] }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-start justify-between gap-3">
                                        <p class="text-sm font-bold text-ink">{{ $item['name'] }}</p>
                                        <p class="shrink-0 text-sm font-bold text-ink">{{ $item['line_total'] }}</p>
                                    </div>
                                    @if (! empty($item['options']))
                                        <p class="mt-0.5 text-[11px] text-muted">{{ collect($item['options'])->pluck('name')->implode(' · ') }}</p>
                                    @endif
                                    @if ($item['note'])
                                        <p class="mt-0.5 text-[11px] italic text-muted">“{{ $item['note'] }}”</p>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="card p-5">
                        <div class="space-y-1.5 text-sm">
                            <div class="flex items-center justify-between"><span class="text-muted">{{ __('customer.subtotal') }}</span><span class="font-semibold text-ink">{{ \App\Services\Money::format($order['subtotal_cents']) }}</span></div>
                            @if ($order['discount_cents'] > 0)
                                <div class="flex items-center justify-between"><span class="text-muted">{{ __('customer.discount') }} {{ $order['coupon_code'] ? '('.$order['coupon_code'].')' : '' }}</span><span class="font-semibold text-success">−{{ \App\Services\Money::format($order['discount_cents']) }}</span></div>
                            @endif
                            <div class="flex items-center justify-between border-t border-line pt-2"><span class="font-bold text-ink">{{ __('customer.total') }}</span><span class="font-display text-xl font-semibold text-forest">{{ $order['total'] }}</span></div>
                            @if ($session['due_cents'] > 0)
                                <div class="flex items-center justify-between pt-1"><span class="text-muted">{{ __('customer.remaining') }}</span><span class="font-bold text-forest" data-track-due>{{ $session['due'] }}</span></div>
                            @endif
                        </div>
                        @if ($order['note'])
                            <div class="mt-4 rounded-xl bg-cream px-3 py-2 text-xs text-ink"><span class="font-bold">{{ __('customer.order_note') }}:</span> {{ $order['note'] }}</div>
                        @endif
                    </div>

                    <div class="card p-4">
                        <p class="text-xs font-semibold leading-relaxed text-muted">{{ __('customer.pay_at_counter') }}</p>
                        <div class="mt-3 grid gap-2">
                            <a href="{{ route('customer.bill', $token) }}" class="btn-secondary w-full">{{ __('customer.view_bill') }}</a>
                            <a href="{{ route('customer.menu', $token) }}" class="btn-primary w-full">{{ __('customer.menu_again') }}</a>
                        </div>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <button type="button" class="btn-ghost btn-sm border border-line" data-service-request="waiter" data-service-url="{{ route('customer.call', $token) }}">{{ __('customer.call_waiter') }}</button>
                            <button type="button" class="btn-ghost btn-sm border border-line" data-service-request="bill" data-service-url="{{ route('customer.call', $token) }}">{{ __('customer.request_bill') }}</button>
                        </div>
                    </div>
                </div>
            </section>
        </main>

        <script type="application/json" id="track-data">{!! json_encode([
            'statusUrl' => route('customer.status', $token),
            'orderNumber' => $order['number'],
            'order' => $order,
            'strings' => trans('customer'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
</x-layouts.customer>
