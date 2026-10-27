import { LoaderCircle, Plus, Save } from "lucide-react";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { formatCurrency, toCents, toDecimal } from "../domain/money.js";
import { apiPatch, apiPost, apiRequest } from "../lib/api.js";
import {
    dateTimeInput,
    dateTimePayload,
    errorMessage,
    LoadingCard,
} from "./cafeFeatureUtils.js";

interface Coupon {
    id: number;
    code: string;
    type: "percent" | "fixed";
    value: number;
    minSubtotalCents: number;
    startsAt: string | null;
    endsAt: string | null;
    usageLimit: number | null;
    usedCount: number;
    isActive: boolean;
}

interface CouponDraft {
    id: number | null;
    code: string;
    type: "percent" | "fixed";
    value: string;
    minSubtotal: string;
    usageLimit: string;
    startsAt: string;
    endsAt: string;
    isActive: boolean;
}

function emptyDraft(): CouponDraft {
    return {
        id: null,
        code: "",
        type: "percent",
        value: "10",
        minSubtotal: "0.00",
        usageLimit: "",
        startsAt: "",
        endsAt: "",
        isActive: true,
    };
}

export function CafeCouponsPanel({
    csrfToken,
}: {
    csrfToken: string | null;
}) {
    const [coupons, setCoupons] = useState<Coupon[]>([]);
    const [draft, setDraft] = useState<CouponDraft>(emptyDraft);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");

    const refresh = useCallback(async () => {
        setLoading(true);
        try {
            const result = await apiRequest<{ coupons: Coupon[] }>(
                "/api/manage/coupons",
            );
            setCoupons(result.coupons);
            setError("");
        } catch (reason) {
            setError(errorMessage(reason));
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    async function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setBusy(true);
        setError("");
        setNotice("");
        try {
            const value =
                draft.type === "percent"
                    ? Number(draft.value)
                    : toCents(draft.value);
            if (!Number.isSafeInteger(value) || value < 1)
                throw new TypeError("İndirim miktarını kontrol edin.");
            const payload = {
                code: draft.code,
                type: draft.type,
                value,
                minSubtotalCents: toCents(draft.minSubtotal),
                usageLimit: draft.usageLimit ? Number(draft.usageLimit) : null,
                startsAt: dateTimePayload(draft.startsAt),
                endsAt: dateTimePayload(draft.endsAt),
                isActive: draft.isActive,
            };
            if (draft.id) {
                await apiPatch(
                    `/api/manage/coupons/${draft.id}`,
                    payload,
                    csrfToken,
                );
                setNotice("Kupon güncellendi.");
            } else {
                await apiPost("/api/manage/coupons", payload, csrfToken);
                setNotice("Kupon oluşturuldu.");
            }
            setDraft(emptyDraft());
            await refresh();
        } catch (reason) {
            setError(errorMessage(reason));
        } finally {
            setBusy(false);
        }
    }

    return (
        <section className="mt-5 space-y-4">
            {(error || notice) && (
                <div aria-live="polite" className={`rounded-2xl p-3 text-sm font-semibold ${error ? "bg-danger-soft text-danger" : "bg-success-soft text-success"}`}>
                    {error || notice}
                </div>
            )}
            <header>
                <p className="eyebrow">Sepette kullanılabilir indirimler</p>
                <h1 className="mt-1 font-display text-2xl font-semibold">Kupon sistemi</h1>
                <p className="mt-1 text-sm text-muted">Kupon kodu, indirim, alt limit, kullanım sınırı ve geçerlilik tarihini yönet.</p>
            </header>
            <form className="card grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-4" onSubmit={(event) => void save(event)}>
                <label><span className="label">Kupon kodu</span><input className="input uppercase" maxLength={40} minLength={2} onChange={(event) => setDraft({ ...draft, code: event.target.value.toUpperCase() })} required value={draft.code} /></label>
                <label><span className="label">İndirim türü</span><select className="input" onChange={(event) => setDraft({ ...draft, type: event.target.value as CouponDraft["type"], value: event.target.value === "percent" ? "10" : "10.00" })} value={draft.type}><option value="percent">Yüzde</option><option value="fixed">Sabit tutar</option></select></label>
                <label><span className="label">{draft.type === "percent" ? "İndirim yüzdesi" : "İndirim tutarı (₺)"}</span><input className="input" inputMode="decimal" max={draft.type === "percent" ? 100 : undefined} min="1" onChange={(event) => setDraft({ ...draft, value: event.target.value })} required type="number" value={draft.value} /></label>
                <label><span className="label">Minimum sepet (₺)</span><input className="input" min="0" onChange={(event) => setDraft({ ...draft, minSubtotal: event.target.value })} required step="0.01" type="number" value={draft.minSubtotal} /></label>
                <label><span className="label">Kullanım sınırı</span><input className="input" min="1" onChange={(event) => setDraft({ ...draft, usageLimit: event.target.value })} placeholder="Sınırsız" type="number" value={draft.usageLimit} /></label>
                <label><span className="label">Başlangıç</span><input className="input" onChange={(event) => setDraft({ ...draft, startsAt: event.target.value })} type="datetime-local" value={draft.startsAt} /></label>
                <label><span className="label">Bitiş</span><input className="input" onChange={(event) => setDraft({ ...draft, endsAt: event.target.value })} type="datetime-local" value={draft.endsAt} /></label>
                <label className="flex items-center gap-2 self-end pb-3 text-sm"><input checked={draft.isActive} onChange={(event) => setDraft({ ...draft, isActive: event.target.checked })} type="checkbox" /> Kupon etkin</label>
                <div className="flex gap-2 md:col-span-2 xl:col-span-4"><button className="btn-primary" disabled={busy} type="submit">{busy ? <LoaderCircle className="size-4 animate-spin" /> : draft.id ? <Save className="size-4" /> : <Plus className="size-4" />}{draft.id ? "Kuponu kaydet" : "Kupon oluştur"}</button>{draft.id && <button className="btn-secondary" onClick={() => setDraft(emptyDraft())} type="button">Vazgeç</button>}</div>
            </form>
            {loading ? <LoadingCard /> : coupons.length ? (
                <div className="space-y-3">
                    {coupons.map((item) => (
                        <article className="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center" key={item.id}>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2"><h2 className="font-mono text-lg font-bold tracking-wide">{item.code}</h2><span className={item.isActive ? "badge-paid" : "badge-cancelled"}>{item.isActive ? "Etkin" : "Kapalı"}</span></div>
                                <p className="mt-1 text-sm text-muted">{item.type === "percent" ? `%${item.value}` : formatCurrency(item.value)} indirim · Minimum sepet {formatCurrency(item.minSubtotalCents)} · {item.usageLimit === null ? "Sınırsız kullanım" : `${item.usedCount} / ${item.usageLimit} kullanım`}</p>
                            </div>
                            <button className="btn-secondary btn-sm" onClick={() => setDraft({ id: item.id, code: item.code, type: item.type, value: item.type === "percent" ? String(item.value) : toDecimal(item.value), minSubtotal: toDecimal(item.minSubtotalCents), usageLimit: item.usageLimit === null ? "" : String(item.usageLimit), startsAt: dateTimeInput(item.startsAt), endsAt: dateTimeInput(item.endsAt), isActive: item.isActive })} type="button">Düzenle</button>
                        </article>
                    ))}
                </div>
            ) : <div className="card p-5 text-sm text-muted">Henüz kupon yok.</div>}
        </section>
    );
}
