<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $branch->cafe->name }} · {{ $table->name }} QR</title>
    @vite(['resources/css/app.css'])
    <style>
        @media print {
            .print-hidden { display: none !important; }
            body { background: #fff !important; }
            .qr-card { box-shadow: none !important; border: 0 !important; }
        }
    </style>
</head>
<body class="grid min-h-screen place-items-center bg-cream p-5">
    <main class="qr-card w-full max-w-sm rounded-[2rem] border border-line bg-paper p-8 text-center shadow-soft">
        <div class="flex justify-center">
            <x-ui.logo />
        </div>

        <p class="mt-6 text-[11px] font-bold uppercase tracking-[.2em] text-forest">{{ $branch->cafe->name }}</p>
        <h1 class="mt-1 font-display text-3xl font-semibold text-ink">{{ $table->name }}</h1>
        <p class="mt-2 text-sm leading-6 text-muted">Menüyü görmek ve sipariş vermek için okutun.</p>

        <img
            src="{{ route('manage.tables.qr', [$branch, $table]) }}"
            alt="{{ $table->name }} için QR kod"
            width="280"
            height="280"
            class="mx-auto mt-5 w-full max-w-[280px] rounded-2xl"
        >

        <p class="mt-3 break-all rounded-xl bg-cream px-3 py-2 font-mono text-[10px] leading-4 text-muted">{{ route('customer.menu', $table->token()) }}</p>
        <p class="mt-6 text-xs font-semibold text-muted">Menü ve masada sipariş · SkyKite</p>

        <button onclick="window.print()" class="btn btn-primary print-hidden mt-6 w-full">
            <x-ui.icon name="printer" class="size-4" />
            Yazdır
        </button>
    </main>
</body>
</html>
