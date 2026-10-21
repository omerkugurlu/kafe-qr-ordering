<div class="mt-3 rounded-2xl border border-ink/10 bg-paper p-4" data-option-editor>
    @if($errors->any())<p role="alert" class="mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs font-semibold text-red-800">{{ $errors->first() }}</p>@endif
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
            <h4 class="text-sm font-extrabold">Ürün seçenekleri</h4>
            <p class="mt-1 text-xs text-muted">Ücretsiz ekler veya ücretli tercihler ekleyin. Örnek: Karamelli ₺0 · Çikolatalı +₺30</p>
        </div>
        @if ($product->optionGroups->isEmpty())<span class="rounded-full bg-cream px-3 py-1 text-[11px] font-bold text-muted">Seçenek yok</span>@endif
    </div>

    @foreach ($product->optionGroups as $group)
        <div class="mb-3 rounded-xl bg-cream/60 p-3">
            <form method="post" action="{{ route('manage.products.option-groups.update', [$branch, $product->id, $group->id]) }}" class="space-y-3" data-options-form data-next-index="{{ $group->options->count() }}">
                @csrf @method('PATCH')
                <div class="grid gap-2 sm:grid-cols-[1.2fr_1fr_.7fr_.7fr_auto] sm:items-end">
                    <label class="text-xs font-bold">Grup adı<input name="name" value="{{ $group->name }}" required maxlength="80" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
                    <label class="text-xs font-bold">Seçim türü<select name="selection_mode" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm"><option value="single" @selected($group->selection_mode === 'single')>Tek seçim</option><option value="multiple" @selected($group->selection_mode === 'multiple')>Çoklu seçim</option></select></label>
                    <label class="text-xs font-bold">En az<input type="number" name="min_selections" value="{{ $group->min_selections }}" min="0" max="10" required class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
                    <label class="text-xs font-bold">En fazla<input type="number" name="max_selections" value="{{ $group->max_selections }}" min="1" max="10" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
                    <label class="flex min-h-10 items-center gap-2 text-xs font-bold"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($group->is_active) class="accent-leaf"> Grup açık</label>
                </div>
                <div class="space-y-2" data-option-list>
                    @foreach ($group->options as $index => $option)
                        <div class="grid gap-2 rounded-lg border border-ink/5 bg-paper p-2 sm:grid-cols-[1.5fr_.8fr_auto] sm:items-end">
                            <input type="hidden" name="options[{{ $index }}][id]" value="{{ $option->id }}">
                            <label class="text-[11px] font-bold">Seçenek adı<input name="options[{{ $index }}][name]" value="{{ $option->name }}" required maxlength="80" class="mt-1 w-full rounded-md border border-ink/15 px-3 py-2 text-sm"></label>
                            <label class="text-[11px] font-bold">Fiyat farkı (₺)<input type="number" name="options[{{ $index }}][price_delta]" value="{{ $option->price_delta }}" min="0" max="1000000" step="0.01" required class="mt-1 w-full rounded-md border border-ink/15 px-3 py-2 text-sm"></label>
                            <label class="flex min-h-9 items-center gap-2 text-xs font-semibold"><input type="hidden" name="options[{{ $index }}][is_available]" value="0"><input type="checkbox" name="options[{{ $index }}][is_available]" value="1" @checked($option->is_available) class="accent-leaf"> Satışta</label>
                        </div>
                    @endforeach
                </div>
                <template data-option-row-template>
                    <div class="grid gap-2 rounded-lg border border-ink/5 bg-paper p-2 sm:grid-cols-[1.5fr_.8fr_auto] sm:items-end">
                        <input type="hidden" name="options[__INDEX__][id]" value="">
                        <label class="text-[11px] font-bold">Seçenek adı<input name="options[__INDEX__][name]" required maxlength="80" class="mt-1 w-full rounded-md border border-ink/15 px-3 py-2 text-sm" placeholder="Örn. Yulaf sütü"></label>
                        <label class="text-[11px] font-bold">Fiyat farkı (₺)<input type="number" name="options[__INDEX__][price_delta]" value="0.00" min="0" max="1000000" step="0.01" required class="mt-1 w-full rounded-md border border-ink/15 px-3 py-2 text-sm"></label>
                        <label class="flex min-h-9 items-center gap-2 text-xs font-semibold"><input type="hidden" name="options[__INDEX__][is_available]" value="0"><input type="checkbox" name="options[__INDEX__][is_available]" value="1" checked class="accent-leaf"> Satışta</label>
                    </div>
                </template>
                <div class="flex flex-wrap justify-between gap-2">
                    <button type="button" data-add-option-row class="rounded-lg border border-ink/10 px-3 py-2 text-xs font-bold">+ Seçenek ekle</button>
                    <div class="flex gap-2"><button class="rounded-lg bg-leaf px-3 py-2 text-xs font-bold text-white">Seçenekleri kaydet</button></div>
                </div>
            </form>
            <form method="post" action="{{ route('manage.products.option-groups.destroy', [$branch, $product->id, $group->id]) }}" class="mt-2" onsubmit="return confirm('Bu seçenek grubunu menüden kaldıralım mı? Eski siparişler korunur.')">
                @csrf @method('DELETE')
                <button class="text-xs font-semibold text-clay">Grubu menüden kaldır</button>
            </form>
        </div>
    @endforeach

    <details class="rounded-xl border border-dashed border-ink/15 p-3">
        <summary class="cursor-pointer list-none text-sm font-bold text-leaf">+ Yeni seçenek grubu ekle</summary>
        <form method="post" action="{{ route('manage.products.option-groups.store', [$branch, $product->id]) }}" class="mt-3 space-y-3" data-options-form data-next-index="2">
            @csrf
            <div class="grid gap-2 sm:grid-cols-[1.2fr_1fr_.7fr_.7fr]">
                <label class="text-xs font-bold">Grup adı<input name="name" required maxlength="80" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm" placeholder="Lezzet seçimi"></label>
                <label class="text-xs font-bold">Seçim türü<select name="selection_mode" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm"><option value="single">Tek seçim</option><option value="multiple">Çoklu seçim</option></select></label>
                <label class="text-xs font-bold">En az<input type="number" name="min_selections" value="0" min="0" max="10" required class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
                <label class="text-xs font-bold">En fazla<input type="number" name="max_selections" value="1" min="1" max="10" class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
            </div>
            <input type="hidden" name="is_active" value="1">
            <div class="space-y-2" data-option-list>
                @foreach ([0 => 'Karamelli', 1 => 'Çikolatalı'] as $index => $name)
                    <div class="grid gap-2 rounded-lg border border-ink/5 bg-cream/60 p-2 sm:grid-cols-[1.5fr_.8fr_auto] sm:items-end">
                        <input type="hidden" name="options[{{ $index }}][id]" value="">
                        <label class="text-[11px] font-bold">Seçenek adı<input name="options[{{ $index }}][name]" value="{{ $name }}" required maxlength="80" class="mt-1 w-full rounded-md border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
                        <label class="text-[11px] font-bold">Fiyat farkı (₺)<input type="number" name="options[{{ $index }}][price_delta]" value="{{ $index === 0 ? '0.00' : '30.00' }}" min="0" max="1000000" step="0.01" required class="mt-1 w-full rounded-md border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
                        <label class="flex min-h-9 items-center gap-2 text-xs font-semibold"><input type="hidden" name="options[{{ $index }}][is_available]" value="0"><input type="checkbox" name="options[{{ $index }}][is_available]" value="1" checked class="accent-leaf"> Satışta</label>
                    </div>
                @endforeach
            </div>
            <template data-option-row-template>
                <div class="grid gap-2 rounded-lg border border-ink/5 bg-cream/60 p-2 sm:grid-cols-[1.5fr_.8fr_auto] sm:items-end">
                    <input type="hidden" name="options[__INDEX__][id]" value="">
                    <label class="text-[11px] font-bold">Seçenek adı<input name="options[__INDEX__][name]" required maxlength="80" class="mt-1 w-full rounded-md border border-ink/15 bg-paper px-3 py-2 text-sm" placeholder="Yeni seçenek"></label>
                    <label class="text-[11px] font-bold">Fiyat farkı (₺)<input type="number" name="options[__INDEX__][price_delta]" value="0.00" min="0" max="1000000" step="0.01" required class="mt-1 w-full rounded-md border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
                    <label class="flex min-h-9 items-center gap-2 text-xs font-semibold"><input type="hidden" name="options[__INDEX__][is_available]" value="0"><input type="checkbox" name="options[__INDEX__][is_available]" value="1" checked class="accent-leaf"> Satışta</label>
                </div>
            </template>
            <button type="button" data-add-option-row class="rounded-lg border border-ink/10 px-3 py-2 text-xs font-bold">+ Seçenek ekle</button>
            <button class="w-full rounded-lg bg-ink px-3 py-2.5 text-sm font-bold text-white">Bu grubu ürüne ekle</button>
        </form>
    </details>
</div>
