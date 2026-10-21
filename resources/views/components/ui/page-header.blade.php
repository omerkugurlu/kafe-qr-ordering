@props(['title', 'eyebrow' => null, 'description' => null])

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="eyebrow">{{ $eyebrow }}</p>
        @endif
        <h2 class="mt-1 font-display text-2xl font-semibold tracking-tight text-ink sm:text-3xl">{{ $title }}</h2>
        @if ($description)
            <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-muted">{{ $description }}</p>
        @endif
    </div>
    @if (! $slot->isEmpty())
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $slot }}</div>
    @endif
</div>
