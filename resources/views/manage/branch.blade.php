<x-layouts.panel
    :title="$branch->name"
    :subtitle="auth()->user()->cafe->name . ' · menü ve şube yönetimi'"
    active="menu"
    :branch="$branch"
>
    @php
        $totalProducts = $categories->sum('products_count');
        $otherBranches = auth()->user()->cafe->branches()->where('id', '!=', $branch->id)->orderBy('name')->get(['id', 'name']);
    @endphp

    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-2xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm font-semibold text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            @foreach (auth()->user()->cafe->branches()->orderBy('name')->get(['id', 'name']) as $branchOption)
                <a
                    href="{{ route('manage.branches.show', $branchOption) }}"
                    @class(['chip', 'chip-active' => $branchOption->id === $branch->id])
                >
                    <x-ui.icon name="pin" class="size-3.5" /> {{ $branchOption->name }}
                </a>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('manage.tables.index', $branch) }}" class="btn btn-secondary btn-sm">
                <x-ui.icon name="qr" class="size-4" /> Masalar & QR
            </a>
            <a href="{{ route('manage.orders.index') }}" class="btn btn-primary btn-sm">
                <x-ui.icon name="panel" class="size-4" /> Canlı siparişler
            </a>
        </div>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Kategori</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $categories->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">{{ $categories->where('is_active', true)->count() }} tanesi yayında</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Ürün</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $totalProducts }}</p>
            <p class="mt-1 text-xs font-medium text-muted">{{ $availableProductCount }} tanesi satışta</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Masa</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $branch->dining_tables_count }}</p>
            <p class="mt-1 text-xs font-medium text-muted">QR kodu üretilmiş</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Menü kaynağı</p>
            <p class="mt-2 font-display text-2xl font-semibold text-ink">{{ $branch->uses_shared_menu ? 'Ortak menü' : 'Özel menü' }}</p>
            <p class="mt-1 text-xs font-medium text-muted">{{ $branch->uses_shared_menu ? 'Tüm şubelerle paylaşılır' : 'Yalnızca bu şubede geçerli' }}</p>
        </article>
    </div>

    @if ($categories->isNotEmpty())
        <div class="mt-4 flex min-w-0 items-center gap-2 rounded-2xl border border-line bg-paper p-3">
            <button type="button" data-category-scroll="left" aria-label="Önceki kategoriler" class="btn btn-secondary btn-sm shrink-0 px-2" hidden>
                <x-ui.icon name="arrow-left" class="size-4" />
            </button>
            <nav data-category-strip aria-label="Menü kategorileri" class="flex min-w-0 flex-1 gap-2 overflow-x-auto scroll-smooth py-0.5">
                @foreach ($categories as $categoryOption)
                    <a
                        href="{{ route('manage.branches.show', ['branch' => $branch, 'category_id' => $categoryOption->id]) }}"
                        data-category-option
                        @if ($selectedCategory?->id === $categoryOption->id) aria-current="page" @endif
                        @class(['chip shrink-0', 'chip-active' => $selectedCategory?->id === $categoryOption->id])
                    >
                        {{ $categoryOption->name }} <span class="ml-1 text-muted">{{ $categoryOption->products_count }}</span>
                    </a>
                @endforeach
            </nav>
            <button type="button" data-category-scroll="right" aria-label="Sonraki kategoriler" class="btn btn-secondary btn-sm shrink-0 px-2" hidden>
                <x-ui.icon name="arrow-right" class="size-4" />
            </button>
        </div>
    @endif

    @if ($suggestionPool->isNotEmpty())
        <template id="menu-suggestion-options">
            @foreach ($suggestionPool as $suggestion)
                <label data-suggestion-option data-suggestion-product-id="{{ $suggestion->id }}" data-suggestion-category-id="{{ $suggestion->category_id }}" class="flex min-h-12 items-center gap-3 rounded-xl px-3 text-sm font-semibold text-ink hover:bg-cream/70">
                    <input type="checkbox" value="{{ $suggestion->id }}" class="size-5 shrink-0 accent-forest">
                    <span data-suggestion-product-name class="truncate">{{ $suggestion->name }}</span>
                </label>
            @endforeach
        </template>
    @endif
    @if ($categories->isNotEmpty())
        <template id="menu-suggestion-category-options">
            @foreach ($categories as $suggestionCategory)
                <label data-suggestion-category-option class="flex min-h-10 items-center gap-2 rounded-lg px-2 text-[11px] font-semibold text-ink hover:bg-cream/70">
                    <input type="checkbox" value="{{ $suggestionCategory->id }}" class="size-4 accent-forest">
                    <span data-suggestion-category-name class="truncate">{{ $suggestionCategory->name }}</span>
                </label>
            @endforeach
        </template>
    @endif

    <div class="mt-4 grid min-w-0 gap-4 xl:grid-cols-2">
        @if ($suggestionPool->where('is_available', true)->isNotEmpty())
            <section class="card overflow-hidden" aria-labelledby="featured-manager-title">
                <div class="space-y-3 p-4">
                    <div class="flex min-w-0 items-center gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-amber-soft text-amber-900"><x-ui.icon name="star" class="size-5" /></span>
                        <span class="min-w-0 flex-1"><span id="featured-manager-title" class="block font-display text-base font-semibold leading-tight text-ink">Sizin için seçtiklerimiz</span><span class="mt-1 block text-xs text-muted">Müşteri menüsünün üstünde göster</span></span>
                        <span data-featured-count class="shrink-0 rounded-full bg-sage px-2.5 py-1 text-xs font-bold text-forest">{{ $suggestionPool->where('is_available', true)->where('is_featured', true)->count() }} / 8 seçili</span>
                    </div>
                    <button type="submit" form="featured-products-form" class="btn btn-primary min-h-11 w-full">Seçtiklerimizi kaydet</button>
                </div>
                <details>
                    <summary class="flex min-h-12 cursor-pointer list-none items-center border-t border-line px-4 py-3 text-sm font-semibold text-forest">Ürün seçimini aç veya düzenle</summary>
                    <form id="featured-products-form" method="POST" action="{{ route('manage.branches.featured.update', $branch) }}" data-featured-picker class="space-y-3 border-t border-line p-4">
                        @csrf @method('PATCH')
                        <p class="text-sm text-muted">En fazla 8 ürün seçin.</p>
                        <div><span class="label">Kategori</span><div data-featured-categories role="group" aria-label="Seçilen ürün kategorisi" class="mt-1 grid max-h-36 grid-cols-2 gap-2">
                            <button type="button" data-featured-category-button data-category-id="all" aria-pressed="true" class="min-h-10 rounded-xl border border-forest bg-sage px-3 text-left text-sm font-semibold text-forest">Tüm kategoriler</button>
                            @foreach ($categories as $featuredCategory)
                                <button type="button" data-featured-category-button data-category-id="{{ $featuredCategory->id }}" aria-pressed="false" class="min-h-10 truncate rounded-xl border border-line bg-paper px-3 text-left text-sm font-semibold text-ink hover:border-forest/50">{{ $featuredCategory->name }}</button>
                            @endforeach
                        </div></div>
                        <label class="block"><span class="label">Ürün ara</span><input type="search" data-featured-search class="input min-h-11 py-2 text-sm" placeholder="Ürün adı yazın..." autocomplete="off"></label>
                        <div data-featured-options class="grid max-h-56 gap-1 overflow-y-auto rounded-xl border border-line bg-cream/40 p-2">
                            @foreach ($suggestionPool->where('is_available', true) as $featuredProduct)
                                <label data-featured-option data-category-id="{{ $featuredProduct->category_id }}" class="flex min-h-12 items-center gap-3 rounded-xl px-3 text-sm font-semibold text-ink hover:bg-paper"><input type="checkbox" name="featured_ids[]" value="{{ $featuredProduct->id }}" @checked($featuredProduct->is_featured) class="size-5 shrink-0 accent-forest"><span class="min-w-0 flex-1 truncate" data-featured-name>{{ $featuredProduct->name }}</span></label>
                            @endforeach
                        </div>
                        <p data-featured-message role="status" aria-live="polite" class="min-h-5 text-sm font-medium text-danger"></p>
                        <div class="grid gap-2"><button type="button" data-featured-select-visible class="btn btn-secondary min-h-11 w-full">Görünenleri seç</button><button type="button" data-featured-clear class="btn btn-secondary min-h-11 w-full">Seçimi temizle</button><button type="submit" class="btn btn-primary min-h-11 w-full">Seçtiklerimizi kaydet</button></div>
                    </form>
                </details>
            </section>
        @endif

        <section class="card overflow-hidden" aria-labelledby="recommendation-manager-title">
            <div class="border-b border-line p-4"><div class="flex items-center gap-3"><span class="grid size-10 shrink-0 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="sparkles" class="size-5" /></span><div><h2 id="recommendation-manager-title" class="font-display text-base font-semibold text-ink">İyi gider önerileri</h2><p class="mt-1 text-xs text-muted">Ürün ve kategori önerilerini ayrı ayrı kaydet</p></div></div></div>
            <div class="max-h-[34rem] space-y-2 overflow-y-auto p-3">
                @forelse ($suggestionPool as $recommendationProduct)
                    @php($selectedSuggestions = $recommendationProduct->suggestedProductIds()->all())
                    @php($selectedSuggestionCategories = $recommendationProduct->suggestedCategoryIds()->all())
                    <details data-recommendation-details data-product-id="{{ $recommendationProduct->id }}" class="rounded-xl border border-line bg-paper">
                        <summary class="flex min-h-12 cursor-pointer list-none items-center gap-2 px-3 py-2 text-sm font-semibold text-ink"><span class="min-w-0 flex-1 truncate">{{ $recommendationProduct->name }}</span><span class="shrink-0 rounded-full bg-sage px-2 py-1 text-[10px] font-bold text-forest">{{ count($selectedSuggestions) + count($selectedSuggestionCategories) }} seçili</span></summary>
                        <form method="post" action="{{ route('manage.products.recommendations.update', [$branch, $recommendationProduct->id]) }}" class="space-y-3 border-t border-line p-3">
                            @csrf @method('PATCH')
                            <div data-suggestion-picker data-selected-suggestions="{{ implode(',', $selectedSuggestions) }}" class="rounded-xl border border-line bg-paper p-3">
                                <div class="flex items-center justify-between gap-2"><div><p class="text-sm font-bold text-ink">Sepete eklenince öner</p><p class="text-xs text-muted">Bu ürün sepete eklenince gösterilir.</p></div><span data-suggestion-count class="shrink-0 rounded-full bg-sage px-2 py-1 text-[10px] font-bold text-forest">0 seçili</span></div>
                                <div class="mt-2"><span class="label">1. Kategoriye dokun</span><div data-suggestion-product-categories role="group" aria-label="Ürün kategorileri" class="mt-1 grid max-h-36 grid-cols-2 gap-2 overflow-y-auto">
                                    @foreach ($categories as $suggestionCategory)
                                        @php($categorySuggestionCount = $suggestionPool->where('category_id', $suggestionCategory->id)->where('id', '!=', $recommendationProduct->id)->count())
                                        @if ($categorySuggestionCount > 0)<button type="button" data-suggestion-category-filter data-category-id="{{ $suggestionCategory->id }}" aria-pressed="false" class="flex min-h-11 min-w-0 items-center justify-between gap-2 rounded-xl border border-line bg-paper px-3 text-left text-sm font-semibold text-ink hover:border-forest/50"><span class="truncate">{{ $suggestionCategory->name }}</span><span class="shrink-0 rounded-full bg-cream px-2 py-0.5 text-xs text-muted">{{ $categorySuggestionCount }}</span></button>@endif
                                    @endforeach
                                </div></div>
                                <label class="mt-2 block"><span class="label">2. Ürün ara</span><input type="search" data-suggestion-search autocomplete="off" disabled class="input min-h-11 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-60" placeholder="Önce kategori seçin" aria-label="Seçili kategoride ürün ara"></label>
                                <div class="mt-2 flex gap-2"><button type="button" data-select-visible class="btn btn-secondary min-h-11 flex-1 text-xs">Bulunanları seç</button><button type="button" data-clear-selection class="btn btn-secondary min-h-11 flex-1 text-xs">Seçimi temizle</button></div>
                                <div data-suggestion-options aria-live="polite" class="mt-2 grid max-h-48 gap-1 overflow-y-auto rounded-xl bg-cream/50 p-2"><p class="col-span-full py-2 text-sm font-medium text-muted">Ürün listesi yükleniyor.</p></div>
                            </div>
                            <div data-suggestion-category-picker data-selected-categories="{{ implode(',', $selectedSuggestionCategories) }}" class="rounded-xl border border-line bg-paper p-3">
                                <div class="flex items-center justify-between gap-2"><div><p class="text-xs font-bold text-ink">Kategoriye göre öner</p><p class="text-[10px] text-muted">Bu kategorilerden ürün eklenince.</p></div><span data-suggestion-count class="shrink-0 rounded-full bg-sage px-2 py-1 text-[10px] font-bold text-forest">0 seçili</span></div>
                                <label class="mt-2 block"><span class="sr-only">Tetikleyici kategori ara</span><input type="search" data-suggestion-category-search autocomplete="off" class="input min-h-10 py-2 text-xs" placeholder="Menüde kategori ara..."></label>
                                <div class="mt-2 flex gap-2"><button type="button" data-select-visible class="btn btn-secondary btn-sm min-h-9 flex-1 text-[10px]">Bulunanları seç</button><button type="button" data-clear-selection class="btn btn-secondary btn-sm min-h-9 flex-1 text-[10px]">Seçimi temizle</button></div>
                                <div data-suggestion-category-options class="mt-2 grid max-h-32 gap-1 overflow-y-auto rounded-xl bg-cream/50 p-2 sm:grid-cols-2"><p class="col-span-full py-2 text-[11px] font-medium text-muted">Kategori listesi yükleniyor.</p></div>
                            </div>
                            <button type="submit" class="btn btn-primary min-h-11 w-full">Bu ürünün önerilerini kaydet</button>
                        </form>
                    </details>
                @empty<p class="rounded-xl bg-cream p-4 text-sm text-muted">Öneri ayarlamak için önce menüye ürün ekleyin.</p>@endforelse
            </div>
        </section>
    </div>

    <div class="mt-4 grid gap-5 xl:grid-cols-[minmax(0,1.35fr)_minmax(0,.65fr)] xl:items-start">
        <section class="min-w-0 space-y-4">
            @if ($selectedCategory)
                @php($category = $selectedCategory)
                <article class="card overflow-hidden">
                    <header class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="font-display text-xl font-semibold text-ink">{{ $category->name }}</h2>
                                <span class="badge badge-neutral">{{ $category->products_count }} ürün</span>
                                @unless ($category->is_active)
                                    <span class="badge badge-cancelled">Kapalı</span>
                                @endunless
                            </div>
                            @if ($category->description)
                                <p class="mt-1 text-xs font-medium text-muted">{{ $category->description }}</p>
                            @endif
                        </div>

                        <details class="group">
                            <summary class="chip cursor-pointer">
                                <x-ui.icon name="pencil" class="size-3.5" /> Kategori
                            </summary>
                            <div class="mt-2 w-72 rounded-2xl border border-line bg-paper p-3 shadow-lift">
                                <form method="post" action="{{ route('manage.categories.update', [$branch, $category->id]) }}" class="space-y-2">
                                    @csrf @method('PATCH')
                                    <label class="block">
                                        <span class="label">Ad</span>
                                        <input name="name" value="{{ $category->name }}" required maxlength="120" class="input py-2 text-sm">
                                    </label>
                                    <label class="block">
                                        <span class="label">Açıklama</span>
                                        <textarea name="description" rows="2" maxlength="500" class="input py-2 text-sm">{{ $category->description }}</textarea>
                                    </label>
                                    <input type="hidden" name="is_active" value="0">
                                    <label class="flex min-h-9 items-center gap-2 text-xs font-semibold text-ink">
                                        <input type="checkbox" name="is_active" value="1" @checked($category->is_active) class="size-4 accent-forest"> Kategori yayında
                                    </label>
                                    <button class="btn btn-primary btn-sm w-full">Kaydet</button>
                                </form>
                                <form method="post" action="{{ route('manage.categories.destroy', [$branch, $category->id]) }}" class="mt-2" data-confirm="{{ $category->name }} kategorisini silmek istiyor musunuz? Ürünler de menüden kalkar.">
                                    @csrf @method('DELETE')
                                    <button class="text-[11px] font-bold text-danger hover:underline">Kategoriyi sil</button>
                                </form>
                            </div>
                        </details>
                    </header>

                    <div class="divide-y divide-line">
                        @forelse ($category->products as $product)
                            <div class="p-4 sm:p-5" data-menu-product-id="{{ $product->id }}">
                                <div class="flex flex-wrap items-start gap-3">
                                    <div class="relative size-16 shrink-0 overflow-hidden rounded-xl border border-line bg-cream">
                                        @if ($product->image_url)
                                            <img src="{{ $product->image_url }}" alt="{{ $product->name }}" class="size-full object-cover" loading="lazy">
                                        @else
                                            <span class="grid size-full place-items-center text-muted"><x-ui.icon name="image" class="size-5" /></span>
                                        @endif
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-1.5">
                                            <h3 class="font-bold text-ink">{{ $product->name }}</h3>
                                            @if ($product->is_featured)
                                                <span class="badge badge-pending"><x-ui.icon name="flame" class="size-3" /> Öne çıkan</span>
                                            @endif
                                            @unless ($product->is_available)
                                                <span class="badge badge-cancelled">Satışta değil</span>
                                            @endunless
                                            @if ($product->prep_minutes > 0)
                                                <span class="badge badge-neutral"><x-ui.icon name="clock" class="size-3" /> {{ $product->prep_minutes }} dk</span>
                                            @endif
                                        </div>
                                        @if ($product->description)
                                            <p class="mt-1 line-clamp-2 max-w-xl text-xs leading-5 text-muted">{{ $product->description }}</p>
                                        @endif
                                        <p class="mt-1 text-sm font-bold text-ink">
                                            {{ \App\Services\Money::formatDecimal($product->priceFor($branch)) }}
                                            @if ($branch->uses_shared_menu)
                                                <span class="ml-1 text-[10px] font-semibold uppercase tracking-[.12em] text-muted">şube fiyatı</span>
                                            @endif
                                        </p>
                                    </div>

                                    <details class="group w-full sm:w-auto" data-recommendation-details data-product-edit-details data-product-id="{{ $product->id }}">
                                        <summary class="btn btn-secondary btn-sm w-full cursor-pointer list-none">
                                            <x-ui.icon name="pencil" class="size-4" /> Ürünü düzenle
                                        </summary>

                                        <div class="mt-3 rounded-2xl border border-line bg-cream/40 p-3">
                                            <form method="post" action="{{ route('manage.products.update', [$branch, $product->id]) }}" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-2">
                                                @csrf @method('PATCH')
                                                <label class="block sm:col-span-2">
                                                    <span class="label">Kategori</span>
                                                    <select name="category_id" required class="input py-2 text-sm">
                                                        @foreach ($categories as $categoryOption)
                                                            <option value="{{ $categoryOption->id }}" @selected($categoryOption->id === $category->id)>{{ $categoryOption->name }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                <label class="block sm:col-span-2">
                                                    <span class="label">Ürün adı</span>
                                                    <input name="name" value="{{ $product->name }}" required maxlength="120" class="input py-2 text-sm">
                                                </label>
                                                <label class="block sm:col-span-2">
                                                    <span class="label">Açıklama</span>
                                                    <textarea name="description" rows="2" maxlength="1000" class="input py-2 text-sm">{{ $product->description }}</textarea>
                                                </label>
                                                <label class="block">
                                                    <span class="label">Fiyat (₺)</span>
                                                    <input type="number" name="price" value="{{ $product->price }}" min="0" max="1000000" step="0.01" required class="input py-2 text-sm">
                                                </label>
                                                <label class="block">
                                                    <span class="label">Hazırlık süresi (dk)</span>
                                                    <input type="number" name="prep_minutes" value="{{ $product->prep_minutes }}" min="0" max="180" class="input py-2 text-sm" placeholder="0">
                                                </label>

                                                <div class="sm:col-span-2 grid gap-2 rounded-xl border border-line bg-paper p-3 sm:grid-cols-2">
                                                    <div>
                                                        <span class="label">Görsel yükle</span>
                                                        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-xs text-muted file:mr-2 file:rounded-lg file:border-0 file:bg-sage file:px-3 file:py-2 file:text-xs file:font-bold file:text-forest">
                                                    </div>
                                                    <label class="block">
                                                        <span class="label">veya görsel URL</span>
                                                        <input name="image_url" value="{{ str_starts_with((string) $product->image_path, 'http') ? $product->image_path : '' }}" class="input py-2 text-xs" placeholder="https://...">
                                                    </label>
                                                    @if ($product->image_path)
                                                        <label class="flex items-center gap-2 text-[11px] font-semibold text-danger sm:col-span-2">
                                                            <input type="checkbox" name="remove_image" value="1" class="size-3.5 accent-danger"> Mevcut görseli kaldır
                                                        </label>
                                                    @endif
                                                </div>

                                                <div class="sm:col-span-2 grid gap-2 rounded-xl border border-line bg-paper p-3 sm:grid-cols-2">
                                                    <input type="hidden" name="is_available" value="0">
                                                    <label class="flex min-h-9 items-center gap-2 text-xs font-semibold text-ink">
                                                        <input type="checkbox" name="is_available" value="1" @checked($product->is_available) class="size-4 accent-forest"> Menüde satışta
                                                    </label>
                                                </div>

                                                <div class="sm:col-span-2 flex flex-wrap items-center justify-between gap-2">
                                                    <button class="btn btn-primary btn-sm">
                                                        <x-ui.icon name="check" class="size-4" /> Ürün bilgilerini kaydet
                                                    </button>
                                                </div>
                                            </form>

                                            <div class="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-line pt-3">
                                                <span class="text-[11px] font-semibold text-muted">Ürün seçenekleri ve şube fiyatı aşağıda.</span>
                                                <form method="post" action="{{ route('manage.products.destroy', [$branch, $product->id]) }}" data-confirm="{{ $product->name }} ürününü menüden silmek istiyor musunuz?">
                                                    @csrf @method('DELETE')
                                                    <button class="text-[11px] font-bold text-danger hover:underline">Ürünü sil</button>
                                                </form>
                                            </div>

                                            @if ($branch->uses_shared_menu)
                                                @include('manage.partials.branch-product-override', ['branch' => $branch, 'product' => $product])
                                            @endif
                                            @include('manage.partials.product-option-groups', ['branch' => $branch, 'product' => $product])
                                        </div>
                                    </details>
                                </div>
                            </div>
                        @empty
                            <p class="px-5 py-8 text-center text-sm font-medium text-muted">Bu kategoride henüz ürün yok. Sağdaki formdan ekleyebilirsiniz.</p>
                        @endforelse
                    </div>
                </article>
            @else
                <div class="card p-10 text-center">
                    <span class="mx-auto grid size-14 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="utensils" class="size-6" /></span>
                    <h2 class="mt-4 font-display text-xl font-semibold text-ink">Menü boş</h2>
                    <p class="mx-auto mt-1 max-w-md text-sm leading-6 text-muted">Sağdaki formdan ilk kategoriyi oluşturun, ardından ürünleri ekleyin. İsterseniz ortak menüyü bu şubeye kopyalayabilirsiniz.</p>
                </div>
            @endif
        </section>

        <aside class="space-y-4 xl:sticky xl:top-24 xl:self-start">
            <section class="card p-4">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-lg font-semibold text-ink">Yeni kategori</h2>
                        <p class="text-[10px] font-semibold uppercase tracking-[.14em] text-muted">Menü bölümü</p>
                    </div>
                    <span class="grid size-9 place-items-center rounded-full bg-sage text-forest"><x-ui.icon name="list" class="size-4" /></span>
                </div>
                <form method="post" action="{{ route('manage.categories.store', $branch) }}" class="mt-3 space-y-2">
                    @csrf
                    <label class="block">
                        <span class="label">Kategori adı</span>
                        <input name="name" required maxlength="120" class="input py-2 text-sm" placeholder="Örn. Kahveler">
                    </label>
                    <label class="block">
                        <span class="label">Açıklama</span>
                        <textarea name="description" rows="2" maxlength="500" class="input py-2 text-sm"></textarea>
                    </label>
                    <button class="btn btn-secondary btn-sm w-full">
                        <x-ui.icon name="plus" class="size-3.5" /> Kategori ekle
                    </button>
                </form>
            </section>

            <section class="card p-4">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-lg font-semibold text-ink">Yeni ürün</h2>
                        <p class="text-[10px] font-semibold uppercase tracking-[.14em] text-muted">Menüye ekle</p>
                    </div>
                    <span class="grid size-9 place-items-center rounded-full bg-amber-soft text-[#8a5a14]"><x-ui.icon name="plus" class="size-4" /></span>
                </div>

                @if ($categories->isEmpty())
                    <p class="mt-3 rounded-xl border border-dashed border-line px-3 py-3 text-xs font-medium text-muted">Önce bir kategori oluşturun.</p>
                @else
                    <form method="post" action="{{ route('manage.products.store', $branch) }}" enctype="multipart/form-data" class="mt-3 space-y-2">
                        @csrf
                        <label class="block">
                            <span class="label">Kategori</span>
                            <select name="category_id" required class="input py-2 text-sm">
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="block">
                            <span class="label">Ürün adı</span>
                            <input name="name" required maxlength="120" value="{{ old('name') }}" class="input py-2 text-sm" placeholder="Örn. Filtre kahve">
                        </label>
                        <label class="block">
                            <span class="label">Açıklama</span>
                            <textarea name="description" rows="2" maxlength="1000" class="input py-2 text-sm">{{ old('description') }}</textarea>
                        </label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="block">
                                <span class="label">Fiyat (₺)</span>
                                <input type="number" name="price" value="{{ old('price') }}" min="0" max="1000000" step="0.01" required class="input py-2 text-sm">
                            </label>
                            <label class="block">
                                <span class="label">Hazırlık (dk)</span>
                                <input type="number" name="prep_minutes" value="{{ old('prep_minutes') }}" min="0" max="180" class="input py-2 text-sm" placeholder="0">
                            </label>
                        </div>
                        <label class="block">
                            <span class="label">Görsel</span>
                            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="block w-full text-[11px] text-muted file:mr-2 file:rounded-lg file:border-0 file:bg-sage file:px-2.5 file:py-1.5 file:text-[11px] file:font-bold file:text-forest">
                            <input name="image_url" value="{{ old('image_url') }}" class="input mt-1 py-2 text-[11px]" placeholder="veya görsel URL">
                        </label>
                        <input type="hidden" name="is_available" value="0">
                        <label class="flex min-h-9 items-center gap-2 text-xs font-semibold text-ink">
                            <input type="checkbox" name="is_available" value="1" checked class="size-4 accent-forest"> Satışta
                        </label>
                        <button class="btn btn-primary btn-sm w-full">
                            <x-ui.icon name="plus" class="size-3.5" /> Ürün ekle
                        </button>
                    </form>
                @endif
            </section>

            <section class="card overflow-hidden">
                <div class="border-b border-line px-4 py-3">
                    <h2 class="font-display text-lg font-semibold text-ink">Menü kaynağı</h2>
                    <p class="text-[10px] font-semibold uppercase tracking-[.14em] text-muted">{{ $branch->uses_shared_menu ? 'Ortak menü kullanılıyor' : 'Şubeye özel menü' }}</p>
                </div>
                <form method="post" action="{{ route('manage.branches.menu-copy', $branch) }}" class="space-y-2 p-4">
                    @csrf
                    <label class="block">
                        <span class="label">İşlem</span>
                        <select name="action" class="input py-2 text-sm">
                            <option value="shared" @selected(old('action') === 'shared')>Ortak menüyü kullan</option>
                            <option value="copy" @selected(old('action') === 'copy')>Menüyü kopyala</option>
                            <option value="empty" @selected(old('action') === 'empty')>Boş menüyle başla</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="label">Kaynak</span>
                        <select name="source" class="input py-2 text-sm">
                            <option value="shared" @selected(old('source', 'shared') === 'shared')>Kafenin ortak menüsü</option>
                            <option value="branch" @selected(old('source') === 'branch')>Başka şubenin menüsü</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="label">Kaynak şube</span>
                        <select name="source_branch_id" class="input py-2 text-sm">
                            <option value="">Seçin</option>
                            @foreach ($menuSources as $menuSource)
                                <option value="{{ $menuSource->id }}" @selected((string) old('source_branch_id') === (string) $menuSource->id)>{{ $menuSource->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($hasPrivateMenu)
                        <label class="flex items-start gap-2 rounded-xl bg-amber-soft px-3 py-2 text-[11px] font-semibold leading-4 text-[#7a4f12]">
                            <input type="checkbox" name="confirm_replace" value="1" class="mt-0.5 size-3.5 accent-forest">
                            Mevcut özel menünün değişeceğini onaylıyorum
                        </label>
                    @endif
                    <button class="btn btn-secondary btn-sm w-full">Menü ayarını uygula</button>
                </form>
            </section>

            <section class="card p-4">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <h2 class="font-display text-lg font-semibold text-ink">Şube bilgileri</h2>
                        <p class="text-[10px] font-semibold uppercase tracking-[.14em] text-muted">İletişim ve durum</p>
                    </div>
                    <span class="grid size-9 place-items-center rounded-full bg-cream text-forest"><x-ui.icon name="pin" class="size-4" /></span>
                </div>
                <form method="post" action="{{ route('manage.branches.update', $branch) }}" class="mt-3 space-y-2">
                    @csrf @method('PATCH')
                    <label class="block">
                        <span class="label">Şube adı</span>
                        <input name="name" value="{{ old('name', $branch->name) }}" required maxlength="120" class="input py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="label">Adres</span>
                        <input name="address" value="{{ old('address', $branch->address) }}" maxlength="255" class="input py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="label">Telefon</span>
                        <input name="phone" value="{{ old('phone', $branch->phone) }}" maxlength="40" class="input py-2 text-sm">
                    </label>
                    <input type="hidden" name="is_active" value="0">
                    <label class="flex min-h-9 items-center gap-2 text-xs font-semibold text-ink">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $branch->is_active)) class="size-4 accent-forest"> Şube sipariş almaya açık
                    </label>
                    <button class="btn btn-secondary btn-sm w-full">Şube bilgilerini kaydet</button>
                </form>
            </section>
        </aside>
    </div>
</x-layouts.panel>
