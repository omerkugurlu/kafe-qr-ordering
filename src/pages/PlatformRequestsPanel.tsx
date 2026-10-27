import { BadgeCheck, LoaderCircle, Store } from "lucide-react";
import {
    subscriptionFeatureLabels,
    subscriptionFeatureKeys,
} from "../domain/subscriptions.js";
import { formatCurrency } from "../domain/money.js";
import { formatDate, type SubscriptionRequest } from "./platformSubscriptionTypes.js";

export function PlatformRequestsPanel({
    requests,
    paymentConfirmed,
    rejectionNotes,
    busy,
    onPaymentChange,
    onNoteChange,
    onApprove,
    onReject,
}: {
    requests: SubscriptionRequest[];
    paymentConfirmed: Record<number, boolean>;
    rejectionNotes: Record<number, string>;
    busy: boolean;
    onPaymentChange: (id: number, value: boolean) => void;
    onNoteChange: (id: number, value: string) => void;
    onApprove: (request: SubscriptionRequest) => void;
    onReject: (request: SubscriptionRequest) => void;
}) {
    return (
        <>
            <header>
                <p className="eyebrow">Manuel ödeme kontrolü</p>
                <h1 className="mt-1 font-display text-2xl font-semibold">
                    Paket talepleri
                </h1>
                <p className="mt-1 text-sm text-muted">
                    Yalnızca ödeme alındığını doğruladıktan sonra onay ver. Onay
                    yeni 12 aylık dönemi oluşturur.
                </p>
            </header>
            {requests.length === 0 ? (
                <div className="card p-6 text-sm text-muted">
                    Bekleyen paket talebi yok.
                </div>
            ) : (
                <div className="space-y-3">
                    {requests.map((item) => (
                        <article className="card p-4 sm:p-5" key={item.id}>
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex items-center gap-2">
                                        <Store className="size-4 text-forest" />
                                        <h2 className="font-display text-xl font-semibold">
                                            {item.cafeName}
                                        </h2>
                                    </div>
                                    <p className="mt-1 text-sm text-muted">
                                        {item.planName} ·{" "}
                                        {formatCurrency(item.annualPriceCents)} / yıl ·{" "}
                                        {item.maxTables} masa ·{" "}
                                        {item.maxBranches ?? "Sınırsız"} şube
                                    </p>
                                    <p className="mt-1 text-xs text-muted">
                                        {item.requester
                                            ? `${item.requester.name} · ${item.requester.email} · `
                                            : ""}
                                        {formatDate(item.requestedAt)}
                                    </p>
                                </div>
                                <span className="badge-pending">Bekliyor</span>
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {subscriptionFeatureKeys
                                    .filter((key) => item.features[key])
                                    .map((key) => (
                                        <span className="badge-neutral" key={key}>
                                            {subscriptionFeatureLabels[key]}
                                        </span>
                                    ))}
                            </div>
                            <div className="mt-4 grid gap-3 lg:grid-cols-[1fr_auto]">
                                <label className="flex items-center gap-2 rounded-2xl bg-cream p-3 text-sm">
                                    <input
                                        checked={paymentConfirmed[item.id] ?? false}
                                        onChange={(event) =>
                                            onPaymentChange(item.id, event.target.checked)
                                        }
                                        type="checkbox"
                                    />
                                    Ödeme alındı ve doğrulandı
                                </label>
                                <button
                                    className="btn-primary"
                                    disabled={busy || !paymentConfirmed[item.id]}
                                    onClick={() => onApprove(item)}
                                    type="button"
                                >
                                    {busy ? (
                                        <LoaderCircle className="size-4 animate-spin" />
                                    ) : (
                                        <BadgeCheck className="size-4" />
                                    )}
                                    Ödemeyi onayla ve etkinleştir
                                </button>
                            </div>
                            <div className="mt-3 grid gap-2 sm:grid-cols-[1fr_auto]">
                                <input
                                    className="input"
                                    maxLength={250}
                                    minLength={3}
                                    onChange={(event) =>
                                        onNoteChange(item.id, event.target.value)
                                    }
                                    placeholder="Reddetme açıklaması"
                                    value={rejectionNotes[item.id] ?? ""}
                                />
                                <button
                                    className="btn-secondary"
                                    disabled={busy}
                                    onClick={() => onReject(item)}
                                    type="button"
                                >
                                    Talebi reddet
                                </button>
                            </div>
                        </article>
                    ))}
                </div>
            )}
        </>
    );
}
