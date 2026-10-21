<x-layouts.app title="{{ $branch->cafe->name }} · Menü">
    @php($productCount = $categories->sum(fn ($category) => $category->products->count()))
    <div class="mx-auto max-w-5xl pb-28" data-customer-menu>
        <header class="relative isolate overflow-hidden rounded-[2rem] bg-[#183c32] px-6 py-8 text-white shadow-xl shadow-[#183c32]/10 sm:px-10 sm:py-11">
            <div aria-hidden="true" class="absolute -right-12 -top-20 -z-10 size-72 rounded-full bg-[#cbe987]/20 blur-3xl"></div>
            <div aria-hidden="true" class="absolute -bottom-36 left-1/3 -z-10 size-80 rounded-full bg-[#78a88a]/25 blur-3xl"></div>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <p class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1.5 text-[11px] font-bold uppercase tracking-[.18em] text-[#d7efa1]">
                    <span class="size-2 rounded-full bg-[#d7efa1]"></span>{{ $branch->cafe->brand_name ?: $branch->cafe->name }}
                </p>
                @auth<span class="inline-flex min-h-10 items-center rounded-full border border-white/20 px-4 text-xs font-bold text-white/85">{{ auth()->user()->name }}</span>@else<a href="{{ route('login') }}" class="inline-flex min-h-10 items-center rounded-full border border-white/20 px-4 text-xs font-bold text-white/85 transition hover:bg-white/10">Hesabım</a>@endauth
            </div>
            <div class="mt-8 max-w-2xl">
                <p class="text-xs font-bold uppercase tracking-[.2em] text-white/60">Güzel bir mola burada başlar</p>
                <h1 class="mt-2 font-display text-4xl font-extrabold tracking-tight sm:text-5xl">{{ $branch->cafe->name }}</h1>
                <p class="mt-3 text-sm text-white/75 sm:text-base">{{ $branch->name }} <span class="mx-1 text-white/35">/</span> {{ $table->name }}</p>
            </div>
            <div class="mt-8 flex flex-wrap items-center gap-2 text-xs font-semibold text-white/80">
                <span class="rounded-full bg-white/10 px-3 py-2">{{ $productCount }} ürün</span>
                <span class="rounded-full bg-white/10 px-3 py-2">Masaya servis</span>
                <span class="rounded-full bg-white/10 px-3 py-2">Ödeme garsona</span>
            </div>
            @guest
                @if ($branch->cafe->registration_policy !== 'guest')
                    <a href="{{ route('customer.register', $table->token()) }}" class="mt-6 inline-flex min-h-11 items-center rounded-xl bg-[#d7efa1] px-4 py-2.5 text-sm font-extrabold text-[#183c32] shadow-sm transition hover:bg-white">Hesap oluştur <span class="ml-2" aria-hidden="true">↗</span></a>
                @endif
            @endguest
        </header>

        @if ($campaigns->isNotEmpty())
            <section class="mt-7" aria-labelledby="campaign-title">
                <div class="mb-3 flex items-end justify-between gap-3 px-1">
                    <div><p class="text-[11px] font-extrabold uppercase tracking-[.18em] text-[#c16c46]">Güzel sürprizler</p><h2 id="campaign-title" class="mt-1 font-display text-xl font-extrabold">Kampanyalar</h2></div>
                    @if ($loyaltyAccount)<span class="rounded-full bg-[#e7f1d5] px-3 py-1.5 text-xs font-bold text-[#285c47]">{{ $loyaltyAccount->points }} puan</span>@endif
                </div>
                <div class="flex snap-x gap-3 overflow-x-auto pb-2">
                    @foreach ($campaigns as $campaign)
                        <article class="min-w-[min(82vw,19rem)] snap-start rounded-2xl border border-[#285c47]/10 bg-[#eef4df] p-4 sm:min-w-72">
                            <div class="flex items-start justify-between gap-3"><h3 class="font-display font-extrabold">{{ $campaign->name }}</h3><span class="shrink-0 rounded-full bg-white/80 px-2.5 py-1 text-[11px] font-extrabold text-[#285c47]">{{ $campaign->reward_type === 'percent' ? number_format((float) $campaign->reward_value, 0, ',', '.').'%' : '₺'.number_format((float) $campaign->reward_value, 2, ',', '.') }}</span></div>
                            <p class="mt-2 text-xs leading-5 text-[#456156]">@if($campaign->type === 'order_count'){{ $campaign->threshold }} tamamlanmış sipariş sonrası indirim ödülü kazanırsınız.@else{{ $campaign->requiredPoints() }} puanla bu ödülü kullanabilirsiniz.@endif</p>
                            @if (! auth()->check())<p class="mt-3 text-[11px] font-bold text-[#285c47]">Puan ve ödül için müşteri hesabı gerekir.</p>@endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($loyaltyAccount)
            <aside class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[#285c47]/10 bg-white px-4 py-3.5 shadow-sm">
                <div><p class="text-sm font-extrabold">Sadakat bakiyen</p><p class="mt-0.5 text-xs text-muted">{{ $loyaltyAccount->completed_order_count }} tamamlanmış sipariş · {{ $loyaltyAccount->points }} puan</p></div>
                <span class="grid size-10 place-items-center rounded-full bg-[#d7efa1] text-lg" aria-hidden="true">✳</span>
            </aside>
        @endif

        @if ($popularProducts->isNotEmpty())
            <section class="mt-8" aria-labelledby="popular-title">
                <div class="mb-3 px-1"><p class="text-[11px] font-extrabold uppercase tracking-[.18em] text-[#c16c46]">Misafirlerin favorisi</p><h2 id="popular-title" class="mt-1 font-display text-xl font-extrabold">En çok tercih edilenler</h2></div>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($popularProducts as $product)
                        <a href="#product-{{ $product->id }}" class="group rounded-2xl border border-ink/10 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-[#285c47]/30 hover:shadow-md">
                            <span class="grid size-10 place-items-center rounded-xl bg-[#eef4df] text-lg" aria-hidden="true">☕</span>
                            <span class="mt-3 block font-bold group-hover:text-[#285c47]">{{ $product->name }}</span>
                            <span class="mt-1 block text-sm text-muted">₺{{ number_format((float) $product->priceFor($branch), 2, ',', '.') }}</span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif

        <section class="mt-9" aria-labelledby="menu-title">
            <div class="flex flex-wrap items-end justify-between gap-3 px-1">
                <div><p class="text-[11px] font-extrabold uppercase tracking-[.18em] text-[#c16c46]">Sana özel hazırlandı</p><h2 id="menu-title" class="mt-1 font-display text-2xl font-extrabold">Menüyü keşfet</h2></div>
                <span class="rounded-full border border-ink/10 bg-white px-3 py-1.5 text-xs font-bold text-muted">{{ $productCount }} lezzet</span>
            </div>

            <nav class="sticky top-0 z-20 -mx-5 mt-4 flex snap-x gap-2 overflow-x-auto border-y border-ink/5 bg-cream/90 px-5 py-3 backdrop-blur sm:-mx-8 sm:px-8" aria-label="Menü kategorileri">
                @foreach ($categories as $category)
                    @if ($category->products->isNotEmpty())<a href="#category-{{ $category->id }}" class="min-h-10 shrink-0 snap-start rounded-full border border-ink/10 bg-white px-4 py-2 text-xs font-bold transition hover:border-[#285c47]/30 hover:bg-[#eef4df]">{{ $category->name }}</a>@endif
                @endforeach
            </nav>

            <form method="post" action="{{ route('customer.orders.store', $table->token()) }}" class="mt-4 space-y-8" data-customer-menu>
                @csrf
                @forelse ($categories as $category)
                    @if ($category->products->isNotEmpty())
                        <section id="category-{{ $category->id }}" class="scroll-mt-20" aria-labelledby="category-title-{{ $category->id }}">
                            <div class="mb-3 flex items-end justify-between gap-3 px-1"><div><h3 id="category-title-{{ $category->id }}" class="font-display text-xl font-extrabold">{{ $category->name }}</h3>@if($category->description)<p class="mt-1 text-xs text-muted">{{ $category->description }}</p>@endif</div><span class="text-xs font-semibold text-muted">{{ $category->products->count() }} ürün</span></div>
                            <div class="grid gap-3">
                                @foreach ($category->products as $product)
                                    <article id="product-{{ $product->id }}" class="scroll-mt-24 rounded-2xl border border-ink/10 bg-white p-4 shadow-sm sm:p-5" data-product-card data-base-price="{{ number_format((float) $product->priceFor($branch), 2, '.', '') }}">
                                        <div class="flex items-start gap-3">
                                            <div class="grid size-11 shrink-0 place-items-center rounded-2xl bg-[#eef4df] text-xl" aria-hidden="true">{{ str_contains(mb_strtolower($category->name), 'tatlı') ? '🍰' : (str_contains(mb_strtolower($category->name), 'soğuk') ? '🧊' : '☕') }}</div>
                                            <div class="min-w-0 flex-1">
                                                <div class="flex flex-wrap items-start justify-between gap-2"><h4 class="font-display text-base font-extrabold">{{ $product->name }}</h4><strong data-current-unit-price class="shrink-0 font-display text-base font-extrabold text-[#285c47]">₺{{ number_format((float) $product->priceFor($branch), 2, ',', '.') }}</strong></div>
                                                @if ($product->description)<p class="mt-1 max-w-2xl text-xs leading-5 text-muted">{{ $product->description }}</p>@endif
                                                @if ($product->optionGroups->isNotEmpty())
                                                    <details class="mt-3 rounded-xl bg-[#f7f8f3] px-3 py-2.5">
                                                        <summary class="min-h-8 cursor-pointer list-none text-xs font-extrabold text-[#285c47]"><span>Ürünü kişiselleştir</span><span class="ml-1 font-medium text-muted">· {{ $product->optionGroups->count() }} seçenek grubu</span></summary>
                                                        <div class="mt-3 space-y-3">
                                                            @foreach ($product->optionGroups as $group)
                                                                @php($availableOptions = $group->options)
                                                                <fieldset class="border-t border-ink/5 pt-3" data-option-group data-min-selections="{{ $group->min_selections }}" data-max-selections="{{ $group->max_selections ?? $availableOptions->count() }}">
                                                                    <legend class="mb-2 flex w-full items-center justify-between gap-2 text-xs font-bold"><span>{{ $group->name }}</span><span class="text-[10px] font-semibold text-muted">{{ $group->min_selections > 0 ? 'Zorunlu' : 'İsteğe bağlı' }} · {{ $group->selection_mode === 'single' ? 'tek seçim' : 'çoklu seçim' }}</span></legend>
                                                                    @if ($group->selection_mode === 'single')
                                                                        <label class="sr-only" for="option-group-{{ $group->id }}-{{ $product->id }}">{{ $group->name }}</label>
                                                                        <select id="option-group-{{ $group->id }}-{{ $product->id }}" name="options[{{ $product->id }}][{{ $group->id }}]" data-option-single class="min-h-11 w-full rounded-xl border border-ink/10 bg-white px-3 py-2 text-sm focus:border-[#285c47] focus:outline-none">
                                                                            <option value="" data-price-delta="0">{{ $group->min_selections > 0 ? 'Bir seçenek seçin' : 'Ekstra istemiyorum' }}</option>
                                                                            @foreach ($availableOptions as $option)
                                                                                <option value="{{ $option->id }}" data-price-delta="{{ number_format((float) $option->price_delta, 2, '.', '') }}">{{ $option->name }}@if ((float) $option->price_delta > 0) · +₺{{ number_format((float) $option->price_delta, 2, ',', '.') }}@else · Ücretsiz @endif</option>
                                                                            @endforeach
                                                                        </select>
                                                                    @else
                                                                        <div class="grid gap-1 sm:grid-cols-2">
                                                                            @foreach ($availableOptions as $option)
                                                                                <label class="flex min-h-11 items-center justify-between gap-3 rounded-lg px-2.5 py-2 text-xs transition hover:bg-white"><span class="flex min-w-0 items-center gap-2"><input type="checkbox" name="options[{{ $product->id }}][{{ $group->id }}][]" value="{{ $option->id }}" data-option-checkbox data-price-delta="{{ number_format((float) $option->price_delta, 2, '.', '') }}" class="size-4 shrink-0 rounded border-ink/20 accent-[#285c47]"><span class="font-semibold">{{ $option->name }}</span></span><span class="shrink-0 text-muted">{{ (float) $option->price_delta > 0 ? '+₺'.number_format((float) $option->price_delta, 2, ',', '.') : 'Ücretsiz' }}</span></label>
                                                                            @endforeach
                                                                        </div>
                                                                    @endif
                                                                    <p data-option-limit-message class="mt-1 hidden text-[11px] font-semibold text-red-700" role="alert"></p>
                                                                </fieldset>
                                                            @endforeach
                                                        </div>
                                                    </details>
                                                @endif
                                                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-ink/5 pt-3">
                                                    <p class="text-xs text-muted"><span data-current-line-total>₺0,00</span> <span class="text-[10px]">seçilen adet toplamı</span></p>
                                                    <div class="flex items-center gap-2 rounded-full border border-ink/10 bg-[#fafbf7] p-1">
                                                        <button type="button" data-quantity-change="-1" class="grid size-9 place-items-center rounded-full text-lg font-bold text-[#285c47] hover:bg-[#eef4df]" aria-label="{{ $product->name }} adedini azalt">−</button>
                                                        <label class="sr-only" for="product-quantity-{{ $product->id }}">{{ $product->name }} adedi</label>
                                                        <input id="product-quantity-{{ $product->id }}" data-product-quantity type="number" name="items[{{ $product->id }}]" value="0" min="0" max="20" inputmode="numeric" class="w-9 border-0 bg-transparent p-0 text-center text-sm font-extrabold outline-none">
                                                        <button type="button" data-quantity-change="1" class="grid size-9 place-items-center rounded-full bg-[#d7efa1] text-lg font-bold text-[#183c32] hover:bg-[#c7e77f]" aria-label="{{ $product->name }} adedini artır">+</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        </section>
                    @endif
                @empty
                    <div class="rounded-3xl border border-dashed border-ink/15 bg-white p-9 text-center"><span class="text-3xl" aria-hidden="true">☕</span><h3 class="mt-3 font-display font-extrabold">Menü hazırlanıyor</h3><p class="mt-1 text-sm text-muted">Bu kafede henüz satışta ürün yok.</p></div>
                @endforelse

                @if ($redeemableCampaigns->isNotEmpty())
                    <label class="block rounded-2xl border border-[#285c47]/10 bg-[#eef4df] p-4"><span class="block text-sm font-extrabold">Sadakat ödülünü kullan</span><span class="mt-1 block text-xs text-muted">Ödül ürün seçenekleri eklendikten sonra hesaplanır.</span><select name="loyalty_campaign_id" class="mt-3 min-h-11 w-full rounded-xl border border-ink/10 bg-white px-3 py-2 text-sm"><option value="">Bu siparişte ödül kullanma</option>@foreach($redeemableCampaigns as $campaign)<option value="{{ $campaign->id }}">{{ $campaign->name }} · {{ $campaign->reward_type === 'percent' ? number_format((float) $campaign->reward_value, 0, ',', '.').'%' : '₺'.number_format((float) $campaign->reward_value, 2, ',', '.') }} indirim</option>@endforeach</select></label>
                @endif
                <label class="block"><span class="mb-2 block text-sm font-bold">Sipariş notu <span class="font-normal text-muted">(isteğe bağlı)</span></span><textarea name="customer_note" maxlength="500" rows="2" class="w-full resize-y rounded-xl border border-ink/10 bg-white px-4 py-3 text-sm outline-none focus:border-[#285c47]" placeholder="Örn. şekersiz olsun"></textarea></label>

                <div class="fixed inset-x-0 bottom-0 z-30 border-t border-ink/10 bg-white/95 px-4 py-3 shadow-[0_-8px_30px_rgb(25_52_43_/_8%)] backdrop-blur sm:bottom-4 sm:left-auto sm:right-6 sm:w-[min(28rem,calc(100%-3rem))] sm:rounded-2xl sm:border">
                    <div class="mx-auto flex max-w-5xl items-center gap-3 sm:max-w-none"><div class="min-w-0 flex-1"><p class="text-[10px] font-bold uppercase tracking-wide text-muted">Sepet · <span data-cart-count>0</span> ürün</p><p data-cart-total class="mt-0.5 font-display text-xl font-extrabold">₺0,00</p></div><button data-submit-order type="submit" disabled class="min-h-12 flex-1 rounded-xl bg-[#285c47] px-4 py-3 text-sm font-extrabold text-white transition hover:bg-[#183c32] disabled:cursor-not-allowed disabled:opacity-45">Siparişi gönder <span aria-hidden="true">→</span></button></div>
                    <p data-cart-error class="mx-auto mt-1 hidden max-w-5xl text-center text-[10px] font-semibold text-red-700 sm:max-w-none" role="alert"></p><p class="mx-auto mt-1 max-w-5xl text-center text-[10px] text-muted sm:max-w-none">Ödeme masada garsonunuza yapılır.</p>
                </div>
            </form>
        </section>
        <p class="mt-10 border-t border-ink/10 pt-5 text-center text-[11px] text-muted">Menü ve masada sipariş <span class="font-bold text-[#285c47]">SkyKite</span> ile.</p>
    </div>
</x-layouts.app>
