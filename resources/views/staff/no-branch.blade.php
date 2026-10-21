<x-layouts.panel title="Sipariş panosu" subtitle="Henüz bir şube atanmadı" active="board">
    <div class="card mx-auto max-w-xl p-8 text-center">
        <span class="mx-auto grid size-16 place-items-center rounded-full bg-amber-soft text-[#8a5a14]">
            <x-ui.icon name="pin" class="size-7" />
        </span>
        <h2 class="mt-5 font-display text-2xl font-semibold text-ink">Şube erişimi gerekiyor</h2>
        <p class="mt-2 text-sm leading-6 text-muted">
            Hesabınıza tanımlı aktif bir şube bulunmuyor. Kafe yöneticinizden şube ataması yapmasını isteyin.
        </p>
        <form method="post" action="{{ route('logout') }}" class="mt-6">
            @csrf
            <button type="submit" class="btn btn-secondary">Çıkış yap</button>
        </form>
    </div>
</x-layouts.panel>
