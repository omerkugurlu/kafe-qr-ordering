import {
    ArrowDownRight,
    ArrowRight,
    BadgeCheck,
    Coffee,
    QrCode,
    ReceiptText,
    ShoppingBag,
} from "lucide-react";
import { useState } from "react";
import { Link, useNavigate } from "react-router-dom";

const skyKiteLink =
    "https://skykite.com.tr/?utm_source=masa_qr&utm_medium=menu&utm_campaign=cross_promotion";

export function HomePage() {
    const [tableToken, setTableToken] = useState("");
    const navigate = useNavigate();

    function openTableMenu(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const token = tableToken.trim();

        if (token.length > 0) {
            navigate(`/masa/${encodeURIComponent(token)}`);
        }
    }

    return (
        <main className="overflow-hidden">
            <header className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-5 sm:px-8 lg:px-10">
                <Link
                    aria-label="SkyKite Masa Sipariş ana sayfa"
                    className="flex items-center gap-3"
                    to="/"
                >
                    <span className="grid size-11 place-items-center rounded-2xl bg-forest text-amber shadow-soft">
                        <Coffee aria-hidden="true" className="size-5" />
                    </span>
                    <span>
                        <span className="block font-display text-lg font-semibold leading-tight">
                            SkyKite
                        </span>
                        <span className="block text-[10px] font-bold uppercase tracking-[.16em] text-muted">
                            Masa Sipariş
                        </span>
                    </span>
                </Link>
                <nav aria-label="Ana menü" className="flex items-center gap-2">
                    <a
                        className="hidden rounded-full px-4 py-2 text-sm font-semibold text-ink-soft hover:bg-ink/5 sm:inline-flex"
                        href="#nasil-calisir"
                    >
                        Nasıl çalışır?
                    </a>
                    <Link className="btn-primary btn-sm" to="/giris">
                        Panele giriş{" "}
                        <ArrowRight aria-hidden="true" className="size-4" />
                    </Link>
                </nav>
            </header>

            <section className="mx-auto grid max-w-7xl items-center gap-10 px-5 pb-12 pt-6 sm:px-8 lg:grid-cols-[1.05fr_.95fr] lg:gap-16 lg:px-10 lg:pb-20 lg:pt-12">
                <div>
                    <p className="inline-flex items-center gap-2 rounded-full border border-forest/15 bg-sage/60 px-4 py-2 text-[11px] font-bold uppercase tracking-[.16em] text-forest">
                        <span className="size-1.5 rounded-full bg-forest" />{" "}
                        Kafe deneyimi, kolayca
                    </p>
                    <h1 className="mt-6 max-w-2xl font-display text-5xl font-semibold leading-[1.02] tracking-[-.03em] text-ink sm:text-6xl lg:text-7xl">
                        Masada siparişin{" "}
                        <span className="text-forest">en kolay</span> hali.
                    </h1>
                    <p className="mt-6 max-w-xl text-base leading-7 text-muted sm:text-lg sm:leading-8">
                        Menüyü keşfet, siparişini masandan ver. Kafeler için
                        menü, masa, servis ve kasa akışı tek yerde.
                    </p>
                    <div className="mt-8 flex flex-wrap items-center gap-3">
                        <Link className="btn-primary" to="/giris">
                            Yönetim paneline giriş{" "}
                            <ArrowRight aria-hidden="true" className="size-4" />
                        </Link>
                        <a className="btn-secondary" href="#nasil-calisir">
                            Nasıl çalışır?
                        </a>
                    </div>
                    <div className="mt-9 grid gap-3 border-t border-line pt-6 text-sm text-muted sm:flex sm:flex-wrap sm:gap-x-6">
                        <span className="inline-flex items-center gap-2">
                            <BadgeCheck
                                aria-hidden="true"
                                className="size-4 text-forest"
                            />{" "}
                            Temassız sipariş
                        </span>
                        <span className="inline-flex items-center gap-2">
                            <BadgeCheck
                                aria-hidden="true"
                                className="size-4 text-forest"
                            />{" "}
                            Canlı servis takibi
                        </span>
                        <span className="inline-flex items-center gap-2">
                            <BadgeCheck
                                aria-hidden="true"
                                className="size-4 text-forest"
                            />{" "}
                            Tek ekranda yönetim
                        </span>
                    </div>
                    <form
                        className="mt-8 rounded-3xl border border-line bg-paper p-4 shadow-soft sm:flex sm:items-end sm:gap-3"
                        onSubmit={openTableMenu}
                    >
                        <label className="min-w-0 flex-1">
                            <span className="label">
                                Masa QR bağlantın açılmadı mı?
                            </span>
                            <input
                                autoComplete="off"
                                className="input"
                                maxLength={128}
                                onChange={(event) =>
                                    setTableToken(event.target.value)
                                }
                                placeholder="Masa kodunu gir"
                                value={tableToken}
                            />
                        </label>
                        <button
                            className="btn-amber mt-3 w-full sm:mt-0 sm:w-auto"
                            type="submit"
                        >
                            Menüyü aç{" "}
                            <ArrowDownRight
                                aria-hidden="true"
                                className="size-4"
                            />
                        </button>
                    </form>
                </div>

                <div className="relative mx-auto w-full max-w-md">
                    <div className="pointer-events-none absolute -right-10 -top-10 size-44 rounded-full bg-amber/20 blur-3xl" />
                    <div className="pointer-events-none absolute -bottom-12 -left-10 size-48 rounded-full bg-sage/70 blur-3xl" />
                    <div className="card relative overflow-hidden rounded-[2.25rem] p-4 shadow-lift sm:p-5">
                        <div className="flex items-center justify-between gap-3 border-b border-line pb-4">
                            <div className="flex items-center gap-3">
                                <span className="grid size-10 place-items-center rounded-2xl bg-forest text-amber">
                                    <Coffee
                                        aria-hidden="true"
                                        className="size-5"
                                    />
                                </span>
                                <div>
                                    <p className="text-[10px] font-bold uppercase tracking-[.16em] text-muted">
                                        Bugünün menüsü
                                    </p>
                                    <p className="font-display text-lg font-semibold leading-tight text-ink">
                                        Kıyı Kahve
                                    </p>
                                </div>
                            </div>
                            <span className="rounded-full bg-amber-soft px-3 py-1 text-xs font-bold text-ink">
                                Masa 04
                            </span>
                        </div>
                        <div className="mt-4 overflow-hidden rounded-3xl bg-cream">
                            <div className="relative h-48 overflow-hidden">
                                <img
                                    alt="Taze hazırlanmış latte"
                                    className="size-full object-cover"
                                    loading="lazy"
                                    src="https://images.unsplash.com/photo-1509042239860-f550ce710b93?auto=format&fit=crop&w=900&q=80"
                                />
                                <div className="absolute inset-0 bg-gradient-to-t from-ink/60 via-ink/5 to-transparent" />
                                <span className="absolute left-4 top-4 rounded-full bg-paper/95 px-3 py-1 text-[10px] font-bold uppercase tracking-[.12em] text-forest">
                                    Barista favorisi
                                </span>
                            </div>
                            <div className="p-4">
                                <div className="flex items-start justify-between gap-3">
                                    <div>
                                        <p className="font-display text-lg font-semibold text-ink">
                                            Buzlu latte
                                        </p>
                                        <p className="mt-0.5 text-xs text-muted">
                                            Çift shot espresso · soğuk süt
                                        </p>
                                    </div>
                                    <span className="text-sm font-bold text-forest">
                                        ₺120,00
                                    </span>
                                </div>
                                <div className="mt-3 flex items-center justify-between gap-3 rounded-2xl border border-line bg-paper px-3 py-2.5">
                                    <span className="inline-flex items-center gap-2 text-xs font-semibold text-ink-soft">
                                        <span className="size-2 rounded-full bg-success" />{" "}
                                        Garsona iletildi
                                    </span>
                                    <span className="text-[11px] font-medium text-muted">
                                        2 dk önce
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div className="mt-4 grid grid-cols-3 gap-2 text-center">
                            <div className="rounded-2xl bg-cream px-2 py-3">
                                <p className="font-display text-lg font-semibold">
                                    4
                                </p>
                                <p className="text-[10px] font-semibold uppercase tracking-[.1em] text-muted">
                                    Masa
                                </p>
                            </div>
                            <div className="rounded-2xl bg-cream px-2 py-3">
                                <p className="font-display text-lg font-semibold">
                                    12
                                </p>
                                <p className="text-[10px] font-semibold uppercase tracking-[.1em] text-muted">
                                    Sipariş
                                </p>
                            </div>
                            <div className="rounded-2xl bg-cream px-2 py-3">
                                <p className="font-display text-lg font-semibold">
                                    ₺1.240
                                </p>
                                <p className="text-[10px] font-semibold uppercase tracking-[.1em] text-muted">
                                    Adisyon
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section
                className="mx-auto max-w-7xl scroll-mt-8 border-t border-line px-5 py-12 sm:px-8 lg:px-10 lg:py-16"
                id="nasil-calisir"
            >
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <p className="text-[11px] font-bold uppercase tracking-[.18em] text-terracotta">
                            Nasıl çalışır?
                        </p>
                        <h2 className="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">
                            Üç adımda masadan mutfağa.
                        </h2>
                    </div>
                    <p className="max-w-sm text-sm leading-6 text-muted">
                        Müşteri QR kodu okutur, sipariş garsona ve mutfağa
                        düşer. Ödeme adisyon üzerinden kapanır.
                    </p>
                </div>
                <div className="mt-8 grid gap-4 md:grid-cols-3">
                    <article className="card p-6">
                        <span className="grid size-11 place-items-center rounded-2xl bg-sage text-forest">
                            <QrCode aria-hidden="true" className="size-5" />
                        </span>
                        <p className="mt-5 text-[10px] font-bold uppercase tracking-[.16em] text-muted">
                            01 · Tara
                        </p>
                        <h3 className="mt-1 font-display text-xl font-semibold">
                            Masaya özel menü
                        </h3>
                        <p className="mt-2 text-sm leading-6 text-muted">
                            Her masanın kendi QR bağlantısı ve menüsü vardır.
                            Müşteri uygulama kurmadan menüye ulaşır.
                        </p>
                    </article>
                    <article className="card p-6">
                        <span className="grid size-11 place-items-center rounded-2xl bg-amber-soft text-ink">
                            <ShoppingBag
                                aria-hidden="true"
                                className="size-5"
                            />
                        </span>
                        <p className="mt-5 text-[10px] font-bold uppercase tracking-[.16em] text-muted">
                            02 · Sipariş
                        </p>
                        <h3 className="mt-1 font-display text-xl font-semibold">
                            Sepet ve onay
                        </h3>
                        <p className="mt-2 text-sm leading-6 text-muted">
                            Ürün seçenekleri, notlar ve öneriler sepette
                            toplanır. Toplam tutar onaydan önce görünür.
                        </p>
                    </article>
                    <article className="card p-6">
                        <span className="grid size-11 place-items-center rounded-2xl bg-terracotta-soft text-terracotta">
                            <ReceiptText
                                aria-hidden="true"
                                className="size-5"
                            />
                        </span>
                        <p className="mt-5 text-[10px] font-bold uppercase tracking-[.16em] text-muted">
                            03 · Servis
                        </p>
                        <h3 className="mt-1 font-display text-xl font-semibold">
                            Canlı takip
                        </h3>
                        <p className="mt-2 text-sm leading-6 text-muted">
                            Garson ve mutfak siparişi görür. Müşteri durumu ve
                            adisyonu kendi ekranından takip eder.
                        </p>
                    </article>
                </div>
            </section>

            <section className="mx-auto mb-12 max-w-7xl px-5 sm:px-8 lg:px-10">
                <div className="flex flex-col gap-5 rounded-[2rem] bg-forest px-6 py-8 text-white sm:px-10 sm:py-10 lg:flex-row lg:items-center lg:justify-between">
                    <div className="max-w-2xl">
                        <p className="text-[11px] font-bold uppercase tracking-[.18em] text-amber">
                            Öğrenci misin?
                        </p>
                        <h2 className="mt-2 font-display text-3xl font-semibold leading-tight sm:text-4xl">
                            SkyKite tam senin için.
                        </h2>
                        <p className="mt-3 text-sm leading-6 text-white/75">
                            KKTC’den Türkiye’ye ürün getirtmenin kolay yolu.
                            İhtiyacın olan ürünleri keşfet, SkyKite ile kapına
                            getirt.
                        </p>
                    </div>
                    <a
                        className="btn-amber shrink-0"
                        href={skyKiteLink}
                        rel="noreferrer"
                        target="_blank"
                    >
                        SkyKite’ı keşfet{" "}
                        <ArrowRight aria-hidden="true" className="size-4" />
                    </a>
                </div>
            </section>
            <footer className="border-t border-line px-5 py-6 text-center text-xs text-muted">
                © {new Date().getFullYear()} SkyKite Masa Sipariş
            </footer>
        </main>
    );
}
