@php
    $override = $product->branches->firstWhere('id', $branch->id)?->pivot;
@endphp
<form method="post" action="{{ route('manage.products.branch-update', [$branch, $product->id]) }}" class="grid gap-2 rounded-xl border border-leaf/20 bg-lime/10 p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
    @csrf
    @method('PATCH')
    <label class="text-xs font-bold">Şube fiyatı (₺)<input type="number" name="price" value="{{ $override?->price ?? $product->price }}" min="0" max="1000000" step="0.01" required class="mt-1 w-full rounded-lg border border-ink/15 bg-paper px-3 py-2 text-sm"></label>
    <div class="flex flex-wrap items-center gap-3">
        <input type="hidden" name="override_enabled" value="0">
        <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="override_enabled" value="1" @checked($override !== null) class="accent-leaf"> Şubeye özel ayar</label>
        <input type="hidden" name="is_available" value="0">
        <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_available" value="1" @checked($override?->is_available ?? $product->is_available) class="accent-leaf"> Bu şubede satışta</label>
    </div>
    <button class="rounded-lg border border-leaf/30 px-3 py-2 text-xs font-bold text-leaf">Şube ayarını kaydet</button>
    <p class="text-xs text-muted sm:col-span-3">Şubeye özel ayar kapalıysa ortak fiyat ve bulunurluk kullanılır.</p>
</form>
