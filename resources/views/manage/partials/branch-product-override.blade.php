@php
    $override = $product->branches->firstWhere('id', $branch->id)?->pivot;
@endphp
<form method="post" action="{{ route('manage.products.branch-update', [$branch, $product->id]) }}" class="grid gap-2 rounded-xl border border-forest/15 bg-sage/30 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
    @csrf
    @method('PATCH')
    <label class="block">
        <span class="label">Şube fiyatı (₺)</span>
        <input type="number" name="price" value="{{ $override?->price ?? $product->price }}" min="0" max="1000000" step="0.01" required class="input py-2 text-sm">
    </label>
    <div class="flex flex-wrap items-center gap-3 pb-1">
        <input type="hidden" name="override_enabled" value="0">
        <label class="flex items-center gap-1.5 text-xs font-semibold text-ink">
            <input type="checkbox" name="override_enabled" value="1" @checked($override !== null) class="size-4 accent-forest"> Şubeye özel
        </label>
        <input type="hidden" name="is_available" value="0">
        <label class="flex items-center gap-1.5 text-xs font-semibold text-ink">
            <input type="checkbox" name="is_available" value="1" @checked($override?->is_available ?? true) class="size-4 accent-forest"> Satışta
        </label>
    </div>
    <button class="btn btn-secondary btn-sm">Şube fiyatını kaydet</button>
</form>
