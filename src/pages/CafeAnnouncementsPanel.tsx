import { Megaphone, Plus, Save } from "lucide-react";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { apiPatch, apiPost, apiRequest } from "../lib/api.js";
import {
    dateTimeInput,
    dateTimePayload,
    errorMessage,
    LoadingCard,
    type BranchOption,
} from "./cafeFeatureUtils.js";

interface Announcement {
    id: number;
    branchId: number | null;
    branchName: string | null;
    type: "announcement" | "promotion";
    title: string;
    body: string | null;
    startsAt: string | null;
    endsAt: string | null;
    isActive: boolean;
}

interface AnnouncementDraft {
    id: number | null;
    type: "announcement" | "promotion";
    title: string;
    body: string;
    branchId: string;
    startsAt: string;
    endsAt: string;
    isActive: boolean;
}

function emptyDraft(): AnnouncementDraft {
    return {
        id: null,
        type: "announcement",
        title: "",
        body: "",
        branchId: "",
        startsAt: "",
        endsAt: "",
        isActive: true,
    };
}

export function CafeAnnouncementsPanel({
    csrfToken,
    branches,
}: {
    csrfToken: string | null;
    branches: BranchOption[];
}) {
    const [announcements, setAnnouncements] = useState<Announcement[]>([]);
    const [draft, setDraft] = useState<AnnouncementDraft>(emptyDraft);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");

    const refresh = useCallback(async () => {
        setLoading(true);
        try {
            const result = await apiRequest<{
                announcements: Announcement[];
            }>("/api/manage/announcements");
            setAnnouncements(result.announcements);
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
            const payload = {
                type: draft.type,
                title: draft.title,
                body: draft.body.trim() || null,
                branchId: draft.branchId ? Number(draft.branchId) : null,
                startsAt: dateTimePayload(draft.startsAt),
                endsAt: dateTimePayload(draft.endsAt),
                isActive: draft.isActive,
            };
            if (draft.id) {
                await apiPatch(
                    `/api/manage/announcements/${draft.id}`,
                    payload,
                    csrfToken,
                );
                setNotice("Duyuru güncellendi.");
            } else {
                await apiPost("/api/manage/announcements", payload, csrfToken);
                setNotice("Duyuru oluşturuldu.");
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
                <p className="eyebrow">Müşteri menüsünde göster</p>
                <h1 className="mt-1 font-display text-2xl font-semibold">Şube duyuruları</h1>
                <p className="mt-1 text-sm text-muted">Tüm şubelere veya seçtiğin şubeye zamanlı duyuru ve kampanya ekle.</p>
            </header>
            <form className="card grid gap-3 p-4 md:grid-cols-2" onSubmit={(event) => void save(event)}>
                <label><span className="label">Başlık</span><input className="input" maxLength={100} minLength={2} onChange={(event) => setDraft({ ...draft, title: event.target.value })} required value={draft.title} /></label>
                <label><span className="label">Tür</span><select className="input" onChange={(event) => setDraft({ ...draft, type: event.target.value as AnnouncementDraft["type"] })} value={draft.type}><option value="announcement">Duyuru</option><option value="promotion">Kampanya</option></select></label>
                <label><span className="label">Şube</span><select className="input" onChange={(event) => setDraft({ ...draft, branchId: event.target.value })} value={draft.branchId}><option value="">Tüm şubeler</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></label>
                <label className="flex items-center gap-2 self-end pb-3 text-sm"><input checked={draft.isActive} onChange={(event) => setDraft({ ...draft, isActive: event.target.checked })} type="checkbox" /> Yayında</label>
                <label className="md:col-span-2"><span className="label">Açıklama</span><textarea className="input min-h-20" maxLength={1000} onChange={(event) => setDraft({ ...draft, body: event.target.value })} value={draft.body} /></label>
                <label><span className="label">Başlangıç (isteğe bağlı)</span><input className="input" onChange={(event) => setDraft({ ...draft, startsAt: event.target.value })} type="datetime-local" value={draft.startsAt} /></label>
                <label><span className="label">Bitiş (isteğe bağlı)</span><input className="input" onChange={(event) => setDraft({ ...draft, endsAt: event.target.value })} type="datetime-local" value={draft.endsAt} /></label>
                <div className="flex gap-2 md:col-span-2"><button className="btn-primary" disabled={busy} type="submit">{busy ? <span className="size-4 animate-spin rounded-full border-2 border-white/30 border-t-white" /> : draft.id ? <Save className="size-4" /> : <Plus className="size-4" />}{draft.id ? "Duyuruyu kaydet" : "Duyuru oluştur"}</button>{draft.id && <button className="btn-secondary" onClick={() => setDraft(emptyDraft())} type="button">Vazgeç</button>}</div>
            </form>
            {loading ? <LoadingCard /> : announcements.length ? (
                <div className="space-y-3">
                    {announcements.map((item) => (
                        <article className="card flex flex-col gap-3 p-4 sm:flex-row sm:items-start" key={item.id}>
                            <div className="grid size-10 shrink-0 place-items-center rounded-2xl bg-sage text-forest"><Megaphone className="size-5" /></div>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2"><h2 className="font-display text-lg font-semibold">{item.title}</h2><span className={item.isActive ? "badge-paid" : "badge-cancelled"}>{item.isActive ? "Yayında" : "Kapalı"}</span></div>
                                <p className="mt-1 text-xs text-muted">{item.type === "promotion" ? "Kampanya" : "Duyuru"} · {item.branchName ?? "Tüm şubeler"}</p>
                                {item.body && <p className="mt-2 text-sm text-muted">{item.body}</p>}
                            </div>
                            <button className="btn-secondary btn-sm" onClick={() => setDraft({ id: item.id, type: item.type, title: item.title, body: item.body ?? "", branchId: item.branchId ? String(item.branchId) : "", startsAt: dateTimeInput(item.startsAt), endsAt: dateTimeInput(item.endsAt), isActive: item.isActive })} type="button">Düzenle</button>
                        </article>
                    ))}
                </div>
            ) : <div className="card p-5 text-sm text-muted">Henüz duyuru yok.</div>}
        </section>
    );
}
