<x-layouts.app title="Masada sipariş">
    <section class="grid items-center gap-12 pb-10 pt-4 lg:grid-cols-[1.05fr_.95fr] lg:gap-16 lg:pb-20 lg:pt-10">
        <div>
            <p class="inline-flex items-center gap-2 rounded-full border border-forest/15 bg-sage/60 px-4 py-2 text-[11px] font-bold uppercase tracking-[.16em] text-forest">
                <span class="size-1.5 rounded-full bg-forest"></span>
                Kafe deneyimi, kolayca
            </p>

            <h1 class="mt-6 max-w-2xl font-display text-5xl font-semibold leading-[1.02] tracking-[-.03em] text-ink sm:text-6xl">
                Masada siparişin
                <span class="relative whitespace-nowrap text-forest">
                    en kolay
                    <svg class="absolute -bottom-2 left-0 h-3 w-full text-amber/70" viewBox="0 0 200 12" preserveAspectRatio="none" aria-hidden="true">
                        <path d="M2 9c40-6 92-8 196-4" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" />
                    </svg>
                </span>
                hali.
            </h1>

            <p class="mt-6 max-w-xl text-lg leading-8 text-muted">
                Menüyü keşfet, siparişini masandan ver. Kafeler için menü, masa, servis ve kasa akışı tek yerde.
            </p>

            <div class="mt-9 flex flex-wrap items-center gap-3">
                <a href="{{ route('login') }}" class="btn btn-primary">
                    Yönetim paneline giriş
                    <x-ui.icon name="arrow-right" class="size-4" />
                </a>
                <a href="#nasil-calisir" class="btn btn-secondary">Nasıl çalışır?</a>
            </div>

            <div class="mt-12 flex flex-wrap items-center gap-x-6 gap-y-3 border-t border-line pt-6 text-sm text-muted">
                <span class="inline-flex items-center gap-2"><x-ui.icon name="check-circle" class="size-4 text-forest" /> Temassız sipariş</span>
                <span class="inline-flex items-center gap-2"><x-ui.icon name="check-circle" class="size-4 text-forest" /> Canlı servis takibi</span>
                <span class="inline-flex items-center gap-2"><x-ui.icon name="check-circle" class="size-4 text-forest" /> Tek ekranda yönetim</span>
            </div>
        </div>

        <div class="relative mx-auto w-full max-w-md">
            <div class="pointer-events-none absolute -right-10 -top-10 size-44 rounded-full bg-amber/20 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-12 -left-10 size-48 rounded-full bg-sage/70 blur-3xl"></div>

            <div class="relative overflow-hidden rounded-[2.25rem] border border-line bg-paper p-4 shadow-lift sm:p-5">
                <div class="flex items-center justify-between gap-3 border-b border-line pb-4">
                    <div class="flex items-center gap-3">
                        <span class="grid size-10 place-items-center rounded-2xl bg-forest text-amber">
                            <x-ui.icon name="coffee" class="size-5" />
                        </span>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.16em] text-muted">Bugünün menüsü</p>
                            <p class="font-display text-lg font-semibold leading-tight text-ink">Kıyı Kahve</p>
                        </div>
                    </div>
                    <span class="badge bg-amber-soft text-ink">Masa 04</span>
                </div>

                <div class="mt-4 overflow-hidden rounded-3xl bg-cream">
                    <div class="relative h-48 overflow-hidden">
                        <img
                            src="https://images.unsplash.com/photo-1509042239860-f550ce710b93?auto=format&fit=crop&w=900&q=80"
                            alt="Taze hazırlanmış latte"
                            class="size-full object-cover"
                            loading="lazy"
                        >
                        <div class="absolute inset-0 bg-gradient-to-t from-ink/60 via-ink/5 to-transparent"></div>
                        <span class="absolute left-4 top-4 rounded-full bg-paper/95 px-3 py-1 text-[10px] font-bold uppercase tracking-[.12em] text-forest">Barista favorisi</span>
                    </div>
                    <div class="p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="font-display text-lg font-semibold text-ink">Buzlu latte</p>
                                <p class="mt-0.5 text-xs text-muted">Çift shot espresso · soğuk süt</p>
                            </div>
                            <span class="text-sm font-bold text-forest">₺120</span>
                        </div>
                        <div class="mt-3 flex items-center justify-between gap-3 rounded-2xl border border-line bg-paper px-3 py-2.5">
                            <span class="inline-flex items-center gap-2 text-xs font-semibold text-ink-soft">
                                <span class="relative flex size-2">
                                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-50"></span>
                                    <span class="relative inline-flex size-2 rounded-full bg-success"></span>
                                </span>
                                Garsona iletildi
                            </span>
                            <span class="text-[11px] font-medium text-muted">2 dk önce</span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                    <div class="rounded-2xl bg-cream px-2 py-3">
                        <p class="font-display text-lg font-semibold text-ink">4</p>
                        <p class="text-[10px] font-semibold uppercase tracking-[.1em] text-muted">Masa</p>
                    </div>
                    <div class="rounded-2xl bg-cream px-2 py-3">
                        <p class="font-display text-lg font-semibold text-ink">12</p>
                        <p class="text-[10px] font-semibold uppercase tracking-[.1em] text-muted">Sipariş</p>
                    </div>
                    <div class="rounded-2xl bg-cream px-2 py-3">
                        <p class="font-display text-lg font-semibold text-ink">₺1.240</p>
                        <p class="text-[10px] font-semibold uppercase tracking-[.1em] text-muted">Adisyon</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="nasil-calisir" class="scroll-mt-8 border-t border-line pt-12 lg:pt-16">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-[11px] font-bold uppercase tracking-[.18em] text-terracotta">Nasıl çalışır?</p>
                <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Üç adımda masadan mutfağa.</h2>
            </div>
            <p class="max-w-sm text-sm leading-6 text-muted">Müşteri QR kodu okutur, sipariş garsona ve mutfağa anında düşer. Ödeme adisyon üzerinden kapanır.</p>
        </div>

        <div class="mt-8 grid gap-4 md:grid-cols-3">
            <article class="card p-6">
                <span class="grid size-11 place-items-center rounded-2xl bg-sage text-forest">
                    <x-ui.icon name="qr" class="size-5" />
                </span>
                <p class="mt-5 text-[10px] font-bold uppercase tracking-[.16em] text-muted">01 · Tara</p>
                <h3 class="mt-1 font-display text-xl font-semibold text-ink">Masaya özel menü</h3>
                <p class="mt-2 text-sm leading-6 text-muted">Her masanın kendi QR kodu ve menü bağlantısı vardır. Müşteri uygulama kurmadan menüye ulaşır.</p>
            </article>

            <article class="card p-6">
                <span class="grid size-11 place-items-center rounded-2xl bg-amber-soft text-ink">
                    <x-ui.icon name="receipt" class="size-5" />
                </span>
                <p class="mt-5 text-[10px] font-bold uppercase tracking-[.16em] text-muted">02 · Sipariş</p>
                <h3 class="mt-1 font-display text-xl font-semibold text-ink">Sepet ve onay</h3>
                <p class="mt-2 text-sm leading-6 text-muted">Ürün seçenekleri, notlar ve öneriler sepette toplanır. Toplam tutar onay ekranında net görünür.</p>
            </article>

            <article class="card p-6">
                <span class="grid size-11 place-items-center rounded-2xl bg-terracotta-soft text-terracotta">
                    <x-ui.icon name="bell" class="size-5" />
                </span>
                <p class="mt-5 text-[10px] font-bold uppercase tracking-[.16em] text-muted">03 · Servis</p>
                <h3 class="mt-1 font-display text-xl font-semibold text-ink">Canlı takip</h3>
                <p class="mt-2 text-sm leading-6 text-muted">Garson ve mutfak siparişi anında görür. Müşteri durumu ve adisyonu kendi ekranından izler.</p>
            </article>
        </div>
    </section>

    <section class="mt-12 overflow-hidden rounded-[2rem] bg-forest px-6 py-10 text-white sm:px-10 lg:mt-16">
        <div class="flex flex-wrap items-center justify-between gap-6">
            <div class="max-w-xl">
                <p class="text-[11px] font-bold uppercase tracking-[.18em] text-amber">SkyKite Masa Sipariş</p>
                <h2 class="mt-2 font-display text-3xl font-semibold leading-tight sm:text-4xl">Kafenizin servis akışını bugün kurun.</h2>
                <p class="mt-3 text-sm leading-6 text-white/65">Menü, masa, personel ve raporlar tek panelde. Kurulum için kafe hesabınızla giriş yapın.</p>
            </div>
            <a href="{{ route('login') }}" class="btn btn-amber">
                Panele giriş yap
                <x-ui.icon name="arrow-right" class="size-4" />
            </a>
        </div>
    </section>
</x-layouts.app>
