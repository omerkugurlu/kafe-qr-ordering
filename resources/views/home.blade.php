<x-layouts.app title="Masada sipariş">
    <section class="grid items-center gap-12 py-8 lg:grid-cols-[1.1fr_.9fr] lg:py-20">
        <div>
            <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-leaf/15 bg-lime/35 px-4 py-2 text-xs font-bold uppercase tracking-[.13em] text-leaf"><span class="size-2 rounded-full bg-leaf"></span> Kafe deneyimi, kolayca</p>
            <h1 class="max-w-2xl font-display text-5xl font-extrabold leading-[1.06] tracking-[-.055em] sm:text-6xl">Masada siparişin <span class="text-leaf">en kolay</span> hali.</h1>
            <p class="mt-6 max-w-xl text-lg leading-8 text-muted">Menüyü keşfet, siparişini masandan ver. Kafeler için menü, masa ve servis akışı tek yerde.</p>
            <div class="mt-9 flex flex-wrap gap-3"><a href="{{ route('login') }}" class="rounded-2xl bg-leaf px-6 py-3.5 font-bold text-white shadow-lg shadow-leaf/15 transition hover:-translate-y-0.5 hover:bg-ink">Yönetim paneline giriş <span aria-hidden="true">→</span></a><span class="inline-flex items-center gap-2 rounded-2xl border border-ink/10 bg-paper px-5 py-3.5 text-sm font-semibold text-muted"><span class="text-clay">✦</span> Hızlı · Temassız · Kolay</span></div>
            <div class="mt-12 flex items-center gap-6 border-t border-ink/10 pt-6 text-sm text-muted"><span><strong class="text-ink">01</strong> Masanı tara</span><span class="text-ink/20">/</span><span><strong class="text-ink">02</strong> Sipariş ver</span><span class="text-ink/20">/</span><span><strong class="text-ink">03</strong> Keyfini çıkar</span></div>
        </div>
        <div class="relative mx-auto w-full max-w-md">
            <div class="absolute -right-5 -top-6 size-32 rounded-full bg-lime/60 blur-3xl"></div><div class="absolute -bottom-10 -left-8 size-36 rounded-full bg-clay/20 blur-3xl"></div>
            <div class="relative rounded-[2rem] border border-ink/10 bg-paper p-4 panel-shadow sm:p-6">
                <div class="flex items-center justify-between border-b border-ink/10 pb-4"><div><p class="text-xs font-bold uppercase tracking-[.15em] text-muted">Bugünün menüsü</p><p class="mt-1 font-display text-xl font-extrabold">Kıyı Kahve</p></div><span class="rounded-full bg-lime/50 px-3 py-1 text-xs font-bold text-leaf">Masa 04</span></div>
                <div class="mt-5 rounded-3xl bg-[#e8eee2] p-5"><div class="flex aspect-[1.65] items-center justify-center overflow-hidden rounded-2xl bg-[#d6e1cc]"><svg viewBox="0 0 240 140" class="h-full w-full"><ellipse cx="120" cy="118" rx="95" ry="12" fill="#b4c5a7"/><path d="M66 73h108l-10 43H76L66 73Z" fill="#285c47"/><path d="M56 68c0-5 4-9 9-9h108c5 0 9 4 9 9v5H56v-5Z" fill="#d5ee8b"/><path d="M74 59c5-27 29-41 51-41s46 14 51 41H74Z" fill="#f7f6f0"/><path d="M118 18v42" stroke="#d97c54" stroke-width="5" stroke-linecap="round"/><circle cx="120" cy="72" r="3" fill="#fffefa"/></svg></div><p class="mt-4 text-xs font-bold uppercase tracking-[.15em] text-leaf">Barista favorisi</p><div class="mt-1 flex items-center justify-between"><span class="font-display text-lg font-bold">Buzlu latte</span><span class="font-bold">₺120</span></div></div>
                <div class="mt-4 flex gap-3"><div class="flex-1 rounded-2xl border border-ink/10 px-4 py-3"><p class="text-xs text-muted">Taze hazırlandı</p><p class="mt-1 text-sm font-bold">Özenle, şimdi</p></div><div class="grid size-12 place-items-center rounded-2xl bg-ink text-lime"><svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M13 6l6 6-6 6"/></svg></div></div>
                <div class="absolute -left-8 top-1/2 hidden -translate-y-1/2 rounded-2xl bg-paper px-4 py-3 shadow-xl sm:block"><span class="block text-xs text-muted">Masa siparişi</span><span class="mt-1 flex items-center gap-2 font-bold"><span class="size-2 rounded-full bg-green-500"></span> Garsona iletildi</span></div>
            </div>
        </div>
    </section>
</x-layouts.app>
