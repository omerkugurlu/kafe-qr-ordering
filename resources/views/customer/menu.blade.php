@php
    $brand = $cafe->brand_name ?: $cafe->name;
    $locale = app()->getLocale();
@endphp

<x-layouts.customer :title="$brand" :cafe="$cafe">
    <div id="customer-menu" class="min-h-dvh pb-32">
        <header class="relative">
            <div class="relative h-56 overflow-hidden bg-forest sm:h-72">
                @if ($cafe->cover_url)
                    <img src="{{ $cafe->cover_url }}" alt="{{ $brand }} kapak görseli" class="absolute inset-0 size-full object-cover">
                @else
                    <div class="absolute inset-0 bg-gradient-to-br from-forest via-forest-soft to-[#3d2f26]"></div>
                    <div class="absolute -right-16 -top-24 size-72 rounded-full border border-white/10"></div>
                    <div class="absolute -bottom-28 left-10 size-80 rounded-full border border-amber/20"></div>
                    <div class="absolute inset-0 grain opacity-40"></div>
                @endif
                <div class="absolute inset-0 bg-gradient-to-t from-ink/90 via-ink/35 to-ink/25"></div>
                <div class="absolute inset-x-0 top-0 h-32 bg-gradient-to-b from-ink/70 to-transparent"></div>

                <div class="relative z-10 mx-auto flex max-w-6xl items-start justify-between gap-4 px-4 py-4 sm:px-6">
                    <a href="{{ route('customer.menu', $token) }}" class="flex min-w-0 items-center gap-3 text-white">
                        @if ($cafe->logo_url)
                            <img src="{{ $cafe->logo_url }}" alt="" class="size-11 shrink-0 rounded-2xl object-cover ring-1 ring-white/30">
                        @else
                            <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-white/10 text-amber ring-1 ring-white/20 backdrop-blur">
                                <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 8l8 14 8-14-8-6Z"/><path d="M4 8h16M12 2v20" opacity=".35"/></svg>
                            </span>
                        @endif
                        <span class="min-w-0 leading-tight">
                            <span class="block truncate font-display text-lg font-semibold">{{ $brand }}</span>
                            <span class="mt-0.5 block truncate text-[10px] font-bold uppercase tracking-[.2em] text-white/65">{{ $branch->name }}</span>
                        </span>
                    </a>

                    <div class="flex shrink-0 items-center gap-2">
                        <div class="flex items-center rounded-full border border-white/20 bg-white/10 p-0.5 text-[10px] font-bold text-white backdrop-blur">
                            <a href="{{ route('locale.switch', ['locale' => 'tr']) }}" class="rounded-full px-2 py-1 {{ $locale === 'tr' ? 'bg-white text-ink' : 'text-white/75 hover:text-white' }}">TR</a>
                            <a href="{{ route('locale.switch', ['locale' => 'en']) }}" class="rounded-full px-2 py-1 {{ $locale === 'en' ? 'bg-white text-ink' : 'text-white/75 hover:text-white' }}">EN</a>
                        </div>

                        @if ($customer !== null)
                            <span class="hidden max-w-32 truncate rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-[11px] font-bold text-white backdrop-blur sm:block">{{ $customer->name }}</span>
                            <form method="post" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="grid size-11 place-items-center rounded-full border border-white/20 bg-white/10 text-white backdrop-blur transition hover:bg-white/20" aria-label="{{ __('customer.logout') }}" title="{{ __('customer.logout') }}">
                                    <x-ui.icon name="logout" class="size-4" />
                                </button>
                            </form>
                        @elseif (auth()->check())
                            <form method="post" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="min-h-11 rounded-full border border-white/20 bg-white/10 px-3 text-[11px] font-bold text-white backdrop-blur transition hover:bg-white/20">{{ __('customer.logout') }}</button>
                            </form>
                        @elseif ($cafe->registration_policy !== \App\Models\Cafe::POLICY_GUEST)
                            <a href="{{ route('customer.login', $token) }}" class="rounded-full border border-white/20 bg-white/10 px-3 py-1.5 text-[11px] font-bold text-white backdrop-blur transition hover:bg-white/20">{{ __('customer.login') }}</a>
                        @endif
                    </div>
                </div>

                <div class="absolute inset-x-0 bottom-0">
                    <div class="mx-auto max-w-6xl px-4 pb-7 sm:px-6">
                        <div class="flex items-end gap-4">
                            @if ($cafe->logo_url)
                                <img src="{{ $cafe->logo_url }}" alt="" class="hidden size-20 shrink-0 rounded-3xl object-cover shadow-lift ring-4 ring-cream/20 sm:block">
                            @endif
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-white/20 bg-white/10 px-2.5 py-1 text-[10px] font-bold uppercase tracking-[.14em] text-white/80 backdrop-blur">
                                        <span class="size-1.5 rounded-full bg-success"></span>
                                        {{ __('customer.brand_tagline') }}
                                    </span>
                                </div>
                                <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight text-white text-balance sm:text-5xl">{{ $brand }}</h1>
                                @if ($cafe->description)
                                    <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/75 sm:text-base">{{ $cafe->description }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="relative z-10 mx-auto -mt-5 max-w-6xl px-4 sm:px-6">
            <div class="grid grid-cols-2 gap-2 rounded-3xl border border-line bg-paper p-2 shadow-soft sm:grid-cols-4">
                <div class="flex items-center gap-2 rounded-2xl bg-cream px-3 py-2.5">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-forest text-white"><x-ui.icon name="table" class="size-4" /></span>
                    <div class="min-w-0"><p class="text-[9px] font-bold uppercase tracking-[.14em] text-muted">{{ __('customer.table') }}</p><p class="truncate text-xs font-bold text-ink">{{ $table->name }}</p></div>
                </div>
                <a href="{{ route('customer.bill', $token) }}" class="flex items-center gap-2 rounded-2xl bg-cream px-3 py-2.5 transition hover:bg-sage/60">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-paper text-forest"><x-ui.icon name="receipt" class="size-4" /></span>
                    <div class="min-w-0"><p class="text-[9px] font-bold uppercase tracking-[.14em] text-muted">{{ __('customer.bill') }}</p><p class="truncate text-xs font-bold text-ink">{{ __('customer.view_live_bill') }}</p></div>
                </a>
                <button type="button" data-service-request="waiter" data-service-url="{{ route('customer.call', $token) }}" class="flex items-center gap-2 rounded-2xl bg-cream px-3 py-2.5 text-left transition hover:bg-sage/60">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-paper text-forest"><x-ui.icon name="bell" class="size-4" /></span>
                    <div class="min-w-0"><p class="text-[9px] font-bold uppercase tracking-[.14em] text-muted">{{ __('customer.service') }}</p><p class="truncate text-xs font-bold text-ink">{{ __('customer.call_waiter') }}</p></div>
                </button>
                <button type="button" data-service-request="bill" data-service-url="{{ route('customer.call', $token) }}" class="flex items-center gap-2 rounded-2xl bg-cream px-3 py-2.5 text-left transition hover:bg-sage/60">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-paper text-forest"><x-ui.icon name="wallet" class="size-4" /></span>
                    <div class="min-w-0"><p class="text-[9px] font-bold uppercase tracking-[.14em] text-muted">{{ __('customer.payment') }}</p><p class="truncate text-xs font-bold text-ink">{{ __('customer.request_bill') }}</p></div>
                </button>
            </div>
        </div>

        <div class="mx-auto mt-4 max-w-6xl px-4 sm:px-6">
            <x-ui.flash />
        </div>

        @if ($loyaltyTeaser)
            <section class="mx-auto mt-4 max-w-6xl px-4 sm:px-6">
                <div class="relative overflow-hidden rounded-3xl border border-amber/30 bg-gradient-to-r from-amber-soft via-paper to-sage/60 p-4 shadow-soft sm:p-5">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-start gap-3">
                            <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-forest text-amber shadow-soft">
                                <x-ui.icon name="star" class="size-5" />
                            </span>
                            <div>
                                <p class="font-display text-lg font-semibold text-ink">{{ __('customer.loyalty_teaser_title') }}</p>
                                <p class="mt-0.5 text-sm leading-6 text-muted">{{ __('customer.loyalty_teaser_body') }}</p>
                            </div>
                        </div>
                        <a href="{{ route('customer.register', $token) }}" class="btn btn-primary shrink-0">
                            {{ __('customer.loyalty_teaser_cta') }}
                            <x-ui.icon name="arrow-right" class="size-4" />
                        </a>
                    </div>
                </div>
            </section>
        @endif

        @if ($announcements->isNotEmpty())
            <section class="mx-auto mt-4 max-w-6xl px-4 sm:px-6" aria-labelledby="announcements-title">
                <div class="mb-2 flex items-center justify-between gap-3">
                    <h2 id="announcements-title" class="font-display text-xl font-semibold text-ink">{{ __('customer.announcements') }}</h2>
                    <span class="text-[11px] font-bold uppercase tracking-[.14em] text-muted">{{ $announcements->count() }} {{ __('customer.announcement') }}</span>
                </div>
                <div class="scroll-hidden flex snap-x gap-3 overflow-x-auto pb-1">
                    @foreach ($announcements as $announcement)
                        <article class="relative w-[86%] shrink-0 snap-start overflow-hidden rounded-3xl border border-line bg-paper p-4 shadow-soft sm:w-[48%] lg:w-[32%]">
                            <div class="pointer-events-none absolute -right-8 -top-8 size-24 rounded-full {{ $announcement->type === 'campaign' ? 'bg-amber/15' : ($announcement->type === 'event' ? 'bg-sage' : 'bg-cream') }}"></div>
                            <div class="relative flex items-center gap-2">
                                <span class="grid size-8 place-items-center rounded-full bg-forest text-amber">
                                    <x-ui.icon :name="$announcement->type === 'event' ? 'calendar' : ($announcement->type === 'campaign' ? 'ticket' : 'bell')" class="size-4" />
                                </span>
                                <span class="text-[10px] font-bold uppercase tracking-[.14em] text-terracotta">{{ __('customer.'.$announcement->type) }}</span>
                            </div>
                            <h3 class="relative mt-3 font-display text-lg font-semibold leading-snug text-ink">{{ $announcement->title }}</h3>
                            @if ($announcement->body)
                                <p class="relative mt-1 line-clamp-3 text-sm leading-6 text-muted">{{ $announcement->body }}</p>
                            @endif
                            @if ($announcement->starts_at || $announcement->ends_at)
                                <p class="relative mt-3 text-[11px] font-semibold text-muted">
                                    {{ $announcement->starts_at?->format('d.m.Y H:i') ?? 'Hemen' }}
                                    @if ($announcement->ends_at)
                                        – {{ $announcement->ends_at->format('d.m.Y H:i') }}
                                    @endif
                                </p>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <div id="session-banner" hidden class="mt-3 border-y border-sage-deep/60 bg-sage/50"></div>

        <div class="sticky top-0 z-30 mt-4 border-y border-line/70 bg-cream/90 backdrop-blur-xl">
            <div class="mx-auto max-w-6xl px-4 py-3 sm:px-6">
                <label class="relative block" for="menu-search">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-muted">
                        <x-ui.icon name="search" class="size-5" />
                    </span>
                    <input id="menu-search" type="search" class="input h-12 pl-12 pr-4" placeholder="{{ __('customer.search_placeholder') }}" autocomplete="off">
                </label>
                <div id="category-chips" class="scroll-hidden mt-3 flex gap-2 overflow-x-auto pb-0.5" aria-label="{{ __('customer.menu') }}"></div>
            </div>
        </div>

        <section id="featured-section" class="mx-auto max-w-6xl px-4 pt-7 sm:px-6">
            <div class="mb-3 flex items-end justify-between gap-3">
                <div>
                    <p class="eyebrow">{{ __('customer.recommended') }}</p>
                    <h2 class="mt-0.5 font-display text-xl font-semibold tracking-tight text-ink sm:text-2xl">{{ __('customer.popular') }}</h2>
                </div>
                <div class="flex items-center gap-3">
                    <span class="hidden text-[11px] font-bold uppercase tracking-[.14em] text-muted sm:inline">{{ $brand }}</span>
                    <div data-featured-controls hidden class="flex gap-2">
                        <button type="button" data-featured-prev class="grid size-11 place-items-center rounded-full border border-line bg-paper text-forest transition hover:border-forest/30 hover:bg-sage/50 disabled:cursor-not-allowed disabled:opacity-40" aria-label="{{ __('customer.previous_featured') }}">
                            <x-ui.icon name="arrow-left" class="size-4" />
                        </button>
                        <button type="button" data-featured-next class="grid size-11 place-items-center rounded-full border border-line bg-paper text-forest transition hover:border-forest/30 hover:bg-sage/50 disabled:cursor-not-allowed disabled:opacity-40" aria-label="{{ __('customer.next_featured') }}">
                            <x-ui.icon name="arrow-right" class="size-4" />
                        </button>
                    </div>
                </div>
            </div>
            <div id="featured-products" role="region" aria-label="{{ __('customer.popular') }}" class="scroll-hidden -mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0"></div>
        </section>

        <div id="menu-empty" hidden class="mx-auto max-w-6xl px-4 py-12 sm:px-6">
            <x-ui.empty-state icon="search" :title="__('customer.search_empty')" :description="__('customer.search_placeholder')" />
        </div>

        <main id="menu-sections" class="mx-auto max-w-6xl space-y-10 px-4 py-7 sm:px-6"></main>
        <main id="search-results" hidden class="mx-auto grid max-w-6xl gap-3 px-4 py-7 sm:grid-cols-2 sm:px-6 xl:grid-cols-3"></main>

        <section aria-labelledby="skykite-promo-heading" class="mx-auto max-w-6xl px-4 pb-8 pt-2 sm:px-6">
            <div class="relative overflow-hidden rounded-[2rem] bg-forest px-5 py-6 text-white shadow-lift sm:px-8 sm:py-8">
                <div aria-hidden="true" class="pointer-events-none absolute -right-10 -top-16 size-56 rounded-full bg-forest-soft/80 blur-3xl"></div>
                <div class="relative flex flex-col items-start gap-5 sm:flex-row sm:items-center sm:justify-between sm:gap-8">
                    <div class="flex items-start gap-4">
                        <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-white/10 text-amber sm:size-14">
                            <x-ui.icon name="sparkles" class="size-6" />
                        </span>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.2em] text-amber">{{ __('customer.brand_promo_eyebrow') }}</p>
                            <h2 id="skykite-promo-heading" class="mt-1 font-display text-2xl font-semibold leading-tight sm:text-3xl">{{ __('customer.brand_promo_title') }}</h2>
                            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/75 sm:text-base">{{ __('customer.brand_promo_description') }}</p>
                        </div>
                    </div>
                    <a href="https://skykite.com.tr/?utm_source=masa_qr&amp;utm_medium=menu&amp;utm_campaign=cross_promotion" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-12 w-full shrink-0 items-center justify-center gap-2 rounded-full bg-amber px-5 font-bold text-forest transition hover:bg-amber-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white sm:w-auto">
                        {{ __('customer.brand_promo_cta') }}
                        <x-ui.icon name="external" class="size-4" />
                    </a>
                </div>
            </div>
        </section>

        <footer class="mt-4 border-t border-line bg-paper">
            <div class="mx-auto flex max-w-6xl flex-col gap-5 px-4 py-8 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                <div>
                    <p class="font-display text-lg font-semibold text-ink">{{ $brand }}</p>
                    <p class="mt-1 text-sm text-muted">{{ $branch->address ?: $cafe->address }}</p>
                    <div class="mt-3 flex flex-wrap items-center gap-3 text-xs font-semibold text-ink-soft">
                        @if ($cafe->phone)
                            <a href="tel:{{ $cafe->phone }}" class="inline-flex items-center gap-1.5 rounded-full border border-line bg-cream px-3 py-1.5"><x-ui.icon name="phone" class="size-3.5" />{{ $cafe->phone }}</a>
                        @endif
                        @if ($cafe->instagram)
                            <a href="https://instagram.com/{{ ltrim($cafe->instagram, '@') }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-full border border-line bg-cream px-3 py-1.5"><x-ui.icon name="instagram" class="size-3.5" />{{ '@'.ltrim($cafe->instagram, '@') }}</a>
                        @endif
                    </div>
                </div>
                <div class="text-xs text-muted sm:text-right">
                    <p class="font-bold uppercase tracking-[.18em] text-ink-soft">SkyKite</p>
                    <p class="mt-1">{{ __('customer.brand_tagline') }}</p>
                    @guest
                        <a href="{{ route('customer.register', $token) }}" class="mt-2 inline-block font-bold text-forest underline decoration-amber decoration-2 underline-offset-4">{{ __('customer.register') }}</a>
                    @endguest
                </div>
            </div>
        </footer>

        <div id="cart-bar" hidden class="fixed inset-x-0 bottom-0 z-40 border-t border-line bg-paper/95 pb-[env(safe-area-inset-bottom)] shadow-bar backdrop-blur-xl">
            <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3 sm:px-6">
                <span class="relative grid size-11 shrink-0 place-items-center rounded-full bg-forest text-white">
                    <x-ui.icon name="bag" class="size-5" />
                    <span data-cart-count class="absolute -right-1 -top-1 grid min-w-5 place-items-center rounded-full bg-amber px-1 text-[10px] font-bold text-ink">0</span>
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-muted">{{ __('customer.total') }}</p>
                    <p class="font-display text-lg font-semibold text-ink" data-cart-total>₺0,00</p>
                </div>
                <button type="button" class="btn-primary" data-cart-open>
                    {{ __('customer.view_cart') }}
                    <span data-cart-count class="grid min-w-5 place-items-center rounded-full bg-white/20 px-1 text-[10px]">0</span>
                </button>
            </div>
        </div>

        {{-- Product sheet --}}
        <div data-sheet="product" hidden>
            <div class="sheet-backdrop" data-sheet-close></div>
            <section class="sheet-panel" role="dialog" aria-modal="true" aria-labelledby="product-sheet-title">
                <div id="product-sheet-body"></div>
            </section>
        </div>

        {{-- Upsell sheet --}}
        <div data-sheet="upsell" hidden>
            <div class="sheet-backdrop" data-sheet-close></div>
            <section class="sheet-panel" role="dialog" aria-modal="true" aria-labelledby="upsell-title">
                <div class="p-5 pb-8">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="eyebrow">{{ __('customer.suggestions_title') }}</p>
                            <h2 id="upsell-title" class="mt-1 font-display text-xl font-semibold tracking-tight text-ink">{{ __('customer.suggestions_title') }}</h2>
                            <p class="mt-1 text-sm text-muted">{{ __('customer.suggestions_hint') }}</p>
                        </div>
                        <button type="button" class="btn-icon shrink-0" data-sheet-close aria-label="{{ __('customer.close') }}"><x-ui.icon name="x" class="size-4" /></button>
                    </div>
                    <div id="upsell-body" class="mt-4 space-y-2"></div>
                    <div class="mt-5 grid grid-cols-2 gap-2">
                        <button type="button" class="btn-secondary" data-sheet-close>{{ __('customer.skip') }}</button>
                        <button type="button" class="btn-primary" data-upsell-cart>{{ __('customer.view_cart') }}</button>
                    </div>
                </div>
            </section>
        </div>

        {{-- Cart drawer --}}
        <div data-drawer="cart" hidden>
            <div data-drawer-backdrop class="fixed inset-0 z-40 hidden bg-ink/45 backdrop-blur-[2px]" data-drawer-close></div>
            <aside class="drawer-panel z-50" role="dialog" aria-modal="true" aria-labelledby="cart-title">
                <header class="flex items-center justify-between gap-3 border-b border-line px-4 py-4">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.18em] text-muted">{{ $table->name }}</p>
                        <h2 id="cart-title" class="font-display text-xl font-semibold text-ink">{{ __('customer.cart') }}</h2>
                    </div>
                    <button type="button" class="btn-icon" data-drawer-close aria-label="{{ __('customer.close') }}"><x-ui.icon name="x" class="size-4" /></button>
                </header>
                <div id="cart-body" class="flex-1 overflow-y-auto p-4"></div>
                <div id="cart-footer" class="border-t border-line bg-paper p-4" hidden>
                    <form id="coupon-form" class="flex gap-2">
                        <label class="relative min-w-0 flex-1" for="coupon-code">
                            <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-muted"><x-ui.icon name="ticket" class="size-4" /></span>
                            <input id="coupon-code" type="text" inputmode="text" autocomplete="off" class="input h-11 pl-10 text-xs uppercase tracking-[.08em]" placeholder="{{ __('customer.coupon_placeholder') }}">
                        </label>
                        <button type="submit" class="btn-secondary btn-sm shrink-0">{{ __('customer.apply') }}</button>
                    </form>
                    <div id="coupon-status" hidden class="mt-2 flex items-center justify-between gap-2 rounded-xl bg-cream px-3 py-2">
                        <span id="coupon-status-text" class="flex-1 text-xs font-bold text-success"></span>
                        <button type="button" class="text-[11px] font-bold text-danger underline underline-offset-2" data-coupon-remove>{{ __('customer.remove') }}</button>
                    </div>
                    <p id="coupon-message" hidden class="mt-2 text-xs font-bold text-danger"></p>

                    <div id="loyalty-box" hidden class="mt-3 rounded-2xl border border-amber/40 bg-amber-soft/35 p-3">
                        <div class="flex items-center justify-between gap-2">
                            <p class="flex items-center gap-1.5 text-xs font-bold text-ink"><x-ui.icon name="sparkles" class="size-4 text-[#8a5a14]" /> {{ __('customer.loyalty_title') }}</p>
                            <span id="loyalty-points" class="rounded-full bg-paper px-2 py-0.5 text-[10px] font-bold text-[#8a5a14]"></span>
                        </div>
                        <div id="loyalty-options" class="mt-2 space-y-1.5"></div>
                        <p id="loyalty-note" class="mt-2 text-[11px] font-semibold leading-4 text-[#7a4f12]">{{ __('customer.loyalty_coupon_conflict') }}</p>
                    </div>

                    <div class="mt-3 space-y-1.5 border-t border-line pt-3 text-sm">
                        <div class="flex items-center justify-between"><span class="text-muted">{{ __('customer.subtotal') }}</span><span class="font-semibold text-ink" data-cart-subtotal>₺0,00</span></div>
                        <div class="flex items-center justify-between" data-cart-discount-row hidden><span class="text-muted">{{ __('customer.discount') }}</span><span class="font-semibold text-success" data-cart-discount>−₺0,00</span></div>
                        <div class="flex items-center justify-between pt-1"><span class="font-bold text-ink">{{ __('customer.total') }}</span><span class="font-display text-xl font-semibold text-forest" data-cart-total-line>₺0,00</span></div>
                    </div>

                    <label for="cart-note" class="label mt-4">{{ __('customer.order_note') }}</label>
                    <textarea id="cart-note" rows="2" maxlength="500" class="input resize-none text-xs" placeholder="{{ __('customer.note_placeholder') }}"></textarea>

                    <button type="button" class="btn-primary mt-3 w-full" data-checkout>{{ __('customer.confirm_order') }}</button>
                    <p class="mt-2 text-center text-[11px] font-medium text-muted">{{ __('customer.pay_at_counter') }}</p>
                </div>
            </aside>
        </div>

        {{-- Confirmation sheet --}}
        <div data-sheet="confirm" hidden>
            <div class="sheet-backdrop" data-sheet-close></div>
            <section class="sheet-panel" role="dialog" aria-modal="true" aria-labelledby="confirm-title">
                <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
                    <div>
                        <p class="eyebrow">{{ __('customer.confirm_title') }}</p>
                        <h2 id="confirm-title" class="mt-0.5 font-display text-xl font-semibold text-ink">{{ __('customer.cart') }}</h2>
                    </div>
                    <button type="button" class="btn-icon shrink-0" data-sheet-close aria-label="{{ __('customer.close') }}"><x-ui.icon name="x" class="size-4" /></button>
                </div>
                <div id="confirm-body" class="px-5 py-4"></div>
                <div class="sticky bottom-0 border-t border-line bg-paper px-5 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.16em] text-muted">{{ __('customer.total') }}</p>
                            <p class="font-display text-2xl font-semibold text-forest" data-confirm-total-footer>₺0,00</p>
                        </div>
                        <button type="button" class="btn-primary flex-1 sm:flex-none" data-confirm-order>
                            <x-ui.icon name="send" class="size-4" />
                            {{ __('customer.send_order') }}
                        </button>
                    </div>
                </div>
            </section>
        </div>

        {{-- No-JS / crawler fallback: also keeps product data readable for assistive tech. --}}
        <noscript>
            <div class="mx-auto max-w-6xl px-4 pb-16 sm:px-6">
                <h2 class="font-display text-2xl font-semibold text-ink">{{ __('customer.menu') }}</h2>
                @foreach ($categories as $category)
                    <section class="mt-6">
                        <h3 class="text-lg font-bold text-ink">{{ $category->name }}</h3>
                        <div class="mt-2 space-y-3">
                            @foreach ($category->products as $product)
                                @php $productPrice = \App\Services\Money::toCents($product->priceFor($branch)); @endphp
                                <article class="rounded-2xl border border-line bg-paper p-4">
                                    <p class="font-semibold text-ink">{{ $product->name }} — {{ \App\Services\Money::format($productPrice) }}</p>
                                    @if ($product->description)
                                        <p class="mt-1 text-sm text-muted">{{ $product->description }}</p>
                                    @endif
                                    @if ($product->optionGroups->isNotEmpty())
                                        <p class="mt-2 text-sm font-bold text-forest">{{ __('customer.customize') }}</p>
                                        <ul class="mt-1 space-y-0.5 text-xs text-muted">
                                            @foreach ($product->optionGroups as $group)
                                                @foreach ($group->options as $option)
                                                    <li>{{ $group->name }} · {{ $option->name }} {{ '+'. \App\Services\Money::format(\App\Services\Money::toCents($option->price_delta)) }}</li>
                                                @endforeach
                                            @endforeach
                                        </ul>
                                    @endif
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        </noscript>

        <script type="application/json" id="menu-data">{!! json_encode($menuData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!}</script>
    </div>
</x-layouts.customer>
