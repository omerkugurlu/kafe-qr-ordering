<x-layouts.app title="{{ $session->diningTable->name }} · Adisyon">
    <div class="mx-auto max-w-4xl">
        <a href="{{ route('staff.orders') }}" class="text-sm font-bold text-leaf">← Sipariş paneli</a>
        <div class="mt-5 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-clay">{{ $session->branch->cafe->name }} · {{ $session->branch->name }}</p>
                <h1 class="mt-1 font-display text-4xl font-extrabold">{{ $session->diningTable->name }}</h1>
                <p class="mt-2 text-muted">{{ $session->opened_at->timezone('Europe/Istanbul')->format('d.m.Y H:i') }} açıldı · {{ $session->status === 'open' ? 'Açık adisyon' : 'Kapatıldı' }}</p>
            </div>
            <div class="rounded-2xl bg-ink px-5 py-4 text-white">
                <p class="text-xs text-white/60">Kalan tutar</p>
                <p class="mt-1 font-display text-3xl font-extrabold">₺{{ number_format((float) $session->dueAmount(), 2, ',', '.') }}</p>
            </div>
        </div>

        <div class="mt-7 grid gap-6 lg:grid-cols-[1.1fr_.9fr]">
            <section class="rounded-3xl border border-ink/10 bg-paper p-6">
                <h2 class="font-display text-xl font-extrabold">Adisyon kalemleri</h2>
                <div class="mt-5 space-y-5">
                    @forelse ($session->orders as $order)
                        <div class="border-b border-ink/10 pb-4 last:border-0">
                            <div class="flex justify-between gap-4">
                                <div>
                                    <p class="font-bold">Sipariş #{{ $order->order_number }} · {{ $order->source === 'waiter' ? 'Garson' : 'QR' }}</p>
                                    <p class="mt-1 text-xs text-muted">{{ match ($order->status) {'pending' => 'Onay bekliyor', 'accepted' => 'Hazırlanıyor', 'served' => 'Servis edildi', 'paid' => 'Ödendi', 'cancelled' => 'İptal', default => $order->status} }}</p>
                                </div>
                                <span class="font-bold">₺{{ number_format((float) $order->total, 2, ',', '.') }}</span>
                            </div>
                            @foreach ($order->items as $item)
                                <div class="mt-3 flex items-center justify-between gap-3 text-sm text-muted">
                                    <span>{{ $item->quantity }}× {{ $item->name_snapshot }}</span>
                                    <span>₺{{ number_format((float) $item->line_total, 2, ',', '.') }}</span>
                                </div>
                            @endforeach
                            @if ((float) $order->discount_total > 0)
                                <p class="mt-2 text-right text-xs font-semibold text-leaf">Sadakat indirimi · −₺{{ number_format((float) $order->discount_total, 2, ',', '.') }}</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-muted">Henüz sipariş yok.</p>
                    @endforelse
                </div>
            </section>

            <div class="space-y-6">
                <section class="rounded-3xl border border-ink/10 bg-paper p-6">
                    <h2 class="font-display text-xl font-extrabold">Adisyona ürün ekle</h2>
                    <p class="mt-1 text-sm text-muted">Seçilen ürünler güncel şube fiyatıyla servis edilmiş olarak kaydedilir.</p>
                    <form method="post" action="{{ route('staff.sessions.items.store', $session->id) }}" class="mt-4 space-y-4">
                        @csrf
                        <div class="max-h-72 space-y-3 overflow-y-auto rounded-xl bg-cream/50 p-3">
                            @forelse ($categories as $category)
                                <div>
                                    <p class="mb-1 text-xs font-bold uppercase tracking-wide text-clay">{{ $category->name }}</p>
                                    @foreach ($category->products as $product)
                                        <label class="flex items-center justify-between gap-3 border-b border-ink/5 py-2 text-sm">
                                            <span>{{ $product->name }} <span class="text-muted">· ₺{{ number_format((float) $product->priceFor($session->branch), 2, ',', '.') }}</span></span>
                                            <input aria-label="{{ $product->name }} adedi" type="number" name="items[{{ $product->id }}]" value="0" min="0" max="20" class="w-16 rounded-lg border border-ink/15 bg-paper px-2 py-1.5 text-center font-bold">
                                        </label>
                                    @endforeach
                                </div>
                            @empty
                                <p class="text-sm text-muted">Bu şubede satışa açık ürün yok.</p>
                            @endforelse
                        </div>
                        <label class="block text-sm font-bold">Not <span class="font-normal text-muted">(isteğe bağlı)</span>
                            <input name="note" maxlength="500" class="mt-2 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3">
                        </label>
                        <button @disabled($session->status !== 'open' || $categories->sum(fn ($category) => $category->products->count()) === 0) class="w-full rounded-xl bg-leaf px-4 py-3 font-bold text-white disabled:opacity-50">Seçilenleri adisyona ekle</button>
                    </form>
                </section>

                <section class="rounded-3xl border border-ink/10 bg-paper p-6">
                    <h2 class="font-display text-xl font-extrabold">Ödeme kaydet</h2>
                    <p class="mt-1 text-sm text-muted">Kart ödemesini fiziksel POS cihazında alın.</p>
                    <form method="post" action="{{ route('staff.sessions.payments.store', $session->id) }}" class="mt-5 space-y-4">
                        @csrf
                        <label class="block text-sm font-bold">Tutar
                            <input type="number" name="amount" step="0.01" min="0.01" max="1000000" required class="mt-2 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3">
                        </label>
                        <label class="block text-sm font-bold">Ödeme yöntemi
                            <select name="method" required class="mt-2 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3"><option value="cash">Nakit</option><option value="card">Kart (fiziksel POS)</option></select>
                        </label>
                        <label class="block text-sm font-bold">Ödeme dağılımı
                            <select name="order_item_id" class="mt-2 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3">
                                <option value="">Adisyon geneli</option>
                                @foreach ($session->orders->whereIn('status', ['accepted', 'served']) as $order)
                                    @foreach ($order->items as $item)
                                        <option value="{{ $item->id }}">#{{ $order->order_number }} · {{ $item->quantity }}× {{ $item->name_snapshot }} (₺{{ number_format((float) $item->line_total, 2, ',', '.') }})</option>
                                    @endforeach
                                @endforeach
                            </select>
                        </label>
                        <label class="block text-sm font-bold">Kişi / not <span class="font-normal text-muted">(isteğe bağlı)</span>
                            <input name="person_label" maxlength="80" class="mt-2 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3" placeholder="Örn. Ayşe / 2 kişi">
                        </label>
                        <button @disabled($session->status !== 'open') class="w-full rounded-xl bg-leaf px-4 py-3 font-bold text-white hover:bg-ink disabled:opacity-50">Ödemeyi kaydet</button>
                    </form>
                </section>

                <section class="rounded-3xl border border-ink/10 bg-paper p-6">
                    <h2 class="font-display text-xl font-extrabold">Ödeme geçmişi</h2>
                    <div class="mt-4 space-y-3">
                        @forelse ($session->payments as $payment)
                            <div class="rounded-xl bg-cream px-4 py-3 text-sm">
                                <div class="flex justify-between gap-3">
                                    <span>{{ $payment->method === 'cash' ? 'Nakit' : 'Kart' }}@if($payment->person_label) · {{ $payment->person_label }}@endif</span>
                                    <span class="font-bold">₺{{ number_format((float) $payment->amount, 2, ',', '.') }}</span>
                                </div>
                                @if ($payment->voided_at)
                                    <p class="mt-1 text-xs text-red-700">İptal edildi · {{ $payment->void_reason }}</p>
                                @elseif ($session->status === 'open')
                                    <form method="post" action="{{ route('staff.sessions.payments.void', [$session->id, $payment->id]) }}" class="mt-2 flex gap-2">
                                        @csrf
                                        <input name="reason" required minlength="3" maxlength="160" placeholder="İptal gerekçesi" class="min-w-0 flex-1 rounded-lg border border-ink/15 bg-paper px-3 py-2 text-xs">
                                        <button class="rounded-lg border border-red-700/20 px-3 py-2 text-xs font-bold text-red-700">Kaydı iptal et</button>
                                    </form>
                                @endif
                            </div>
                        @empty
                            <p class="text-sm text-muted">Henüz ödeme yok.</p>
                        @endforelse
                    </div>
                </section>

                <form method="post" action="{{ route('staff.sessions.close', $session->id) }}" class="rounded-3xl border border-ink/10 bg-paper p-6">
                    @csrf
                    <label class="block text-sm font-bold">Kapanış notu <span class="font-normal text-muted">(isteğe bağlı)</span>
                        <input name="closing_note" maxlength="160" class="mt-2 w-full rounded-xl border border-ink/15 bg-cream/40 px-4 py-3">
                    </label>
                    <button @disabled($session->status !== 'open') class="mt-4 w-full rounded-xl border border-leaf/30 px-4 py-3 font-bold text-leaf hover:bg-lime/25 disabled:opacity-50">Adisyonu kapat</button>
                </form>
            </div>
        </div>
    </div>
</x-layouts.app>
