<x-layouts.app title="Şifre yenileme">
    <div class="mx-auto max-w-lg">
        <div class="card p-6 sm:p-10">
            <a href="{{ route('login') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-forest hover:underline">
                <x-ui.icon name="arrow-left" class="size-4" />
                Girişe dön
            </a>

            <p class="mt-7 text-[11px] font-bold uppercase tracking-[.18em] text-terracotta">Hesap güvenliği</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight text-ink">Şifreni yenile</h1>
            <p class="mt-3 text-sm leading-6 text-muted">Hesabına bağlı e-posta adresini yaz. E-posta kayıtlıysa yenileme bağlantısı gönderilir.</p>

            <form method="post" action="{{ route('password.email') }}" class="mt-7 space-y-4">
                @csrf
                <label class="block">
                    <span class="label">E-posta</span>
                    <input type="email" name="email" required autocomplete="email" value="{{ old('email') }}" class="input">
                </label>
                <button type="submit" class="btn btn-primary w-full">Yenileme bağlantısı gönder</button>
            </form>
        </div>
    </div>
</x-layouts.app>
