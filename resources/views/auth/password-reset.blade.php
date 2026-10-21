<x-layouts.app title="Yeni şifre belirle">
    <div class="mx-auto max-w-lg">
        <div class="card p-6 sm:p-10">
            <p class="text-[11px] font-bold uppercase tracking-[.18em] text-terracotta">Hesap güvenliği</p>
            <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight text-ink">Yeni şifre belirle</h1>

            <form method="post" action="{{ route('password.update') }}" class="mt-7 space-y-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <label class="block">
                    <span class="label">E-posta</span>
                    <input type="email" name="email" required autocomplete="email" value="{{ old('email') }}" class="input">
                </label>

                <label class="block">
                    <span class="label">Yeni şifre <span class="font-medium normal-case tracking-normal text-muted/80">(en az 12 karakter)</span></span>
                    <input type="password" name="password" minlength="12" required autocomplete="new-password" class="input">
                </label>

                <label class="block">
                    <span class="label">Yeni şifreyi tekrar yazın</span>
                    <input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password" class="input">
                </label>

                <button type="submit" class="btn btn-primary w-full">Şifreyi güncelle</button>
            </form>
        </div>
    </div>
</x-layouts.app>
