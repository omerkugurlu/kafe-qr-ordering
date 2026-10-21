@if (session('status'))
    <div {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-2xl border border-success/20 bg-success-soft px-4 py-3 text-sm font-semibold text-forest']) }} role="status">
        <x-ui.icon name="check-circle" class="mt-0.5 size-5 shrink-0" />
        <p>{{ session('status') }}</p>
    </div>
@endif

@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'flex items-start gap-3 rounded-2xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm text-danger']) }} role="alert">
        <x-ui.icon name="alert" class="mt-0.5 size-5 shrink-0" />
        <div>
            <p class="font-bold">Bilgileri kontrol edip tekrar deneyin.</p>
            <ul class="mt-1.5 list-inside list-disc space-y-0.5 text-[13px] font-medium">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
