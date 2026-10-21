<div class="mt-3 rounded-2xl border border-line bg-cream/40 p-3" data-option-editor>
    @if ($errors->any())
        <p role="alert" class="mb-3 rounded-xl bg-danger-soft px-3 py-2 text-xs font-semibold text-danger">{{ $errors->first() }}</p>
    @endif

    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h4 class="text-xs font-bold uppercase tracking-[.14em] text-terracotta">Ürün seçenekleri</h4>
            <p class="mt-1 text-[11px] leading-4 text-muted">Ücretsiz ekler veya ücretli tercihler. Örn. Karamelli ₺0 · Çikolatalı +₺30</p>
        </div>
        @if ($product->optionGroups->isEmpty())
            <span class="badge badge-neutral">Seçenek yok</span>
        @endif
    </div>

    @foreach ($product->optionGroups as $group)
        <div class="mb-3 rounded-xl border border-line bg-paper p-3">
            <form method="post" action="{{ route('manage.products.option-groups.update', [$branch, $product->id, $group->id]) }}" class="space-y-3" data-options-form data-next-index="{{ $group->options->count() }}">
                @csrf @method('PATCH')
                <div class="grid gap-2 sm:grid-cols-[1.2fr_1fr_.7fr_.7fr_auto] sm:items-end">
                    <label class="block">
                        <span class="label">Grup adı</span>
                        <input name="name" value="{{ $group->name }}" required maxlength="80" class="input py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="label">Seçim türü</span>
                        <select name="selection_mode" class="input py-2 text-sm">
                            <option value="single" @selected($group->selection_mode === 'single')>Tek seçim</option>
                            <option value="multiple" @selected($group->selection_mode === 'multiple')>Çoklu seçim</option>
                        </select>
                    </label>
                    <label class="block">
                        <span class="label">En az</span>
                        <input type="number" name="min_selections" value="{{ $group->min_selections }}" min="0" max="10" required class="input py-2 text-sm">
                    </label>
                    <label class="block">
                        <span class="label">En fazla</span>
                        <input type="number" name="max_selections" value="{{ $group->max_selections }}" min="1" max="10" class="input py-2 text-sm">
                    </label>
                    <label class="flex min-h-10 items-center gap-2 text-xs font-bold text-ink">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked($group->is_active) class="size-4 accent-forest"> Grup açık
                    </label>
                </div>

                <div class="space-y-2" data-option-list>
                    @foreach ($group->options as $index => $option)
                        <div class="grid gap-2 rounded-xl border border-line bg-cream/40 p-2 sm:grid-cols-[1.5fr_.8fr_auto] sm:items-end">
                            <input type="hidden" name="options[{{ $index }}][id]" value="{{ $option->id }}">
                            <label class="block">
                                <span class="label">Seçenek adı</span>
                                <input name="options[{{ $index }}][name]" value="{{ $option->name }}" required maxlength="80" class="input py-2 text-sm">
                            </label>
                            <label class="block">
                                <span class="label">Fiyat farkı (₺)</span>
                                <input type="number" name="options[{{ $index }}][price_delta]" value="{{ $option->price_delta }}" min="0" max="1000000" step="0.01" required class="input py-2 text-sm">
                            </label>
                            <label class="flex min-h-10 items-center gap-2 text-xs font-semibold text-ink">
                                <input type="hidden" name="options[{{ $index }}][is_available]" value="0">
                                <input type="checkbox" name="options[{{ $index }}][is_available]" value="1" @checked($option->is_available) class="size-4 accent-forest"> Satışta
                            </label>
                        </div>
                    @endforeach
                </div>

                <template data-option-row-template>
                    <div class="grid gap-2 rounded-xl border border-line bg-cream/40 p-2 sm:grid-cols-[1.5fr_.8fr_auto] sm:items-end">
                        <input type="hidden" name="options[__INDEX__][id]" value="">
                        <label class="block">
                            <span class="label">Seçenek adı</span>
                            <input name="options[__INDEX__][name]" required maxlength="80" class="input py-2 text-sm" placeholder="Örn. Yulaf sütü">
                        </label>
                        <label class="block">
                            <span class="label">Fiyat farkı (₺)</span>
                            <input type="number" name="options[__INDEX__][price_delta]" value="0.00" min="0" max="1000000" step="0.01" required class="input py-2 text-sm">
                        </label>
                        <label class="flex min-h-10 items-center gap-2 text-xs font-semibold text-ink">
                            <input type="hidden" name="options[__INDEX__][is_available]" value="0">
                            <input type="checkbox" name="options[__INDEX__][is_available]" value="1" checked class="size-4 accent-forest"> Satışta
                        </label>
                    </div>
                </template>

                <div class="flex flex-wrap items-center justify-between gap-2">
                    <button type="button" data-add-option-row class="btn btn-ghost btn-sm">
                        <x-ui.icon name="plus" class="size-3.5" /> Seçenek ekle
                    </button>
                    <button class="btn btn-primary btn-sm">Seçenekleri kaydet</button>
                </div>
            </form>

            <form method="post" action="{{ route('manage.products.option-groups.destroy', [$branch, $product->id, $group->id]) }}" class="mt-2" data-confirm="Bu seçenek grubunu menüden kaldıralım mı? Eski siparişler korunur.">
                @csrf @method('DELETE')
                <button class="text-[11px] font-bold text-danger hover:underline">Grubu menüden kaldır</button>
            </form>
        </div>
    @endforeach

    <details class="rounded-xl border border-dashed border-line p-3">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-2 text-xs font-bold text-forest">
            <span class="flex items-center gap-1.5"><x-ui.icon name="plus" class="size-3.5" /> Yeni seçenek grubu</span>
            <x-ui.icon name="chevron-down" class="size-4" />
        </summary>
        <form method="post" action="{{ route('manage.products.option-groups.store', [$branch, $product->id]) }}" class="mt-3 space-y-3" data-options-form data-next-index="2">
            @csrf
            <div class="grid gap-2 sm:grid-cols-[1.2fr_1fr_.7fr_.7fr]">
                <label class="block">
                    <span class="label">Grup adı</span>
                    <input name="name" required maxlength="80" class="input py-2 text-sm" placeholder="Lezzet seçimi">
                </label>
                <label class="block">
                    <span class="label">Seçim türü</span>
                    <select name="selection_mode" class="input py-2 text-sm">
                        <option value="single">Tek seçim</option>
                        <option value="multiple">Çoklu seçim</option>
                    </select>
                </label>
                <label class="block">
                    <span class="label">En az</span>
                    <input type="number" name="min_selections" value="0" min="0" max="10" required class="input py-2 text-sm">
                </label>
                <label class="block">
                    <span class="label">En fazla</span>
                    <input type="number" name="max_selections" value="1" min="1" max="10" class="input py-2 text-sm">
                </label>
            </div>
            <input type="hidden" name="is_active" value="1">
            <div class="space-y-2" data-option-list>
                @foreach ([0 => 'Karamelli', 1 => 'Çikolatalı'] as $index => $name)
                    <div class="grid gap-2 rounded-xl border border-line bg-cream/40 p-2 sm:grid-cols-[1.5fr_.8fr_auto] sm:items-end">
                        <input type="hidden" name="options[{{ $index }}][id]" value="">
                        <label class="block">
                            <span class="label">Seçenek adı</span>
                            <input name="options[{{ $index }}][name]" value="{{ $name }}" required maxlength="80" class="input py-2 text-sm">
                        </label>
                        <label class="block">
                            <span class="label">Fiyat farkı (₺)</span>
                            <input type="number" name="options[{{ $index }}][price_delta]" value="{{ $index === 0 ? '0.00' : '30.00' }}" min="0" max="1000000" step="0.01" required class="input py-2 text-sm">
                        </label>
                        <label class="flex min-h-10 items-center gap-2 text-xs font-semibold text-ink">
                            <input type="hidden" name="options[{{ $index }}][is_available]" value="0">
                            <input type="checkbox" name="options[{{ $index }}][is_available]" value="1" checked class="size-4 accent-forest"> Satışta
                        </label>
                    </div>
                @endforeach
            </div>
            <template data-option-row-template>
                <div class="grid gap-2 rounded-xl border border-line bg-cream/40 p-2 sm:grid-cols-[1.5fr_.8fr_auto] sm:items-end">
                    <input type="hidden" name="options[__INDEX__][id]" value="">
                    <label class="block">
                        <span class="label">Seçenek adı</span>
                        <input name="options[__INDEX__][name]" required maxlength="80" class="input py-2 text-sm" placeholder="Yeni seçenek">
                    </label>
                    <label class="block">
                        <span class="label">Fiyat farkı (₺)</span>
                        <input type="number" name="options[__INDEX__][price_delta]" value="0.00" min="0" max="1000000" step="0.01" required class="input py-2 text-sm">
                    </label>
                    <label class="flex min-h-10 items-center gap-2 text-xs font-semibold text-ink">
                        <input type="hidden" name="options[__INDEX__][is_available]" value="0">
                        <input type="checkbox" name="options[__INDEX__][is_available]" value="1" checked class="size-4 accent-forest"> Satışta
                    </label>
                </div>
            </template>
            <button type="button" data-add-option-row class="btn btn-ghost btn-sm">
                <x-ui.icon name="plus" class="size-3.5" /> Seçenek ekle
            </button>
            <button class="btn btn-primary w-full btn-sm">Bu grubu ürüne ekle</button>
        </form>
    </details>
</div>
