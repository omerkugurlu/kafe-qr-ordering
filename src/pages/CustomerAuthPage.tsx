import { ArrowLeft, ArrowRight, Coffee, LoaderCircle } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useAuth } from '../auth/AuthProvider.js';
import { ApiClientError } from '../lib/api.js';

export function CustomerAuthPage({ mode }: { mode: 'login' | 'register' }) {
    const { token = '' } = useParams();
    const navigate = useNavigate();
    const { login, registerCustomer } = useAuth();
    const [name, setName] = useState('');
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setError('');
        setBusy(true);
        try {
            if (mode === 'register') await registerCustomer({ token, name: name.trim(), email: email.trim(), password });
            else await login(email.trim(), password, token);
            navigate(`/masa/${encodeURIComponent(token)}`, { replace: true });
        } catch (reason) {
            setError(reason instanceof ApiClientError ? reason.message : 'İşlem tamamlanamadı. Tekrar deneyin.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <main className="flex min-h-dvh flex-col bg-cream px-4 py-5 sm:px-8 sm:py-8">
            <Link className="mx-auto flex w-full max-w-5xl items-center gap-2 text-sm font-semibold text-muted hover:text-forest" to={`/masa/${encodeURIComponent(token)}`}>
                <ArrowLeft aria-hidden="true" className="size-4" /> Menüye dön
            </Link>
            <section className="card mx-auto my-auto w-full max-w-md p-6 sm:p-8">
                <div className="grid size-12 place-items-center rounded-2xl bg-forest text-amber"><Coffee aria-hidden="true" className="size-5" /></div>
                <p className="mt-6 eyebrow">SkyKite Masa Sipariş</p>
                <h1 className="mt-2 font-display text-3xl font-semibold">{mode === 'register' ? 'Müşteri hesabı oluştur' : 'Müşteri hesabına gir'}</h1>
                <p className="mt-2 text-sm leading-6 text-muted">Siparişlerini ve kazandığın ödülleri buradan takip edebilirsin.</p>
                <form className="mt-6 space-y-4" onSubmit={submit}>
                    {mode === 'register' && <label className="block"><span className="label">Ad soyad</span><input autoComplete="name" className="input" maxLength={120} minLength={2} onChange={(event) => setName(event.target.value)} required value={name} /></label>}
                    <label className="block"><span className="label">E-posta</span><input autoComplete="email" className="input" onChange={(event) => setEmail(event.target.value)} required type="email" value={email} /></label>
                    <label className="block"><span className="label">Şifre</span><input autoComplete={mode === 'register' ? 'new-password' : 'current-password'} className="input" minLength={mode === 'register' ? 8 : undefined} onChange={(event) => setPassword(event.target.value)} required type="password" value={password} /><span className="mt-1.5 block text-xs text-muted">{mode === 'register' ? 'En az 8 karakter.' : 'Şifreni gir.'}</span></label>
                    {error && <p aria-live="polite" className="rounded-2xl bg-danger-soft px-4 py-3 text-sm font-semibold text-danger">{error}</p>}
                    <button className="btn-primary w-full rounded-2xl" disabled={busy} type="submit">{busy ? <LoaderCircle className="size-4 animate-spin" /> : <>{mode === 'register' ? 'Hesap oluştur' : 'Giriş yap'} <ArrowRight aria-hidden="true" className="size-4" /></>}</button>
                </form>
                <p className="mt-6 text-center text-sm text-muted">{mode === 'register' ? 'Zaten hesabın var mı?' : 'İlk kez mi geliyorsun?'}{' '}<Link className="font-bold text-forest underline decoration-forest/30 underline-offset-4" to={`/masa/${encodeURIComponent(token)}/${mode === 'register' ? 'giris' : 'kayit'}`}>{mode === 'register' ? 'Giriş yap' : 'Hesap oluştur'}</Link></p>
            </section>
            <p className="mx-auto mt-6 max-w-5xl text-center text-[11px] text-muted">Hesap bilgilerin bu kafedeki sipariş ve sadakat işlemleri için kullanılır.</p>
        </main>
    );
}
