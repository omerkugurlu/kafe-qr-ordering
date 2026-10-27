import { BadgeCheck, CalendarClock, Check, LoaderCircle } from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import {
    subscriptionFeatureLabels,
    subscriptionFeatureKeys,
} from "../domain/subscriptions.js";
import { formatCurrency } from "../domain/money.js";
import { ApiClientError, apiPost, apiRequest } from "../lib/api.js";

type SubscriptionFeatureMap = Record<
    keyof typeof subscriptionFeatureLabels,
    boolean
>;
interface Plan {
    id: number;
    name: string;
    annualPriceCents: number;
    maxTables: number;
    maxBranches: number | null;
    features: SubscriptionFeatureMap;
    isActive: boolean;
}
interface Subscription {
    id: number;
    planName: string;
    startsAt: string;
    endsAt: string;
    annualPriceCents: number;
    maxTables: number;
    maxBranches: number | null;
    features: SubscriptionFeatureMap;
}
interface RequestHistory {
    id: number;
    planName: string;
    annualPriceCents: number;
    status: "pending" | "approved" | "rejected";
    requestedAt: string;
    reviewedAt: string | null;
    reviewNote: string | null;
}
interface SubscriptionOverview {
    subscription: Subscription | null;
    scheduledSubscription: Subscription | null;
    latestExpiredSubscription: Subscription | null;
    daysRemaining: number;
    usage: { tables: number; branches: number };
    activePlanIds: number[];
    renewablePlanId: number | null;
    pendingRequest: RequestHistory | null;
    requests: RequestHistory[];
    plans: Plan[];
}

function errorMessage(error: unknown): string {
    return error instanceof ApiClientError
        ? error.message
        : "Paket bilgileri alınamadı. Sayfayı yenileyin.";
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat("tr-TR", {
        dateStyle: "medium",
        timeZone: "Europe/Istanbul",
    }).format(new Date(value));
}

function requestStatus(status: RequestHistory["status"]): string {
    return status === "pending"
        ? "Yönetici incelemesinde"
        : status === "approved"
          ? "Onaylandı"
          : "Reddedildi";
}

