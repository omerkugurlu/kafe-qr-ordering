<x-layouts.customer :title="__('customer.my_account')" :cafe="$cafe">
    <div class="mx-auto min-h-dvh max-w-6xl px-4 py-5 sm:px-6 sm:py-8">
        <header class="flex flex-wrap items-center justify-between gap-4 rounded-3xl border border-line bg-paper p-4 shadow-soft sm:p-6">
            <div class="flex min-w-0 items-center gap-3">
                <a href="{{ route('customer.menu', $token) }}" class="grid size-11 shrink-0 place-items-center rounded-full border border-line bg-cream text-forest transition hover:bg-sage/60" aria-label="{{ __('customer.back_to_menu') }}">
                    <x-ui.icon name="arrow-left" class="size-4" />
                </a>
                <div class="min-w-0">
                    <p class="truncate text-[10px] font-bold uppercase tracking-[.16em] text-terracotta">{{ $cafe->brand_name ?: $cafe->name }} · {{ $branch->name }}</p>
                    <h1 class="mt-1 truncate font-display text-2xl font-semibold text-ink sm:text-3xl">{{ __('customer.account_welcome', ['name' => $customer->name]) }}</h1>
                    <p class="mt-1 truncate text-xs text-muted">{{ $customer->email }}</p>
                </div>
            </div>
            <form method="post" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn-secondary min-h-11 px-4">
                    <x-ui.icon name="logout" class="size-4" />
                    {{ __('customer.logout') }}
                </button>
            </form>
            <p class="w-full text-xs leading-relaxed text-muted">{{ __('customer.account_scope_note', ['cafe' => $cafe->brand_name ?: $cafe->name]) }}</p>
        </header>

        <div class="mt-4"><x-ui.flash /></div>

        <main class="mt-5 space-y-8">
            <section aria-label="{{ __('customer.account') }}" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <article class="rounded-3xl border border-amber/30 bg-gradient-to-br from-amber-soft to-paper p-4 shadow-soft sm:p-5">
                    <span class="grid size-10 place-items-center rounded-2xl bg-forest text-amber"><x-ui.icon name="star" class="size-5" /></span>
                    <p class="mt-4 text-xs font-semibold text-muted">{{ __('customer.points_balance') }}</p>
                    <p class="mt-1 font-display text-3xl font-semibold text-ink">{{ number_format((int) ($account?->points ?? 0)) }}</p>
                </article>
                <article class="rounded-3xl border border-line bg-paper p-4 shadow-soft sm:p-5">
                    <span class="grid size-10 place-items-center rounded-2xl bg-sage text-forest"><x-ui.icon name="sparkles" class="size-5" /></span>
                    <p class="mt-4 text-xs font-semibold text-muted">{{ __('customer.points_earned_total') }}</p>
                    <p class="mt-1 font-display text-3xl font-semibold text-ink">{{ number_format($lifetimePoints) }}</p>
                </article>
                <article class="rounded-3xl border border-line bg-paper p-4 shadow-soft sm:p-5">
                    <span class="grid size-10 place-items-center rounded-2xl bg-sage text-forest"><x-ui.icon name="receipt" class="size-5" /></span>
                    <p class="mt-4 text-xs font-semibold text-muted">{{ __('customer.paid_orders_total') }}</p>
                    <p class="mt-1 font-display text-3xl font-semibold text-ink">{{ number_format($paidOrderCount) }}</p>
                </article>
                <article class="rounded-3xl border border-success/20 bg-success-soft/60 p-4 shadow-soft sm:p-5">
                    <span class="grid size-10 place-items-center rounded-2xl bg-success text-white"><x-ui.icon name="trending" class="size-5" /></span>
                    <p class="mt-4 text-xs font-semibold text-muted">{{ __('customer.lifetime_savings') }}</p>
                    <p class="mt-1 font-display text-2xl font-semibold text-success sm:text-3xl">{{ \App\Services\Money::format($savingsCents) }}</p>
                </article>
            </section>

            <div class="grid gap-8 lg:grid-cols-[minmax(0,1.2fr)_minmax(18rem,.8fr)]">
                <section aria-labelledby="account-promotions-heading">
                    <div class="mb-3 flex items-end justify-between gap-3">
                        <div>
                            <p class="eyebrow">{{ $cafe->name }}</p>
                            <h2 id="account-promotions-heading" class="mt-1 font-display text-2xl font-semibold text-ink">{{ __('customer.active_promotions') }}</h2>
                        </div>
                    </div>

                    @if (! $cafe->loyalty_enabled)
                        <div class="rounded-3xl border border-dashed border-line bg-paper px-5 py-8 text-center text-sm text-muted">{{ __('customer.no_active_promotions') }}</div>
                    @elseif ($campaigns->isEmpty())
                        <div class="rounded-3xl border border-dashed border-line bg-paper px-5 py-8 text-center text-sm text-muted">{{ __('customer.no_active_promotions') }}</div>
                    @else
                        <div class="space-y-3">
                            @foreach ($campaigns as $campaignRow)
                                @php
                                    $campaign = $campaignRow['campaign'];
                                    $progressLabel = $campaign->type === \App\Models\LoyaltyCampaign::TYPE_POINTS
                                        ? __('customer.campaign_points_progress', ['current' => $campaignRow['current'], 'required' => $campaignRow['required']])
                                        : __('customer.campaign_orders_progress', ['current' => $campaignRow['current'], 'required' => $campaignRow['required']]);
                                @endphp
                                <article class="rounded-3xl border border-line bg-paper p-4 shadow-soft sm:p-5">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0">
                                            <h3 class="font-display text-lg font-semibold text-ink">{{ $campaign->name }}</h3>
                                            <p class="mt-1 text-sm font-semibold text-forest">{{ __('customer.campaign_reward', ['reward' => $campaignRow['reward']]) }}</p>
                                        </div>
                                        <span class="shrink-0 rounded-full px-3 py-1 text-[10px] font-bold {{ $campaignRow['available'] ? 'bg-success-soft text-success' : 'bg-cream text-muted' }}">
                                            {{ $campaignRow['available'] ? __('customer.campaign_available') : __('customer.campaign_in_progress') }}
                                        </span>
                                    </div>
                                    <div class="mt-4">
                                        <div class="mb-1.5 flex items-center justify-between gap-3 text-xs font-semibold text-muted">
                                            <span>{{ $progressLabel }}</span>
                                            <span>{{ $campaignRow['progress'] }}%</span>
                                        </div>
                                        <div role="progressbar" aria-label="{{ $campaign->name }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $campaignRow['progress'] }}" class="h-2 overflow-hidden rounded-full bg-cream">
                                            <div class="h-full rounded-full bg-forest transition-[width]" style="width: {{ $campaignRow['progress'] }}%"></div>
                                        </div>
                                    </div>
                                    @if ((float) $campaign->minimum_order_total > 0)
                                        <p class="mt-3 text-xs text-muted">{{ __('customer.campaign_minimum_order', ['amount' => \App\Services\Money::formatDecimal($campaign->minimum_order_total)]) }}</p>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-6 rounded-3xl border border-line bg-paper p-4 sm:p-5">
                        <h3 class="font-display text-lg font-semibold text-ink">{{ __('customer.announcements') }}</h3>
                        @forelse ($announcements as $announcement)
                            <article class="border-b border-line py-3 last:border-0 last:pb-0">
                                <p class="text-[10px] font-bold uppercase tracking-[.12em] text-terracotta">{{ __('customer.'.$announcement->type) }}</p>
                                <h4 class="mt-1 font-semibold text-ink">{{ $announcement->title }}</h4>
                                <p class="mt-1 text-sm leading-relaxed text-muted">{{ $announcement->body }}</p>
                            </article>
                        @empty
                            <p class="mt-2 text-sm text-muted">{{ __('customer.no_announcements') }}</p>
                        @endforelse
                    </div>
                </section>

                <section aria-labelledby="account-orders-heading">
                    <div class="mb-3">
                        <p class="eyebrow">{{ __('customer.order_number') }}</p>
                        <h2 id="account-orders-heading" class="mt-1 font-display text-2xl font-semibold text-ink">{{ __('customer.account_order_history') }}</h2>
                    </div>
                    @forelse ($recentOrders as $order)
                        <article class="mb-3 rounded-3xl border border-line bg-paper p-4 shadow-soft">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs font-bold text-ink">{{ __('customer.order_number') }} #{{ $order->order_number }}</p>
                                    <p class="mt-1 text-[11px] text-muted">{{ __('customer.order_at_branch', ['branch' => $order->branch->name, 'table' => $order->tableSession->diningTable->name]) }}</p>
                                    <p class="mt-1 text-[11px] text-muted">{{ $order->created_at->translatedFormat('d M Y · H:i') }}</p>
                                </div>
                                <span class="badge-neutral shrink-0">{{ __('customer.status_'.$order->status) }}</span>
                            </div>
                            <div class="mt-3 flex items-end justify-between gap-3 border-t border-line pt-3">
                                <div>
                                    @if ($order->status === \App\Models\Order::STATUS_PAID && (float) $order->discount_total > 0)
                                        <p class="text-xs font-semibold text-success">{{ __('customer.order_discount_saved', ['amount' => \App\Services\Money::formatDecimal($order->discount_total)]) }}</p>
                                    @endif
                                    <p class="mt-1 text-[10px] font-bold uppercase tracking-[.12em] text-muted">{{ __('customer.total') }}</p>
                                </div>
                                <p class="font-display text-lg font-semibold text-ink">{{ \App\Services\Money::formatDecimal($order->total) }}</p>
                            </div>
                        </article>
                    @empty
                        <div class="rounded-3xl border border-dashed border-line bg-paper px-5 py-8 text-center text-sm text-muted">{{ __('customer.account_no_orders') }}</div>
                    @endforelse
                </section>
            </div>

            <a href="{{ route('customer.menu', $token) }}" class="btn-primary w-full sm:w-auto">
                <x-ui.icon name="arrow-left" class="size-4" />
                {{ __('customer.account_menu_cta') }}
            </a>
        </main>
    </div>
</x-layouts.customer>
