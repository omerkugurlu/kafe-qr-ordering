<x-layouts.panel
    title="Duyurular"
    :subtitle="$cafe->name . ' · menü duyuruları, etkinlikler ve kampanyalar'"
    active="announcements"
>
    @php
        $activeCount = $announcements->filter(fn ($announcement) => $announcement->is_active)->count();
    @endphp

    <div class="grid gap-4 sm:grid-cols-3">
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Toplam duyuru</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $announcements->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Kafenin tüm kayıtları</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Yayında</p>
            <p class="mt-2 font-display text-3xl font-semibold text-success">{{ $activeCount }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Müşteri menüsünde görünür</p>
        </article>
        <article class="kpi">
            <p class="text-[11px] font-bold uppercase tracking-[.16em] text-muted">Hedef</p>
            <p class="mt-2 font-display text-3xl font-semibold text-ink">{{ $announcements->whereNull('branch_id')->count() }}</p>
            <p class="mt-1 text-xs font-medium text-muted">Tüm şubelere açık duyuru</p>
        </article>
    </div>

    @if ($errors->any())
        <div role="alert" class="mt-5 rounded-2xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm font-semibold text-danger">
            {{ $errors->first() }}
        </div>
    @endif

    <div class="mt-5 grid gap-5 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,.9fr)]">
        <div class="space-y-3">
            @forelse ($announcements as $announcement)
                <article class="card overflow-hidden">
                    <div class="flex flex-wrap items-start justify-between gap-3 p-5">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="badge badge-neutral">{{ $announcement->typeLabel() }}</span>
                                @if ($announcement->is_active)
                                    <span class="badge badge-ready">Yayında</span>
                                @else
                                    <span class="badge badge-cancelled">Kapalı</span>
                                @endif
                                <span class="badge badge-neutral">
                                    <x-ui.icon name="pin" class="size-3" />
                                    {{ $announcement->branch?->name ?? 'Tüm şubeler' }}
                                </span>
                            </div>
                            <h2 class="mt-3 font-display text-2xl font-semibold text-ink">{{ $announcement->title }}</h2>
                            @if ($announcement->body)
                                <p class="mt-1 line-clamp-3 text-sm leading-6 text-muted">{{ $announcement->body }}</p>
                            @endif
                            <p class="mt-2 text-[11px] font-medium text-muted">
                                @if ($announcement->starts_at || $announcement->ends_at)
                                    {{ $announcement->starts_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? 'Hemen' }}
                                    –
                                    {{ $announcement->ends_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i') ?? 'Süresiz' }}
                                @else
                                    Tarih sınırı yok
                                @endif
                                · {{ $announcement->created_at->timezone('Europe/Istanbul')->format('d.m.Y H:i') }}
                            </p>
                        </div>
                        <span class="grid size-11 shrink-0 place-items-center rounded-full {{ $announcement->is_active ? 'bg-amber-soft text-[#8a5a14]' : 'bg-cream text-muted' }}">
                            <x-ui.icon name="bell" class="size-5" />
                        </span>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 border-t border-line px-5 py-3">
                        <a href="{{ route('manage.announcements.index', ['duzenle' => $announcement->id]) }}" class="btn btn-secondary btn-sm">
                            <x-ui.icon name="pencil" class="size-3.5" />
                            Düzenle
                        </a>
                        <form method="post" action="{{ route('manage.announcements.toggle', $announcement) }}">
                            @csrf @method('PATCH')
                            <button class="btn btn-secondary btn-sm">{{ $announcement->is_active ? 'Yayından kaldır' : 'Yayına al' }}</button>
                        </form>
                        <form method="post" action="{{ route('manage.announcements.destroy', $announcement) }}" class="ml-auto" data-confirm="“{{ $announcement->title }}” duyurusunu silmek istiyor musunuz?">
                            @csrf @method('DELETE')
                            <button class="text-xs font-bold text-danger hover:underline">Sil</button>
                        </form>
                    </div>
                </article>
            @empty
                <div class="card p-10 text-center">
                    <span class="mx-auto grid size-14 place-items-center rounded-full bg-sage text-forest">
                        <x-ui.icon name="bell" class="size-6" />
                    </span>
                    <h2 class="mt-4 font-display text-2xl font-semibold text-ink">Henüz duyuru yok</h2>
                    <p class="mt-1 text-sm text-muted">İlk duyurunuzu ekleyerek müşterilerinize haber verin.</p>
                </div>
            @endforelse
        </div>

        <aside class="card h-fit p-5">
            <p class="eyebrow">{{ $editing ? 'Duyuruyu düzenle' : 'Yeni duyuru' }}</p>
            <h2 class="mt-1 font-display text-2xl font-semibold text-ink">{{ $editing ? $editing->title : 'Menüye duyuru ekle' }}</h2>
            <p class="mt-1 text-xs leading-5 text-muted">Duyurular müşteri menüsünün üst bölümünde gösterilir. Tüm şubelere veya tek bir şubeye hedefleyebilirsiniz.</p>

            <form method="post" action="{{ $editing ? route('manage.announcements.update', $editing) : route('manage.announcements.store') }}" class="mt-5 space-y-4">
                @csrf
                @if ($editing)
                    @method('PATCH')
                @endif

                <label class="block">
                    <span class="label">Başlık</span>
                    <input name="title" value="{{ old('title', $editing?->title) }}" maxlength="120" required class="input" placeholder="Örn. Bu akşam canlı müzik">
                </label>

                <label class="block">
                    <span class="label">Açıklama <span class="font-medium normal-case tracking-normal text-muted">(isteğe bağlı)</span></span>
                    <textarea name="body" maxlength="2000" rows="4" class="input">{{ old('body', $editing?->body) }}</textarea>
                </label>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="label">Tür</span>
                        <select name="type" class="input">
                            <option value="announcement" @selected(old('type', $editing?->type ?? 'announcement') === 'announcement')>Duyuru</option>
                            <option value="event" @selected(old('type', $editing?->type) === 'event')>Etkinlik</option>
                            <option value="campaign" @selected(old('type', $editing?->type) === 'campaign')>Kampanya</option>
                        </select>
                    </label>

                    <label class="block">
                        <span class="label">Hedef</span>
                        <select name="branch_id" class="input">
                            <option value="">Tüm şubeler</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('branch_id', $editing?->branch_id) === (string) $branch->id)>{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="label">Başlangıç</span>
                        <input type="datetime-local" name="starts_at" value="{{ old('starts_at', $editing?->starts_at?->format('Y-m-d\TH:i')) }}" class="input">
                    </label>
                    <label class="block">
                        <span class="label">Bitiş</span>
                        <input type="datetime-local" name="ends_at" value="{{ old('ends_at', $editing?->ends_at?->format('Y-m-d\TH:i')) }}" class="input">
                    </label>
                </div>

                <input type="hidden" name="is_active" value="0">
                <label class="flex min-h-10 items-center gap-2 text-sm font-semibold text-ink">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editing?->is_active ?? true)) class="size-4 accent-forest">
                    Menüde yayında
                </label>

                <div class="flex flex-wrap items-center gap-2">
                    <button class="btn btn-primary">
                        <x-ui.icon name="check" class="size-4" />
                        {{ $editing ? 'Değişiklikleri kaydet' : 'Duyuruyu yayınla' }}
                    </button>
                    @if ($editing)
                        <a href="{{ route('manage.announcements.index') }}" class="btn btn-secondary">Vazgeç</a>
                    @endif
                </div>
            </form>
        </aside>
    </div>
</x-layouts.panel>
