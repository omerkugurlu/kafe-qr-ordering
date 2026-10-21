<x-layouts.kitchen :title="$branch->name . ' · Mutfak'" :branch="$branch">
    <div
        data-kitchen-board
        data-endpoint="{{ $dataEndpoint }}"
        data-status-url="{{ $statusUrlTemplate }}"
        class="min-h-dvh"
    >
        <header class="sticky top-0 z-30 border-b border-white/10 bg-[#14100d]/95 backdrop-blur-xl">
            <div class="mx-auto flex max-w-[1600px] flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
                <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-bold uppercase tracking-[.24em] text-amber">{{ $branch->cafe->name }}</p>
                    <h1 class="mt-0.5 truncate font-display text-2xl font-semibold text-cream">{{ $branch->name }} · Mutfak</h1>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($branches->count() > 1)
                        @foreach ($branches as $branchOption)
                            <a
                                href="{{ route('staff.kitchen', $branchOption) }}"
                                @class([
                                    'inline-flex min-h-9 items-center rounded-full border px-3 py-1.5 text-xs font-bold transition',
                                    'border-amber bg-amber text-ink' => $branchOption->id === $branch->id,
                                    'border-white/15 text-cream/70 hover:border-white/30 hover:text-cream' => $branchOption->id !== $branch->id,
                                ])
                            >{{ $branchOption->name }}</a>
                        @endforeach
                    @endif

                    <span class="hidden items-center gap-2 rounded-full border border-white/10 px-3 py-1.5 text-[11px] font-bold text-cream/70 sm:inline-flex">
                        <span class="relative flex size-2">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-success opacity-60"></span>
                            <span class="relative inline-flex size-2 rounded-full bg-success"></span>
                        </span>
                        Canlı
                    </span>
                    <span class="font-mono text-sm font-semibold tabular-nums text-cream/80" data-kitchen-clock>--:--</span>
                    <button type="button" class="inline-flex min-h-9 items-center gap-2 rounded-full border border-white/15 px-3 py-1.5 text-xs font-bold text-cream transition hover:border-white/30" data-sound-toggle aria-pressed="true">
                        <x-ui.icon name="bell" class="size-4" />
                        <span data-sound-label>Ses açık</span>
                    </button>
                    <button type="button" class="inline-flex min-h-9 items-center gap-2 rounded-full border border-white/15 px-3 py-1.5 text-xs font-bold text-cream transition hover:border-white/30" data-kitchen-fullscreen>
                        <x-ui.icon name="grid" class="size-4" />
                        Tam ekran
                    </button>
                    @if (auth()->user()?->role === \App\Models\User::ROLE_WAITER || auth()->user()?->role === \App\Models\User::ROLE_KITCHEN)
                        <a href="{{ route('staff.branch', $branch) }}" class="inline-flex min-h-9 items-center gap-2 rounded-full bg-amber px-3 py-1.5 text-xs font-bold text-ink transition hover:bg-[#f0b45f]">
                            <x-ui.icon name="receipt" class="size-4" />
                            Pano
                        </a>
                    @endif
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-[1600px] px-4 py-5 sm:px-6">
            <div class="grid gap-4 lg:grid-cols-3">
                @foreach ([
                    ['key' => 'pending', 'title' => 'Yeni siparişler', 'hint' => 'Onay bekliyor', 'dot' => 'bg-amber'],
                    ['key' => 'accepted', 'title' => 'Pişiyor', 'hint' => 'Hazırlanıyor', 'dot' => 'bg-terracotta'],
                    ['key' => 'ready', 'title' => 'Hazır', 'hint' => 'Servis bekliyor', 'dot' => 'bg-success'],
                ] as $lane)
                    <section class="rounded-3xl border border-white/10 bg-white/[.035]">
                        <header class="flex items-center justify-between gap-2 border-b border-white/10 px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="size-2 rounded-full {{ $lane['dot'] }}"></span>
                                <h2 class="font-display text-xl font-semibold text-cream">{{ $lane['title'] }}</h2>
                            </div>
                            <span class="grid min-w-8 place-items-center rounded-full bg-white/10 px-2 py-1 text-xs font-bold text-cream" data-lane-count="{{ $lane['key'] }}">0</span>
                        </header>
                        <p class="px-4 pt-2 text-[10px] font-bold uppercase tracking-[.2em] text-cream/40">{{ $lane['hint'] }}</p>
                        <div class="min-h-[24rem] space-y-3 p-3" data-lane-body="{{ $lane['key'] }}"></div>
                    </section>
                @endforeach
            </div>
            <p class="mt-4 text-center text-[11px] font-medium text-cream/40">
                Son güncelleme: <span data-updated>şimdi</span> · Yeni siparişlerde sesli uyarı çalar.
            </p>
        </main>
    </div>
</x-layouts.kitchen>
