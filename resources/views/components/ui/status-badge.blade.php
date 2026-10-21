@props(['status', 'label' => null])

@php
    [$class, $text] = match ($status) {
        'pending' => ['badge-pending', 'Alındı'],
        'accepted' => ['badge-preparing', 'Hazırlanıyor'],
        'ready' => ['badge-ready', 'Hazır'],
        'served' => ['badge-served', 'Teslim edildi'],
        'paid' => ['badge-paid', 'Ödendi'],
        'cancelled' => ['badge-cancelled', 'İptal'],
        default => ['badge-neutral', $status],
    };
@endphp

<span {{ $attributes->merge(['class' => $class]) }}>
    <span class="size-1.5 rounded-full bg-current opacity-70"></span>
    {{ $label ?? $text }}
</span>
