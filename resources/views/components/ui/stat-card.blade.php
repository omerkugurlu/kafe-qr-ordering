@props(['label', 'value', 'hint' => null, 'icon' => 'chart', 'tone' => 'forest'])

@php
    $toneClass = match ($tone) {
        'amber' => 'bg-amber-soft text-[#8a5a14]',
        'terracotta' => 'bg-terracotta-soft text-[#8f3d22]',
        'success' => 'bg-success-soft text-success',
        'danger' => 'bg-danger-soft text-danger',
        'sage' => 'bg-sage text-forest',
        default => 'bg-forest text-white',
    };
@endphp

<div {{ $attributes->merge(['class' => 'card flex items-start gap-4 p-5']) }}>
    <span class="grid size-11 shrink-0 place-items-center rounded-2xl {{ $toneClass }}">
        <x-ui.icon :name="$icon" class="size-5" />
    </span>
    <div class="min-w-0 flex-1">
        <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">{{ $label }}</p>
        <p class="mt-1 font-display text-2xl font-semibold tracking-tight text-ink">{{ $value }}</p>
        @if ($hint)
            <p class="mt-1 text-xs font-medium text-muted">{{ $hint }}</p>
        @endif
    </div>
</div>
