import { ArrowLeft, Coffee } from "lucide-react";
import { Link, useLocation } from "react-router-dom";

type PlaceholderPageProps = {
    title: string;
};

export function PlaceholderPage({ title }: PlaceholderPageProps) {
    const location = useLocation();

    return (
        <main className="mx-auto grid min-h-dvh max-w-3xl place-items-center px-5 py-12">
            <section className="card w-full max-w-xl p-7 text-center sm:p-10">
                <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-forest text-amber">
                    <Coffee aria-hidden="true" className="size-6" />
                </span>
                <p className="mt-6 text-[11px] font-bold uppercase tracking-[.18em] text-terracotta">
                    SkyKite Masa Sipariş
                </p>
                <h1 className="mt-2 font-display text-3xl font-semibold text-ink">
                    {title}
                </h1>
                <p className="mt-3 break-all text-sm leading-6 text-muted">
                    Bu ekran yeni Netlify + Turso uygulamasına taşınıyor.
                </p>
                <p className="mt-2 break-all rounded-xl bg-cream px-3 py-2 font-mono text-xs text-muted">
                    {location.pathname}
                </p>
                <Link className="btn-secondary mt-6" to="/">
                    <ArrowLeft aria-hidden="true" className="size-4" />
                    Ana sayfaya dön
                </Link>
            </section>
        </main>
    );
}
