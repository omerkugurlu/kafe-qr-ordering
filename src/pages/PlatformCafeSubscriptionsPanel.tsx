import { CalendarClock, LoaderCircle, Store } from "lucide-react";
import type { FormEvent } from "react";
import { formatCurrency } from "../domain/money.js";
import {
    dateInputValue,
    formatDate,
    type CafeDraft,
    type CafeRecord,
    type Plan,
} from "./platformSubscriptionTypes.js";

export function PlatformCafeSubscriptionsPanel({
    cafes,
    plans,
    drafts,
    savingCafeId,
    onDraftChange,
    onSave,
}: {
    cafes: CafeRecord[];
    plans: Plan[];
    drafts: Record<number, CafeDraft>;
    savingCafeId: number | null;
    onDraftChange: (cafeId: number, update: Partial<CafeDraft>) => void;
    onSave: (event: FormEvent<HTMLFormElement>, cafe: CafeRecord) => void;
}) {
    return (
        <>
            <header>
                <p className="eyebrow">Abonelik denetimi</p>
                <h1 className="mt-1 font-display text-2xl font-semibold">
                    Kafe paketleri ve süreleri
                </h1>
                <p className="mt-1 text-sm text-muted">
                    Kafenin planını, paket bitiş tarihini ve yönetici işlem notunu
                    düzenle. Her değişiklik önceki dönem kaydını saklar.
                </p>
            </header>
            {cafes.length === 0 ? (
                <div className="card p-6 text-sm text-muted">Kayıtlı kafe yok.</div>
            ) : (
                <div className="space-y-3">
                    {cafes.map((cafe) => {
                        const draft = drafts[cafe.id];
                        if (!draft) return null;
                        return (
                            <article className="card p-4 sm:p-5" key={cafe.id}>
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <Store className="size-4 text-forest" />
                                            <h2 className="font-display text-xl font-semibold">
                                                {cafe.name}
                                            </h2>
                                        </div>
                                        <p className="mt-1 text-sm text-muted">
                                            {cafe.subscription
                                                ? `${cafe.subscription.planName} · ${cafe.subscriptionState === "current" ? `${cafe.daysRemaining} gün kaldı` : cafe.subscriptionState === "scheduled" ? `Başlangıç ${formatDate(cafe.subscription.startsAt)}` : "Süresi doldu"} · ${formatDate(cafe.subscription.endsAt)} bitiş`
                                                : "Paket atanmamış"}
                                        </p>
                                    </div>
                                    {cafe.pendingRequest && (
                                        <span className="badge-pending">
                                            Bekleyen talep: {cafe.pendingRequest.planName}
                                        </span>
                                    )}
                                </div>
                                <form
                                    className="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-[1fr_1fr_1.2fr_auto]"
                                    onSubmit={(event) => onSave(event, cafe)}
                                >
                                    <label>
                                        <span className="label">Paket</span>
                                        <select
                                            className="input"
                                            onChange={(event) =>
                                                onDraftChange(cafe.id, {
                                                    planId: event.target.value,
                                                })
                                            }
                                            value={draft.planId}
                                        >
                                            {plans
                                                .filter(
                                                    (plan) =>
                                                        plan.isActive ||
                                                        plan.id === cafe.subscription?.planId,
                                                )
                                                .map((plan) => (
                                                    <option key={plan.id} value={plan.id}>
                                                        {plan.name} · {formatCurrency(plan.annualPriceCents)} / yıl
                                                    </option>
                                                ))}
                                        </select>
                                    </label>
                                    <label>
                                        <span className="label">Paket bitiş tarihi</span>
                                        <input
                                            className="input"
                                            min={dateInputValue()}
                                            onChange={(event) =>
                                                onDraftChange(cafe.id, {
                                                    endsOn: event.target.value,
                                                })
                                            }
                                            required
                                            type="date"
                                            value={draft.endsOn}
                                        />
                                    </label>
                                    <label>
                                        <span className="label">İşlem notu</span>
                                        <input
                                            className="input"
                                            maxLength={250}
                                            minLength={3}
                                            onChange={(event) =>
                                                onDraftChange(cafe.id, {
                                                    reason: event.target.value,
                                                })
                                            }
                                            placeholder="Örn. ödeme teyidi"
                                            required
                                            value={draft.reason}
                                        />
                                    </label>
                                    <button
                                        className="btn-primary self-end"
                                        disabled={
                                            savingCafeId !== null ||
                                            Boolean(cafe.pendingRequest)
                                        }
                                        type="submit"
                                    >
                                        {savingCafeId === cafe.id ? (
                                            <LoaderCircle className="size-4 animate-spin" />
                                        ) : (
                                            <CalendarClock className="size-4" />
                                        )}
                                        Süreyi kaydet
                                    </button>
                                </form>
                                {cafe.pendingRequest && (
                                    <p className="mt-2 text-xs text-muted">
                                        Doğrudan düzenleme için önce paket talebi
                                        ekranında bekleyen isteği sonuçlandır.
                                    </p>
                                )}
                            </article>
                        );
                    })}
                </div>
            )}
        </>
    );
}
