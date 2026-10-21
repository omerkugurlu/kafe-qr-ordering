<x-layouts.app title="{{ $branch->name }} · Yönetim">
    <div class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <a href="{{ route('manage.dashboard') }}" class="inline-flex min-h-11 items-center text-sm font-bold text-leaf">← İşletme paneli</a>
            <p class="mt-4 text-xs font-bold uppercase tracking-[.18em] text-clay">Şube yönetimi</p>
            <h1 class="mt-1 font-display text-3xl font-extrabold tracking-tight sm:text-4xl">{{ $branch->name }}</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-muted">Şube bilgilerini, çalışma şeklini ve menüyü buradan yönetin.</p>
        </div>
        <a href="{{ route('manage.tables.index', $branch) }}" class="inline-flex min-h-12 items-center justify-center rounded-xl bg-leaf px-5 py-3 text-center font-bold text-white transition hover:bg-ink">Masalar ve QR kodları <span class="ml-2" aria-hidden="true">→</span></a>
    </div>

    <form method="post" action="{{ route('manage.branches.update', $branch) }}" class="mb-6 rounded-3xl border border-ink/10 bg-paper p-5 panel-shadow sm:p-7">
        @csrf
        @method('PATCH')
        <div class="mb-5">
            <h2 class="font-display text-xl font-extrabold">Şube bilgileri</h2>
            <p class="mt-1 text-sm text-muted">Adres ve iletişim bilgisini müşterilerinize gösterin.</p>
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <label class="block text-sm font-bold">Şube adı
                <input name="name" maxlength="120" required value="{{ old('name', $branch->name) }}" class="mt-2 min-h-12 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3 outline-none focus:border-leaf">
            </label>
            <label class="block text-sm font-bold">Adres
                <input name="address" maxlength="255" value="{{ old('address', $branch->address) }}" class="mt-2 min-h-12 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3 outline-none focus:border-leaf">
            </label>
            <label class="block text-sm font-bold">Telefon <span class="font-normal text-muted">(isteğe bağlı)</span>
                <input name="phone" maxlength="40" value="{{ old('phone', $branch->phone) }}" class="mt-2 min-h-12 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3 outline-none focus:border-leaf">
            </label>
            <label class="flex min-h-12 items-center gap-3 rounded-xl border border-ink/10 bg-cream/40 px-4 py-3 text-sm font-bold">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $branch->is_active)) class="size-5 accent-leaf">
                Şube sipariş almaya açık
            </label>
        </div>
        <div class="mt-5 flex justify-end">
            <button class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-ink px-5 py-3 font-bold text-white transition hover:bg-leaf sm:w-auto">Şube bilgilerini kaydet</button>
        </div>
    </form>

    <section class="mb-7 overflow-hidden rounded-3xl border border-ink/10 bg-ink text-white panel-shadow">
        <div class="border-b border-white/10 px-5 py-5 sm:px-7 sm:py-6">
            <p class="text-xs font-bold uppercase tracking-[.18em] text-lime">Menü çalışma şekli</p>
            <div class="mt-2 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h2 class="font-display text-2xl font-extrabold">Bu şubenin menüsünü seçin</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-white/65">Ortak menüde yapılan değişiklikler tüm şubelere yansır. Şubeye özel menüde düzenlemeler yalnızca burada görünür.</p>
                </div>
                <span class="inline-flex w-fit items-center gap-2 rounded-full bg-white/10 px-3 py-2 text-xs font-bold">
                    <span class="size-2 rounded-full {{ $branch->uses_shared_menu ? 'bg-lime' : 'bg-clay' }}"></span>
                    Şu an: {{ $branch->uses_shared_menu ? 'Ortak menü' : 'Şubeye özel menü' }}
                </span>
            </div>
        </div>

        <form method="post" action="{{ route('manage.branches.menu-copy', $branch) }}" class="space-y-5 p-5 sm:p-7">
            @csrf
            <fieldset>
                <legend class="mb-3 text-sm font-bold text-white/80">Ne yapmak istiyorsunuz?</legend>
                <div class="grid gap-3 lg:grid-cols-3">
                    <label class="flex min-h-24 cursor-pointer gap-3 rounded-2xl border border-white/15 bg-white/5 p-4 transition hover:border-lime/70 hover:bg-white/10">
                        <input type="radio" name="action" value="shared" @checked(old('action') === 'shared') required class="mt-1 size-5 shrink-0 accent-lime">
                        <span><span class="block font-bold">Ortak menüyü kullan</span><span class="mt-1 block text-sm leading-5 text-white/60">Şubeye özel kopyanız saklanır. İstediğiniz zaman tekrar açabilirsiniz.</span></span>
                    </label>
                    <label class="flex min-h-24 cursor-pointer gap-3 rounded-2xl border border-white/15 bg-white/5 p-4 transition hover:border-lime/70 hover:bg-white/10">
                        <input type="radio" name="action" value="copy" @checked(old('action') === 'copy') class="mt-1 size-5 shrink-0 accent-lime">
                        <span><span class="block font-bold">Bir menüyü kopyala</span><span class="mt-1 block text-sm leading-5 text-white/60">Kopya bağımsız olur; sonrasında ürünleri serbestçe düzenlersiniz.</span></span>
                    </label>
                    <label class="flex min-h-24 cursor-pointer gap-3 rounded-2xl border border-white/15 bg-white/5 p-4 transition hover:border-lime/70 hover:bg-white/10">
                        <input type="radio" name="action" value="empty" @checked(old('action') === 'empty') class="mt-1 size-5 shrink-0 accent-lime">
                        <span><span class="block font-bold">Boş menüyle başla</span><span class="mt-1 block text-sm leading-5 text-white/60">Kategorileri ve ürünleri bu şube için sıfırdan eklersiniz.</span></span>
                    </label>
                </div>
            </fieldset>

            <div class="grid gap-4 rounded-2xl border border-white/10 bg-white/5 p-4 sm:grid-cols-2 sm:p-5">
                <label class="block text-sm font-bold">Kopyalanacak menü
                    <select name="source" class="mt-2 min-h-12 w-full rounded-xl border border-white/15 bg-ink px-4 py-3 text-white outline-none focus:border-lime">
                        <option value="shared" @selected(old('source', 'shared') === 'shared')>Kafenin ortak menüsü</option>
                        <option value="branch" @selected(old('source') === 'branch')>Başka bir şubenin mevcut menüsü</option>
                    </select>
                    <span class="mt-2 block text-xs leading-5 text-white/55">Kaynak şube ortak menü kullanıyorsa kopyasında o şubenin etkin fiyatları alınır.</span>
                </label>
                <label class="block text-sm font-bold">Kaynak şube
                    <select name="source_branch_id" class="mt-2 min-h-12 w-full rounded-xl border border-white/15 bg-ink px-4 py-3 text-white outline-none focus:border-lime">
                        <option value="">Şube menüsünü kopyalayacaksan seç</option>
                        @foreach ($menuSources as $menuSource)
                            <option value="{{ $menuSource->id }}" @selected((string) old('source_branch_id') === (string) $menuSource->id)>{{ $menuSource->name }}{{ $menuSource->uses_shared_menu ? ' · ortak menü' : ' · özel menü' }}</option>
                        @endforeach
                    </select>
                    @error('source_branch_id')<span class="mt-2 block text-sm font-bold text-lime">{{ $message }}</span>@enderror
                </label>
            </div>

            @if ($hasPrivateMenu)
                <label class="flex cursor-pointer items-start gap-3 rounded-2xl border border-clay/35 bg-clay/10 p-4 text-sm leading-6">
                    <input type="checkbox" name="confirm_replace" value="1" @checked(old('confirm_replace')) class="mt-1 size-5 shrink-0 accent-lime">
                    <span><span class="font-bold">Mevcut özel menüyü değiştireceğimi anlıyorum.</span><span class="block text-white/65">Kopyalama veya boş menü seçerseniz bu şubenin mevcut kategorileri ve ürünleri yenilenir. Eski siparişlerin ürün ve fiyat kayıtları korunur. “Ortak menü” seçeneği özel menünüzü silmez.</span></span>
                </label>
                @error('confirm_replace')<p role="alert" class="text-sm font-bold text-lime">{{ $message }}</p>@enderror
            @endif

            @error('action')<p role="alert" class="text-sm font-bold text-lime">{{ $message }}</p>@enderror
            @error('source')<p role="alert" class="text-sm font-bold text-lime">{{ $message }}</p>@enderror

            <button class="inline-flex min-h-12 w-full items-center justify-center rounded-xl bg-lime px-5 py-3 font-extrabold text-ink transition hover:bg-white sm:w-auto">Menü ayarını uygula <span class="ml-2" aria-hidden="true">→</span></button>
        </form>
    </section>

    <div class="grid gap-6 lg:grid-cols-[minmax(17rem,.75fr)_minmax(0,1.25fr)]">
        <section class="space-y-6">
            <div class="rounded-3xl border border-ink/10 bg-paper p-5 panel-shadow sm:p-6">
                <div><p class="text-xs font-bold uppercase tracking-[.14em] text-clay">Adım 1</p><h2 class="mt-1 font-display text-xl font-extrabold">Kategori ekle</h2></div>
                <form method="post" action="{{ route('manage.categories.store', $branch) }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="block text-sm font-bold">Kategori adı
                        <input name="name" maxlength="120" required class="mt-2 min-h-12 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3" placeholder="Örn. Kahveler">
                    </label>
                    <label class="block text-sm font-bold">Açıklama <span class="font-normal text-muted">(isteğe bağlı)</span>
                        <textarea name="description" maxlength="500" rows="2" class="mt-2 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3"></textarea>
                    </label>
                    <button class="min-h-12 w-full rounded-xl bg-leaf px-4 py-3 font-bold text-white">Kategori ekle</button>
                </form>
            </div>

            <div class="rounded-3xl border border-ink/10 bg-paper p-5 panel-shadow sm:p-6">
                <div><p class="text-xs font-bold uppercase tracking-[.14em] text-clay">Adım 2</p><h2 class="mt-1 font-display text-xl font-extrabold">Ürün ekle</h2></div>
                <form method="post" action="{{ route('manage.products.store', $branch) }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="block text-sm font-bold">Kategori
                        <select name="category_id" required class="mt-2 min-h-12 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3">
                            <option value="">Kategori seçin</option>
                            @foreach ($categories->where('is_active', true) as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach
                        </select>
                    </label>
                    <label class="block text-sm font-bold">Ürün adı
                        <input name="name" maxlength="120" required class="mt-2 min-h-12 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3" placeholder="Örn. Filtre kahve">
                    </label>
                    <label class="block text-sm font-bold">Açıklama
                        <textarea name="description" maxlength="1000" rows="2" class="mt-2 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3"></textarea>
                    </label>
                    <label class="block text-sm font-bold">Fiyat (₺)
                        <input type="number" name="price" min="0" max="1000000" step="0.01" required class="mt-2 min-h-12 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3">
                    </label>
                    <label class="flex min-h-11 items-center gap-2 text-sm font-bold"><input type="checkbox" name="is_available" value="1" checked class="size-5 accent-leaf"> Satışta</label>
                    <button class="min-h-12 w-full rounded-xl bg-leaf px-4 py-3 font-bold text-white">Ürünü ekle</button>
                </form>
            </div>
        </section>

        <section class="min-w-0 rounded-3xl border border-ink/10 bg-paper p-5 panel-shadow sm:p-7">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div><p class="text-xs font-bold uppercase tracking-[.14em] text-clay">Menü içerikleri</p><h2 class="mt-1 font-display text-2xl font-extrabold">Düzenleme</h2><p class="mt-1 text-sm text-muted">{{ $categories->count() }} kategori · {{ $categories->sum('products_count') }} ürün</p></div>
                <span class="inline-flex w-fit items-center rounded-full bg-lime/45 px-3 py-2 text-xs font-bold text-leaf">{{ $branch->uses_shared_menu ? 'Ortak menü' : 'Şubeye özel menü' }}</span>
            </div>

            <div class="mt-6 space-y-5">
                @forelse ($categories as $category)
                    <article class="min-w-0 rounded-2xl border border-ink/10 p-4 sm:p-5">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div class="min-w-0"><h3 class="font-display text-xl font-extrabold">{{ $category->name }}</h3>@if($category->description)<p class="mt-1 text-sm text-muted">{{ $category->description }}</p>@endif</div>
                            <form method="post" action="{{ route('manage.categories.update', [$branch, $category->id]) }}" class="flex flex-wrap items-center gap-2">
                                @csrf @method('PATCH')
                                <input type="hidden" name="name" value="{{ $category->name }}"><input type="hidden" name="description" value="{{ $category->description }}"><input type="hidden" name="is_active" value="0">
                                <label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($category->is_active) class="size-5 accent-leaf"> Aktif</label>
                                <button class="min-h-11 rounded-lg border border-ink/10 px-3 py-2 text-sm font-bold">Kaydet</button>
                            </form>
                        </div>
                        <div class="mt-4 space-y-3">
                            @forelse($category->products as $product)
                                <form method="post" action="{{ route('manage.products.update', [$branch, $product->id]) }}" class="grid min-w-0 gap-3 rounded-xl bg-cream/65 p-3 sm:grid-cols-[minmax(8rem,1fr)_minmax(10rem,1.5fr)_minmax(6rem,.7fr)_auto] sm:items-end">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="category_id" value="{{ $category->id }}">
                                    <label class="min-w-0 text-xs font-bold">Ürün<input name="name" value="{{ $product->name }}" maxlength="120" required class="mt-1 min-h-11 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
                                    <label class="min-w-0 text-xs font-bold">Açıklama<input name="description" value="{{ $product->description }}" maxlength="1000" class="mt-1 min-h-11 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
                                    <label class="min-w-0 text-xs font-bold">Fiyat<input type="number" name="price" value="{{ $product->price }}" min="0" max="1000000" step="0.01" required class="mt-1 min-h-11 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
                                    <div class="flex min-h-11 items-center gap-2">
                                        <input type="hidden" name="is_available" value="0">
                                        <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_available" value="1" @checked($product->is_available) class="size-5 accent-leaf"> Satışta</label>
                                        <button class="min-h-11 rounded-lg bg-ink px-3 py-2 text-xs font-bold text-white">Kaydet</button>
                                    </div>
                                </form>
                                @if($branch->uses_shared_menu)@include('manage.partials.branch-product-override', ['branch' => $branch, 'product' => $product])@endif
                                @include('manage.partials.product-option-groups', ['branch' => $branch, 'product' => $product])
                            @empty
                                <p class="rounded-xl bg-cream p-4 text-sm text-muted">Bu kategoride henüz ürün yok.</p>
                            @endforelse
                        </div>
                    </article>
                @empty
                    <div class="rounded-2xl border border-dashed border-ink/20 bg-cream/30 p-8 text-center sm:p-12"><span class="text-3xl" aria-hidden="true">☕</span><p class="mt-3 font-bold">Menünüzü oluşturmaya başlayın</p><p class="mt-1 text-sm text-muted">Soldan kategori oluşturun, ardından ürünlerinizi ekleyin.</p></div>
                @endforelse
            </div>
        </section>
    </div>
</x-layouts.app>
