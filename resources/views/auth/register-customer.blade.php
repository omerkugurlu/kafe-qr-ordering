<x-layouts.customer :title="$cafe->name . ' · ' . __('customer.register')" :cafe="$cafe">
    <div class="mx-auto grid min-h-dvh w-full max-w-5xl items-center gap-6 px-4 py-8 sm:px-6 lg:grid-cols-[.9fr_1.1fr]">
        <section class="relative hidden overflow-hidden rounded-[2rem] bg-forest p-10 text-white lg:block">
            <div class="pointer-events-none absolute -right-24 -top-24 size-80 rounded-full border-[56px] border-white/5"></div>
            <div class="pointer-events-none absolute -bottom-28 -left-20 size-72 rounded-full bg-amber/10 blur-2xl"></div>
            <div class="relative flex h-full flex-col justify-between">
                <div>
                    @if ($cafe->logo_url)
                        <img src="{{ $cafe->logo_url }}" alt="" class="size-14 rounded-2xl object-cover ring-1 ring-white/25">
                    @else
                        <span class="grid size-14 place-items-center rounded-2xl bg-white/10 text-amber ring-1 ring-white/20">
                            <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 4 8l8 14 8-14-8-6Z"/><path d="M4 8h16M12 2v20" opacity=".35"/></svg>
                        </span>
                    @endif
                    <p class="mt-10 text-[11px] font-bold uppercase tracking-[.22em] text-amber">{{ $cafe->brand_name ?: $cafe->name }}</p>
                    <h1 class="mt-3 font-display text-4xl font-semibold leading-[1.05]">{{ __('customer.loyalty_teaser_title') }}</h1>
                    <p class="mt-4 max-w-xs text-sm leading-6 text-white/65">{{ __('customer.loyalty_teaser_body') }}</p>
                </div>

                <div class="mt-10 grid gap-3">
                    <div class="flex items-center gap-3 rounded-2xl bg-white/5 px-4 py-3 text-sm ring-1 ring-white/10">
                        <x-ui.icon name="star" class="size-5 shrink-0 text-amber" />
                        <span class="font-medium text-white/80">{{ __('customer.loyalty_teaser_title') }}</span>
                    </div>
                    <div class="flex items-center gap-3 rounded-2xl bg-white/5 px-4 py-3 text-sm ring-1 ring-white/10">
                        <x-ui.icon name="receipt" class="size-5 shrink-0 text-amber" />
                        <span class="font-medium text-white/80">{{ __('customer.login_hint') }}</span>
                    </div>
                </div>
            </div>
        </section>

        <section class="card p-6 sm:p-10">
            <a href="{{ route('customer.menu', $token) }}" class="inline-flex items-center gap-2 text-sm font-bold text-forest hover:underline">
                <x-ui.icon name="arrow-left" class="size-4" />
                {{ __('customer.back_to_menu') }}
            </a>
            @if (auth()->check())
                <form method="post" action="{{ route('logout') }}" class="mt-4">
                    @csrf
                    <button type="submit" class="btn btn-secondary min-h-11">{{ __('customer.logout') }}</button>
                </form>
            @endif

            <p class="mt-6 text-[11px] font-bold uppercase tracking-[.18em] text-terracotta">{{ $cafe->name }}</p>
            <h2 class="mt-2 font-display text-3xl font-semibold tracking-tight text-ink">{{ __('customer.register') }}</h2>
            <p class="mt-2 text-sm leading-6 text-muted">{{ __('customer.login_hint') }}</p>

            @if ($errors->any())
                <div role="alert" class="mt-5 rounded-2xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm font-semibold text-danger">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="post" action="{{ route('customer.register.store', $token) }}" class="mt-6 space-y-4">
                @csrf

                <label class="block">
                    <span class="label">Ad soyad</span>
                    <input name="name" value="{{ old('name') }}" maxlength="120" required autofocus autocomplete="name" class="input" placeholder="Ad Soyad">
                </label>

                <label class="block">
                    <span class="label">{{ __('customer.email') }}</span>
                    <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email" class="input" placeholder="ornek@mail.com">
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="label">{{ __('customer.password') }}</span>
                        <input type="password" name="password" minlength="12" required autocomplete="new-password" class="input" placeholder="En az 12 karakter">
                    </label>

                    <label class="block">
                        <span class="label">Şifreyi tekrar yazın</span>
                        <input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password" class="input" placeholder="••••••••••••">
                    </label>
                </div>

                <button type="submit" class="btn btn-primary w-full">
                    {{ __('customer.register') }}
                    <x-ui.icon name="arrow-right" class="size-4" />
                </button>
            </form>

            <p class="mt-6 border-t border-line pt-5 text-center text-sm text-muted">
                {{ __('customer.have_account') }}
                <a href="{{ route('customer.login', $token) }}" class="font-bold text-forest hover:underline">{{ __('customer.login') }}</a>
            </p>
        </section>
    </div>
</x-layouts.customer>
