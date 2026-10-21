<x-layouts.app title="{{ $branch->name }} · Siparişler">
    <div class="mb-7 flex flex-wrap items-end justify-between gap-4"><div><a href="{{ route('staff.orders') }}" class="text-sm font-bold text-leaf">← Şubelerim</a><p class="mt-5 text-xs font-bold uppercase tracking-[.18em] text-clay">{{ $branch->cafe->name }}</p><h1 class="mt-1 font-display text-4xl font-extrabold">{{ $branch->name }}</h1></div><a href="{{ route('staff.orders') }}" class="rounded-xl border border-ink/10 bg-paper px-4 py-2.5 text-sm font-bold">Şube değiştir</a></div>
    <livewire:staff.order-board :branch-id="$branch->id" />
</x-layouts.app>
