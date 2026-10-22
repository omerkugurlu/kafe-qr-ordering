import {
    ArrowLeft,
    BadgeCheck,
    CircleUserRound,
    Clock3,
    Coffee,
    ReceiptText,
    RotateCw,
    Star,
    Wallet,
} from "lucide-react";
import { useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { useAuth } from "../auth/AuthProvider.js";
import { apiPost, apiRequest, ApiClientError } from "../lib/api.js";
import { formatCurrency } from "../domain/money.js";

interface Order {
    id: number;
    number: string;
    status: string;
    totalCents: number;
    createdAt: string;
    items: { id: number; name: string; quantity: number }[];
}

function statusLabel(status: string) {
    return (
        (
            {
                pending: "Alındı",
                accepted: "Hazırlanıyor",
                ready: "Hazır",
                served: "Teslim edildi",
                paid: "Ödendi",
                cancelled: "İptal edildi",
            } as Record<string, string>
        )[status] ?? status
    );
}

function timeLabel(date: string) {
    return new Intl.DateTimeFormat("tr-TR", {
        dateStyle: "medium",
        timeStyle: "short",
        timeZone: "Europe/Istanbul",
    }).format(new Date(date));
}

export function CustomerBillPage() {
    const { token = "" } = useParams();
    const [payload, setPayload] = useState<any>(null);
    const [error, setError] = useState("");
    const [toast, setToast] = useState("");
    const { csrfToken } = useAuth();

    const refresh = useCallback(async () => {
        try {
            setPayload(
                await apiRequest(
                    `/api/tables/${encodeURIComponent(token)}/bill`,
                ),
            );
            setError("");
        } catch (reason) {
            setError(
                reason instanceof ApiClientError
                    ? reason.message
                    : "Adisyon alınamadı.",
            );
        }
    }, [token]);
    useEffect(() => {
        void refresh();
        const timer = window.setInterval(() => void refresh(), 5000);
        return () => window.clearInterval(timer);
    }, [refresh]);

    async function requestBill() {
        try {
            const data = await apiPost<{ message: string }>(
                `/api/tables/${encodeURIComponent(token)}/service-requests`,
                { type: "bill" },
                csrfToken,
            );
            setToast(data.message);
        } catch (reason) {
            setToast(
                reason instanceof ApiClientError
                    ? reason.message
                    : "Hesap isteği gönderilemedi.",
            );
        }
    }

    return (
        <main className="min-h-dvh px-4 py-5 sm:px-8 sm:py-8">
            <div className="mx-auto max-w-3xl">
                <Link
                    className="inline-flex items-center gap-2 text-sm font-semibold text-muted hover:text-forest"
                    to={`/masa/${encodeURIComponent(token)}`}
                >
                    <ArrowLeft className="size-4" /> Menüye dön
                </Link>
                <header className="mt-7 flex items-end justify-between gap-3">
                    <div>
                        <p className="eyebrow">Masandaki adisyon</p>
                        <h1 className="mt-1 font-display text-3xl font-semibold">
                            Hesabım
                        </h1>
                        <p className="mt-1 text-sm text-muted">
                            Tutarlar otomatik güncellenir.
                        </p>
                    </div>
                    <button
                        aria-label="Adisyonu yenile"
                        className="btn-icon"
                        onClick={() => void refresh()}
                        type="button"
                    >
                        <RotateCw className="size-4" />
                    </button>
                </header>
                {error && !payload && (
                    <div className="card mt-6 p-5 text-sm text-danger">
                        {error}
                    </div>
                )}
                {payload?.session ? (
                    <>
                        <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
                            <BillMetric
                                icon={<ReceiptText className="size-4" />}
                                label="Adisyon"
                                value={formatCurrency(
                                    payload.session.totalCents,
                                )}
                            />
                            <BillMetric
                                icon={<Wallet className="size-4" />}
                                label="Ödenen"
                                value={formatCurrency(
                                    payload.session.paidCents,
                                )}
                            />
                            <BillMetric
                                icon={<Clock3 className="size-4" />}
                                label="Kalan"
                                value={formatCurrency(payload.session.dueCents)}
                            />
                        </div>
                        <section className="card mt-4 p-4 sm:p-6">
                            <div className="flex items-center justify-between gap-2">
                                <h2 className="font-display text-xl font-semibold">
                                    Siparişler
                                </h2>
                                <span className="badge-neutral">
                                    {payload.table}
                                </span>
                            </div>
                            <div className="mt-4 space-y-4">
                                {payload.session.orders.map((order: Order) => (
                                    <article
                                        className="border-t border-line pt-4 first:border-0 first:pt-0"
                                        key={order.id}
                                    >
                                        <div className="flex items-center justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-bold">
                                                    Sipariş #{order.number}
                                                </p>
                                                <p className="mt-0.5 text-xs text-muted">
                                                    {timeLabel(order.createdAt)}
                                                </p>
                                            </div>
                                            <span
                                                className={`badge ${order.status === "pending" ? "badge-pending" : order.status === "accepted" ? "badge-preparing" : order.status === "ready" ? "badge-ready" : order.status === "paid" ? "badge-paid" : "badge-served"}`}
                                            >
                                                {statusLabel(order.status)}
                                            </span>
                                        </div>
                                        <div className="mt-2 flex justify-between gap-3 text-sm">
                                            <p className="text-muted">
                                                {order.items
                                                    .map(
                                                        (item) =>
                                                            `${item.quantity} × ${item.name}`,
                                                    )
                                                    .join(" · ")}
                                            </p>
                                            <strong className="shrink-0">
                                                {formatCurrency(
                                                    order.totalCents,
                                                )}
                                            </strong>
                                        </div>
                                    </article>
                                ))}
                            </div>
                            <div className="mt-5 border-t border-line pt-4">
                                {payload.session.payments.map(
                                    (payment: any) => (
                                        <div
                                            className="flex justify-between py-1.5 text-xs"
                                            key={payment.id}
                                        >
                                            <span
                                                className={
                                                    payment.voided
                                                        ? "text-muted line-through"
                                                        : "text-muted"
                                                }
                                            >
                                                {payment.method === "cash"
                                                    ? "Nakit"
                                                    : "Kart"}
                                                {payment.personLabel
                                                    ? ` · ${payment.personLabel}`
                                                    : ""}
                                                {payment.voided
                                                    ? " · İptal edildi"
                                                    : ""}
                                            </span>
                                            <span
                                                className={
                                                    payment.voided
                                                        ? "text-muted line-through"
                                                        : "font-semibold"
                                                }
                                            >
                                                {formatCurrency(
                                                    payment.amountCents,
                                                )}
                                            </span>
                                        </div>
                                    ),
                                )}
                            </div>
                            {payload.session.canRequestBill && (
                                <button
                                    className="btn-primary mt-5 w-full rounded-2xl"
                                    onClick={() => void requestBill()}
                                    type="button"
                                >
                                    Garsondan hesabı iste
                                </button>
                            )}
                        </section>
                    </>
                ) : (
                    <section className="card mt-6 p-8 text-center">
                        <ReceiptText className="mx-auto size-8 text-muted/50" />
                        <h2 className="mt-3 font-display text-xl font-semibold">
                            Açık adisyon yok
                        </h2>
                        <p className="mt-1 text-sm text-muted">
                            Yeni sipariş veya açık hesap görünmüyor.
                        </p>
                    </section>
                )}
                {toast && (
                    <p
                        aria-live="polite"
                        className="toast-success fixed bottom-5 left-1/2 w-max max-w-[calc(100%-2rem)] -translate-x-1/2"
                    >
                        {toast}
                    </p>
                )}
            </div>
        </main>
    );
}

function BillMetric({
    icon,
    label,
    value,
}: {
    icon: React.ReactNode;
    label: string;
    value: string;
}) {
    return (
        <div className="kpi p-4 sm:p-5">
            <span className="inline-flex items-center gap-2 text-xs font-semibold text-muted">
                {icon}
                {label}
            </span>
            <p className="mt-2 font-display text-xl font-semibold sm:text-2xl">
                {value}
            </p>
        </div>
    );
}

export function CustomerAccountPage() {
    const { token = "" } = useParams();
    const { user, logout } = useAuth();
    const [payload, setPayload] = useState<any>(null);
    const [error, setError] = useState("");

    useEffect(() => {
        apiRequest(`/api/tables/${encodeURIComponent(token)}/account`)
            .then(setPayload)
            .catch((reason: unknown) =>
                setError(
                    reason instanceof ApiClientError
                        ? reason.message
                        : "Hesap bilgisi alınamadı.",
                ),
            );
    }, [token]);

    if (error)
        return (
            <main className="grid min-h-dvh place-items-center p-5">
                <section className="card w-full max-w-md p-7 text-center">
                    <CircleUserRound className="mx-auto size-9 text-forest" />
                    <h1 className="mt-4 font-display text-2xl font-semibold">
                        Müşteri hesabım
                    </h1>
                    <p className="mt-2 text-sm leading-6 text-muted">{error}</p>
                    <div className="mt-5 grid grid-cols-2 gap-2">
                        <Link
                            className="btn-secondary"
                            to={`/masa/${encodeURIComponent(token)}/giris`}
                        >
                            Giriş yap
                        </Link>
                        <Link
                            className="btn-primary"
                            to={`/masa/${encodeURIComponent(token)}/kayit`}
                        >
                            Hesap oluştur
                        </Link>
                    </div>
                </section>
            </main>
        );
    if (!payload)
        return (
            <main className="grid min-h-dvh place-items-center">
                <div className="skeleton h-64 w-[min(90vw,40rem)]" />
            </main>
        );

    return (
        <main className="min-h-dvh px-4 py-5 sm:px-8 sm:py-8">
            <div className="mx-auto max-w-4xl">
                <Link
                    className="inline-flex items-center gap-2 text-sm font-semibold text-muted hover:text-forest"
                    to={`/masa/${encodeURIComponent(token)}`}
                >
                    <ArrowLeft className="size-4" /> Menüye dön
                </Link>
                <header className="mt-7">
                    <p className="eyebrow">Kişisel alanın</p>
                    <h1 className="mt-1 font-display text-3xl font-semibold">
                        Merhaba, {payload.customer.name.split(" ")[0]}.
                    </h1>
                    <p className="mt-1 text-sm text-muted">
                        {payload.customer.email}
                    </p>
                </header>
                <section className="mt-5 grid gap-3 sm:grid-cols-3">
                    <div className="kpi bg-forest text-white">
                        <span className="inline-flex items-center gap-2 text-xs font-semibold text-white/70">
                            <Star className="size-4 text-amber" /> Biriken puan
                        </span>
                        <p className="mt-2 font-display text-3xl font-semibold">
                            {payload.loyalty.points}
                        </p>
                        <p className="mt-1 text-xs text-white/60">
                            Sonraki ödülün için puan biriktir.
                        </p>
                    </div>
                    <div className="kpi">
                        <span className="inline-flex items-center gap-2 text-xs font-semibold text-muted">
                            <BadgeCheck className="size-4 text-forest" />{" "}
                            Tamamlanan sipariş
                        </span>
                        <p className="mt-2 font-display text-3xl font-semibold">
                            {payload.loyalty.completedOrders}
                        </p>
                        <p className="mt-1 text-xs text-muted">
                            Ödenmiş siparişlerin
                        </p>
                    </div>
                    <div className="kpi">
                        <span className="inline-flex items-center gap-2 text-xs font-semibold text-muted">
                            <Wallet className="size-4 text-terracotta" />{" "}
                            Kazandığın indirim
                        </span>
                        <p className="mt-2 font-display text-3xl font-semibold">
                            {formatCurrency(payload.loyalty.savedCents)}
                        </p>
                        <p className="mt-1 text-xs text-muted">
                            Kullandığın ödül ve kuponlar
                        </p>
                    </div>
                </section>
                <section className="card mt-4 p-4 sm:p-6">
                    <div className="flex items-center justify-between">
                        <h2 className="font-display text-xl font-semibold">
                            Ödül ve puan hareketleri
                        </h2>
                        <span className="badge-neutral">
                            {payload.rewards.length} kayıt
                        </span>
                    </div>
                    {payload.rewards.length === 0 ? (
                        <div className="py-8 text-center">
                            <Star className="mx-auto size-7 text-muted/40" />
                            <p className="mt-2 text-sm font-semibold">
                                Henüz bir hareket yok
                            </p>
                            <p className="mt-1 text-xs text-muted">
                                İlk ödenmiş siparişinden sonra puanların burada
                                görünür.
                            </p>
                        </div>
                    ) : (
                        <div className="mt-3 divide-y divide-line">
                            {payload.rewards.map(
                                (reward: any, index: number) => (
                                    <article
                                        className="flex items-center justify-between gap-4 py-3"
                                        key={`${reward.createdAt}-${index}`}
                                    >
                                        <div>
                                            <p className="text-sm font-semibold">
                                                {reward.description}
                                            </p>
                                            <p className="mt-0.5 text-xs text-muted">
                                                {timeLabel(reward.createdAt)}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            {reward.pointsDelta !== 0 && (
                                                <p
                                                    className={`text-sm font-bold ${reward.pointsDelta > 0 ? "text-success" : "text-terracotta"}`}
                                                >
                                                    {reward.pointsDelta > 0
                                                        ? "+"
                                                        : ""}
                                                    {reward.pointsDelta} puan
                                                </p>
                                            )}
                                            {reward.discountCents > 0 && (
                                                <p className="text-xs font-semibold text-forest">
                                                    {formatCurrency(
                                                        reward.discountCents,
                                                    )}{" "}
                                                    indirim
                                                </p>
                                            )}
                                        </div>
                                    </article>
                                ),
                            )}
                        </div>
                    )}
                </section>
                <section className="card mt-4 p-4 sm:p-6">
                    <div className="flex items-center justify-between">
                        <h2 className="font-display text-xl font-semibold">
                            Son siparişlerim
                        </h2>
                        <Coffee className="size-5 text-forest" />
                    </div>
                    {payload.orders.length === 0 ? (
                        <p className="py-8 text-center text-sm text-muted">
                            Henüz siparişin yok.
                        </p>
                    ) : (
                        <div className="mt-3 divide-y divide-line">
                            {payload.orders.map((order: Order) => (
                                <Link
                                    className="flex items-center justify-between gap-3 py-3 hover:text-forest"
                                    key={order.id}
                                    to={`/masa/${encodeURIComponent(token)}/siparis/${order.number}`}
                                >
                                    <div className="min-w-0">
                                        <p className="text-sm font-bold">
                                            Sipariş #{order.number}
                                        </p>
                                        <p className="mt-0.5 truncate text-xs text-muted">
                                            {order.items
                                                .map(
                                                    (item) =>
                                                        `${item.quantity} × ${item.name}`,
                                                )
                                                .join(" · ")}
                                        </p>
                                        <p className="mt-0.5 text-[11px] text-muted">
                                            {timeLabel(order.createdAt)}
                                        </p>
                                    </div>
                                    <div className="shrink-0 text-right">
                                        <p className="text-sm font-bold">
                                            {formatCurrency(order.totalCents)}
                                        </p>
                                        <span className="text-xs font-semibold text-forest">
                                            {statusLabel(order.status)}
                                        </span>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    )}
                </section>
                <div className="mt-5 flex justify-between text-xs text-muted">
                    <span>Hesap hareketleri bu kafeye özeldir.</span>
                    {user && (
                        <button
                            className="font-bold text-forest"
                            onClick={() =>
                                void logout().then(() =>
                                    window.location.assign(
                                        `/masa/${encodeURIComponent(token)}`,
                                    ),
                                )
                            }
                            type="button"
                        >
                            Çıkış yap
                        </button>
                    )}
                </div>
            </div>
        </main>
    );
}

export function OrderTrackingPage() {
    const { token = "", orderNumber = "" } = useParams();
    const [order, setOrder] = useState<any>(null);
    const [error, setError] = useState("");
    const refresh = useCallback(
        () =>
            apiRequest<{ order: any }>(
                `/api/tables/${encodeURIComponent(token)}/orders/${encodeURIComponent(orderNumber)}`,
            )
                .then((data) => {
                    setOrder(data.order);
                    setError("");
                })
                .catch((reason: unknown) =>
                    setError(
                        reason instanceof ApiClientError
                            ? reason.message
                            : "Sipariş bilgisi alınamadı.",
                    ),
                ),
        [orderNumber, token],
    );
    useEffect(() => {
        void refresh();
        const timer = window.setInterval(() => void refresh(), 5000);
        return () => window.clearInterval(timer);
    }, [refresh]);
    const stages = [
        {
            id: "pending",
            title: "Sipariş alındı",
            detail: "Garson siparişini kontrol ediyor.",
        },
        {
            id: "accepted",
            title: "Hazırlanıyor",
            detail: "Kafe siparişini hazırlıyor.",
        },
        { id: "ready", title: "Hazır", detail: "Siparişin masaya geliyor." },
        { id: "served", title: "Teslim edildi", detail: "Afiyet olsun!" },
    ];
    const currentIndex = order
        ? order.status === "paid"
            ? stages.length
            : stages.findIndex((stage) => stage.id === order.status)
        : -1;
    return (
        <main className="min-h-dvh px-4 py-5 sm:px-8 sm:py-8">
            <div className="mx-auto max-w-2xl">
                <Link
                    className="inline-flex items-center gap-2 text-sm font-semibold text-muted hover:text-forest"
                    to={`/masa/${encodeURIComponent(token)}`}
                >
                    <ArrowLeft className="size-4" /> Menüye dön
                </Link>
                <section className="card mt-7 overflow-hidden">
                    <div className="bg-forest px-5 py-7 text-white sm:px-8">
                        <p className="text-[10px] font-bold uppercase tracking-[.18em] text-amber">
                            Canlı sipariş takibi
                        </p>
                        <h1 className="mt-2 font-display text-3xl font-semibold">
                            {order
                                ? `Sipariş #${order.number}`
                                : "Sipariş durumun"}
                        </h1>
                        <p className="mt-1 text-sm text-white/65">
                            Durum otomatik olarak yenilenir.
                        </p>
                    </div>
                    <div className="p-5 sm:p-8">
                        {error && !order && (
                            <p className="rounded-2xl bg-danger-soft p-4 text-sm text-danger">
                                {error}
                            </p>
                        )}
                        {order && order.status === "cancelled" ? (
                            <div className="rounded-2xl bg-danger-soft p-4">
                                <p className="font-bold text-danger">
                                    Sipariş iptal edildi
                                </p>
                                {order.cancelReason && (
                                    <p className="mt-1 text-sm text-danger/80">
                                        {order.cancelReason}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <div className="space-y-0">
                                {stages.map((stage, index) => {
                                    const complete = index < currentIndex;
                                    const active = index === currentIndex;
                                    return (
                                        <div
                                            className="relative flex gap-4 pb-7 last:pb-0"
                                            key={stage.id}
                                        >
                                            <div className="flex w-7 shrink-0 flex-col items-center">
                                                <span
                                                    className={`grid size-7 place-items-center rounded-full text-xs font-bold ${complete || active ? "bg-forest text-white" : "bg-ink/5 text-muted"}`}
                                                >
                                                    {complete ? "✓" : index + 1}
                                                </span>
                                                {index < stages.length - 1 && (
                                                    <span
                                                        className={`mt-1 min-h-7 w-0.5 flex-1 ${complete ? "bg-forest" : "bg-line"}`}
                                                    />
                                                )}
                                            </div>
                                            <div className="pb-1">
                                                <p
                                                    className={`font-semibold ${active ? "text-forest" : complete ? "text-ink" : "text-muted"}`}
                                                >
                                                    {stage.title}
                                                </p>
                                                <p className="mt-0.5 text-sm text-muted">
                                                    {active
                                                        ? stage.detail
                                                        : stage.id ===
                                                                "pending" &&
                                                            complete
                                                          ? "Siparişin kafeye iletildi."
                                                          : stage.id ===
                                                                  "accepted" &&
                                                              complete
                                                            ? "Sipariş hazırlanmaya alındı."
                                                            : stage.detail}
                                                </p>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                        {order?.status === "paid" && (
                            <div className="mt-4 rounded-2xl bg-success-soft p-4 text-sm font-semibold text-success">
                                Ödemen alındı, masa hesabı kapandı.{" "}
                                {formatCurrency(order.totalCents)}
                            </div>
                        )}
                        {order && (
                            <div className="mt-5 border-t border-line pt-4">
                                <div className="flex justify-between text-sm">
                                    <span className="text-muted">
                                        Sipariş toplamı
                                    </span>
                                    <strong>
                                        {formatCurrency(order.totalCents)}
                                    </strong>
                                </div>
                                <div className="mt-3 space-y-2">
                                    {order.items.map((item: any) => (
                                        <div
                                            className="flex justify-between text-sm"
                                            key={item.id}
                                        >
                                            <span>
                                                {item.quantity} × {item.name}
                                            </span>
                                            <span className="text-muted">
                                                {formatCurrency(
                                                    item.lineTotalCents,
                                                )}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                                <Link
                                    className="btn-secondary mt-5 w-full rounded-2xl"
                                    to={`/masa/${encodeURIComponent(token)}/adisyon`}
                                >
                                    Masadaki adisyonu görüntüle{" "}
                                    <ReceiptText className="size-4" />
                                </Link>
                            </div>
                        )}
                    </div>
                </section>
            </div>
        </main>
    );
}
