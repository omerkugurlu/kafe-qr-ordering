import { ArrowLeft, ArrowRight, Coffee, LoaderCircle, LockKeyhole } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../auth/AuthProvider.js';
import { ApiClientError } from '../lib/api.js';

function routeForRole(role: string): string {
    if (role === 'platform_admin') return '/platform';
    if (role === 'cafe_owner' || role === 'cafe_manager') return '/yonetim';
    return role === 'kitchen' ? '/mutfak' : '/garson';
}

export function LoginPage() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError('');
        setBusy(true);
        try {
            await login(email.trim(), password);
            const me = await fetch('/api/auth/me').then((response) => response.json());
            navigate(routeForRole(me.user?.role ?? 'waiter'), { replace: true });
        } catch (reason) {
            setError(reason instanceof ApiClientError ? reason.message : 'Giriş yapılamadı. Tekrar deneyin.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <main className="grid min-h-dvh bg-paper lg:grid-cols-[.95fr_1.05fr]">
            <section className="flex flex-col px-5 py-6 sm:px-10 lg:px-14 lg:py-10">
                <Link className="inline-flex w-fit items-center gap-2 text-sm font-semibold text-muted hover:text-forest" to="/">
                    <ArrowLeft aria-hidden="true" className="size-4" /> Ana sayfa
                </Link>
                <div className="mx-auto my-auto w-full max-w-md py-10">
                    <Link className="mb-10 flex items-center gap-3" to="/">
                        <span className="grid size-12 place-items-center rounded-2xl bg-forest text-amber"><Coffee aria-hidden="true" className="size-5" /></span>
                        <span><span className="block font-display text-lg font-semibold">SkyKite</span><span className="block text-[10px] font-bold uppercase tracking-[.16em] text-muted">Masa Sipariş</span></span>
                    </Link>
                    <p className="eyebrow">Çalışan ve yönetici girişi</p>
                    <h1 className="mt-2 font-display text-4xl font-semibold tracking-tight sm:text-5xl">Tekrar hoş geldiniz.</h1>
                    <p className="mt-3 text-sm leading-6 text-muted">Kafe yönetimi, garson ve mutfak panellerine buradan ulaşın.</p>
                    <form className="mt-8 space-y-5" onSubmit={submit}>
                        <label className="block"><span className="label">E-posta</span><input autoComplete="username" className="input" onChange={(event) => setEmail(event.target.value)} required type="email" value={email} /></label>
                        <label className="block"><span className="label">Şifre</span><span className="relative block"><LockKeyhole aria-hidden="true" className="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-muted" /><input autoComplete="current-password" className="input pl-11" onChange={(event) => setPassword(event.target.value)} required type="password" value={password} /></span></label>
                        {error && <p aria-live="polite" className="rounded-2xl bg-danger-soft px-4 py-3 text-sm font-semibold text-danger">{error}</p>}
                        <button className="btn-primary w-full rounded-2xl" disabled={busy} type="submit">{busy ? <LoaderCircle className="size-4 animate-spin" /> : <>Panele giriş yap <ArrowRight aria-hidden="true" className="size-4" /></>}</button>
                    </form>
                    <p className="mt-6 text-xs leading-5 text-muted">Müşteri hesabı için masadaki QR menüsünden <span className="font-semibold text-ink">Hesabım</span> bölümünü açın.</p>
                </div>
                <p className="text-xs text-muted">Giriş bilgilerinizi kafe yöneticinizden alabilirsiniz.</p>
            </section>
            <aside className="relative hidden overflow-hidden bg-forest p-12 text-white lg:flex lg:flex-col lg:justify-between">
                <div className="absolute -right-28 -top-24 size-96 rounded-full border border-white/10" />
                <div className="absolute -bottom-36 -left-24 size-[34rem] rounded-full border border-white/10" />
                <p className="relative text-xs font-bold uppercase tracking-[.2em] text-amber">SkyKite Masa Sipariş</p>
                <div className="relative max-w-lg"><p className="eyebrow text-amber">Her şey bir arada</p><h2 className="mt-4 font-display text-5xl font-semibold leading-tight">Masalar, siparişler ve servis tek ekranda.</h2><p className="mt-5 max-w-md text-base leading-7 text-white/70">Yoğun servis saatlerinde ekibiniz siparişleri hızlıca görür, paylaşır ve adisyonları güvenle kapatır.</p></div>
                <p className="relative text-xs text-white/50">© {new Date().getFullYear()} SkyKite Masa Sipariş</p>
            </aside>
        </main>
    );
}
