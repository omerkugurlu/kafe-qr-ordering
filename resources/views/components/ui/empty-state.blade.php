@props(['icon' => 'info', 'title', 'description' => null])

<div {{ $attributes->merge(['class' => 'flex flex-col items-center justify-center rounded-3xl border border-dashed border-line bg-paper/60 px-6 py-12 text-center']) }}>
    <span class="grid size-12 place-items-center rounded-2xl bg-cream text-muted">
        <x-ui.icon :name="$icon" class="size-6" />
    </span>
    <p class="mt-4 font-display text-lg font-semibold text-ink">{{ $title }}</p>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-muted">{{ $description }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
