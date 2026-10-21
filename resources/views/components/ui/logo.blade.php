@props(['compact' => false, 'href' => null])

@php($link = $href ?? url('/'))

<a href="{{ $link }}" {{ $attributes->merge(['class' => 'group flex items-center gap-3']) }} aria-label="SkyKite Masa ana sayfa">
    <span class="relative grid size-10 shrink-0 place-items-center rounded-2xl bg-forest text-amber shadow-sm transition-transform duration-300 group-hover:-rotate-6">
        <svg viewBox="0 0 24 24" class="size-6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M12 2 4 8l8 14 8-14-8-6Z" />
            <path d="M4 8h16M12 2v20" opacity=".35" />
            <path d="M9.5 10.5c0-1 .8-1.6 1.6-1.6M14.5 10.5c0-1-.8-1.6-1.6-1.6" opacity=".8" />
        </svg>
    </span>
    @unless ($compact)
        <span class="leading-none">
            <span class="block font-display text-lg font-semibold tracking-tight text-ink">SkyKite</span>
            <span class="mt-1 block text-[9px] font-bold uppercase tracking-[.28em] text-muted">Masa Sipariş</span>
        </span>
    @endunless
</a>
