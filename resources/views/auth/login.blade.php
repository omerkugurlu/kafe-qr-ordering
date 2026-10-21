<x-layouts.app title="Giriş yap">
    <div class="mx-auto grid max-w-5xl overflow-hidden rounded-[2rem] border border-line bg-paper shadow-lift md:grid-cols-[.95fr_1.05fr]">
        <aside class="relative hidden overflow-hidden bg-forest p-10 text-white md:flex md:flex-col md:justify-between">
            <div class="pointer-events-none absolute -right-24 -top-24 size-80 rounded-full border-[56px] border-white/5"></div>
            <div class="pointer-events-none absolute -bottom-28 -left-20 size-72 rounded-full bg-amber/10 blur-2xl"></div>

            <div class="relative">
                <span class="grid size-12 place-items-center rounded-2xl bg-white/10 text-amber ring-1 ring-white/15">
                    <x-ui.icon name="chef" class="size-6" />
                </span>
                <p class="mt-12 text-[11px] font-bold uppercase tracking-[.22em] text-amber">İşletme yönetimi</p>
                <h1 class="mt-4 font-display text-4xl font-semibold leading-[1.08]">Servis akışını<br>tek ekrandan<br>yönet.</h1>
                <p class="mt-5 max-w-xs text-sm leading-6 text-white/65">Menüden masaya, siparişten ödemeye. Garson, mutfak ve kasa aynı akışta buluşur.</p>
            </div>

            <div class="relative grid gap-3 text-sm">
                <div class="flex items-center gap-3 rounded-2xl bg-white/5 px-4 py-3 ring-1 ring-white/10">
                    <x-ui.icon name="qr" class="size-5 shrink-0 text-amber" />
                    <span class="font-medium text-white/80">Her masa için ayrı QR menü</span>
                </div>
                <div class="flex items-center gap-3 rounded-2xl bg-white/5 px-4 py-3 ring-1 ring-white/10">
                    <x-ui.icon name="bell" class="size-5 shrink-0 text-amber" />
                    <span class="font-medium text-white/80">Canlı sipariş ve servis takibi</span>
                </div>
            </div>
        </aside>

        <div class="p-7 sm:p-10 md:p-12">
            <p class="text-[11px] font-bold uppercase tracking-[.2em] text-terracotta">Hoş geldiniz</p>
            <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-ink sm:text-4xl">Hesabına giriş yap</h2>
            <p class="mt-3 text-sm leading-6 text-muted">Yönetim ve servis paneline devam et.</p>

            <form class="mt-8 space-y-5" method="post" action="{{ route('login.store') }}">
                @csrf

                <label class="block">
                    <span class="label">E-posta adresi</span>
                    <input
                        type="email"
                        name="email"
                        autocomplete="username"
                        required
                        autofocus
                        value="{{ old('email') }}"
                        class="input"
                        placeholder="ornek@kafe.com"
                    >
                </label>

                <label class="block">
                    <span class="label">Şifre</span>
                    <input
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                        class="input"
                        placeholder="Şifrenizi girin"
                    >
                </label>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="flex cursor-pointer items-center gap-2 text-sm font-medium text-muted">
                        <input type="checkbox" name="remember" value="1" class="size-4 accent-forest">
                        Beni hatırla
                    </label>
                    <a href="{{ route('password.request') }}" class="text-sm font-semibold text-forest hover:underline">Şifremi unuttum</a>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    Giriş yap
                    <x-ui.icon name="arrow-right" class="size-4" />
                </button>
            </form>

            <p class="mt-7 border-t border-line pt-5 text-xs leading-5 text-muted">
                Giriş bilgilerini kafe yöneticinizden alabilirsiniz. Müşteri hesabı oluşturmak için masanızdaki QR kodu okutun.
            </p>
        </div>
    </div>
</x-layouts.app>