export function CafeSubscriptionPanel({
    csrfToken,
}: {
    csrfToken: string | null;
}) {
    const [data, setData] = useState<SubscriptionOverview | null>(null);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [busyPlanId, setBusyPlanId] = useState<number | null>(null);

    const refresh = useCallback(async () => {
        try {
            setData(
                await apiRequest<SubscriptionOverview>(
                    "/api/manage/subscription",
                ),
            );
            setError("");
        } catch (reason) {
            setError(errorMessage(reason));
        }
    }, []);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    async function requestPlan(plan: Plan) {
        setBusyPlanId(plan.id);
        setError("");
        setNotice("");
        try {
            await apiPost(
                "/api/manage/subscription-requests",
                { planId: plan.id },
                csrfToken,
            );
            setNotice(
                `${plan.name} paketi talebiniz yöneticiye gönderildi. Paket, manuel ödeme kontrolünden sonra etkinleşir.`,
            );
            await refresh();
        } catch (reason) {
            setError(errorMessage(reason));
        } finally {
            setBusyPlanId(null);
        }
    }

    if (!data)
        return (
            <section className="card mt-5 flex items-center gap-3 p-5 text-sm text-muted">
                <LoaderCircle className="size-4 animate-spin" /> Paket bilgileri
                yükleniyor…
                {error && <span className="text-danger">{error}</span>}
            </section>
        );

    const current = data.subscription;
    const next = data.scheduledSubscription;
    const visibleCurrent = current ?? data.latestExpiredSubscription;

    return (
        <section className="mt-5 space-y-5">
            {(error || notice) && (
                <div
                    aria-live="polite"
                    className={`rounded-2xl p-3 text-sm font-semibold ${error ? "bg-danger-soft text-danger" : "bg-success-soft text-success"}`}
                >
                    {error || notice}
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-[1.2fr_0.8fr]">
                <section className="card p-5 sm:p-6">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="eyebrow">Mevcut paket</p>
                            <h1 className="mt-1 font-display text-2xl font-semibold">
                                {visibleCurrent?.planName ?? "Henüz paket yok"}
                            </h1>
                        </div>
                        <span
                            className={
                                current ? "badge-paid" : "badge-cancelled"
                            }
                        >
                            {current
                                ? `${data.daysRemaining} gün kaldı`
                                : visibleCurrent
                                  ? "Süresi doldu"
                                  : "Paket bekleniyor"}
                        </span>
                    </div>
                    {visibleCurrent ? (
                        <>
                            <p className="mt-2 text-sm text-muted">
                                {formatCurrency(
                                    visibleCurrent.annualPriceCents,
                                )}{" "}
                                / yıl · {formatDate(visibleCurrent.startsAt)}–
                                {formatDate(visibleCurrent.endsAt)}
                            </p>
                            <div className="mt-5 grid gap-2 sm:grid-cols-3">
                                <UsageCard
                                    label="Masa kullanımı"
                                    value={`${data.usage.tables} / ${visibleCurrent.maxTables}`}
                                />
                                <UsageCard
                                    label="Şube kullanımı"
                                    value={`${data.usage.branches} / ${visibleCurrent.maxBranches ?? "Sınırsız"}`}
                                />
                                <UsageCard
                                    label="Yenileme tarihi"
                                    value={formatDate(visibleCurrent.endsAt)}
                                />
                            </div>
                            <div className="mt-4 flex flex-wrap gap-2">
                                {subscriptionFeatureKeys.map((key) => (
                                    <span className="badge-neutral" key={key}>
                                        {visibleCurrent.features[key] ? (
                                            <Check className="mr-1 inline size-3 text-success" />
                                        ) : null}
                                        {subscriptionFeatureLabels[key]}
                                    </span>
                                ))}
                            </div>
                        </>
                    ) : (
                        <p className="mt-2 text-sm leading-6 text-muted">
                            Kullanımın başlaması için yıllık paketlerden birini
                            seçip yönetici onayına gönder.
                        </p>
                    )}
                </section>

                <section className="card p-5 sm:p-6">
                    <p className="eyebrow">Paket talebi</p>
                    {data.pendingRequest ? (
                        <>
                            <h2 className="mt-1 font-display text-xl font-semibold">
                                Talebin inceleniyor
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-muted">
                                {data.pendingRequest.planName} ·{" "}
                                {formatCurrency(
                                    data.pendingRequest.annualPriceCents,
                                )}{" "}
                                / yıl ·{" "}
                                {formatDate(data.pendingRequest.requestedAt)}{" "}
                                tarihinde gönderildi.
                            </p>
                            <p className="mt-3 rounded-2xl bg-cream p-3 text-sm text-muted">
                                Ödeme ve etkinleştirme süper yönetici tarafından
                                manuel olarak doğrulanır.
                            </p>
                        </>
                    ) : next ? (
                        <>
                            <h2 className="mt-1 font-display text-xl font-semibold">
                                Sıradaki paket: {next.planName}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-muted">
                                {formatDate(next.startsAt)} tarihinde başlar.
                                Yeni bir talep açmadan önce bu paketin döneminin
                                başlamasını bekleyin.
                            </p>
                        </>
                    ) : (
                        <>
                            <h2 className="mt-1 font-display text-xl font-semibold">
                                {data.requests.length
                                    ? "Yeni paket seç"
                                    : "Henüz talep yok"}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-muted">
                                Yıllık paketi seç; süper yönetici talebi ve
                                manuel ödeme kontrolünü tamamlayınca
                                etkinleşsin.
                            </p>
                        </>
                    )}
                </section>
            </div>

            <section>
                <div className="mb-3">
                    <p className="eyebrow">Yıllık paketler</p>
                    <h2 className="mt-1 font-display text-2xl font-semibold">
                        İşletmene uygun planı seç
                    </h2>
                    <p className="mt-1 text-sm text-muted">
                        Paket süresi 12 aydır. Talep yönetici onayına gider;
                        çevrimiçi ödeme alınmaz.
                    </p>
                </div>
                <div className="grid gap-4 lg:grid-cols-3">
                    {data.plans.map((plan) => {
                        const selected = data.activePlanIds.includes(plan.id);
                        const canRenew =
                            data.renewablePlanId === plan.id &&
                            !data.pendingRequest &&
                            !next;
                        const disabled = Boolean(
                            data.pendingRequest ||
                            next ||
                            (selected && !canRenew) ||
                            busyPlanId !== null,
                        );
                        return (
                            <article
                                className="card flex flex-col p-5"
                                key={plan.id}
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <h3 className="font-display text-xl font-semibold">
                                            {plan.name}
                                        </h3>
                                        <p className="mt-1 text-xs uppercase tracking-[0.16em] text-muted">
                                            {plan.maxTables} masa ·{" "}
                                            {plan.maxBranches ?? "Sınırsız"}{" "}
                                            şube
                                        </p>
                                    </div>
                                    {selected && (
                                        <span className="badge-paid">
                                            {canRenew
                                                ? "Mevcut paket"
                                                : "Aktif"}
                                        </span>
                                    )}
                                </div>
                                <p className="mt-4 font-display text-2xl font-semibold text-forest">
                                    {formatCurrency(plan.annualPriceCents)}
                                    <span className="ml-1 font-sans text-sm font-normal text-muted">
                                        / yıl
                                    </span>
                                </p>
                                <ul className="my-5 flex-1 space-y-2 border-t border-line pt-4 text-sm">
                                    <li className="flex gap-2">
                                        <Check className="size-4 shrink-0 text-success" />
                                        QR menü, masadan sipariş ve
                                        kategori/ürün yönetimi
                                    </li>
                                    {subscriptionFeatureKeys.map((key) => (
                                        <li
                                            className={`flex gap-2 ${plan.features[key] ? "text-ink" : "text-muted"}`}
                                            key={key}
                                        >
                                            {plan.features[key] ? (
                                                <Check className="size-4 shrink-0 text-success" />
                                            ) : (
                                                <span className="w-4 shrink-0 text-center">
                                                    —
                                                </span>
                                            )}
                                            {subscriptionFeatureLabels[key]}
                                        </li>
                                    ))}
                                </ul>
                                <button
                                    className="btn-primary w-full"
                                    disabled={disabled}
                                    onClick={() => void requestPlan(plan)}
                                    type="button"
                                >
                                    {busyPlanId === plan.id ? (
                                        <LoaderCircle className="size-4 animate-spin" />
                                    ) : (
                                        <BadgeCheck className="size-4" />
                                    )}
                                    {selected
                                        ? canRenew
                                            ? "Yenileme talep et"
                                            : "Bu paket aktif"
                                        : data.pendingRequest
                                          ? "Bekleyen talep var"
                                          : next
                                            ? "Sıradaki paket mevcut"
                                            : "Bu paketi talep et"}
                                </button>
                            </article>
                        );
                    })}
                </div>
            </section>

            {data.requests.length > 0 && (
                <section className="card p-5">
                    <div className="flex items-center gap-2">
                        <CalendarClock className="size-4 text-forest" />
                        <h2 className="font-display text-xl font-semibold">
                            Paket talep geçmişi
                        </h2>
                    </div>
                    <div className="mt-3 divide-y divide-line">
                        {data.requests.map((item) => (
                            <div
                                className="flex flex-wrap items-center justify-between gap-2 py-3 text-sm"
                                key={item.id}
                            >
                                <span className="font-semibold">
                                    {item.planName} ·{" "}
                                    {formatCurrency(item.annualPriceCents)} /
                                    yıl
                                </span>
                                <span className="text-muted">
                                    {requestStatus(item.status)} ·{" "}
                                    {formatDate(item.requestedAt)}
                                </span>
                                {item.reviewNote && (
                                    <p className="basis-full text-muted">
                                        Yönetici notu: {item.reviewNote}
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                </section>
            )}
        </section>
    );
}

function UsageCard({ label, value }: { label: string; value: string }) {
    return (
        <div className="rounded-2xl bg-cream p-3">
            <p className="eyebrow">{label}</p>
            <p className="mt-1 font-display text-lg font-semibold">{value}</p>
        </div>
    );
}
