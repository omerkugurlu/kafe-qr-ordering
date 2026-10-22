import {
    ArrowLeft,
    Bell,
    Check,
    CheckCheck,
    ChefHat,
    CircleDollarSign,
    Clock3,
    Coffee,
    CreditCard,
    DoorOpen,
    LoaderCircle,
    LogOut,
    Plus,
    RefreshCw,
    ReceiptText,
    Utensils,
    Wallet,
    X,
} from "lucide-react";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, Navigate } from "react-router-dom";
import { useAuth } from "../auth/AuthProvider.js";
import { formatCurrency, toCents, toDecimal } from "../domain/money.js";
import { apiPost, apiRequest, ApiClientError } from "../lib/api.js";

type OrderStatus =
    "pending" | "accepted" | "ready" | "served" | "paid" | "cancelled";
interface StaffOrder {
    id: number;
    number: string;
    source: string;
    status: OrderStatus;
    totalCents: number;
    totalLabel: string;
    note: string | null;
    createdAt: string;
    tableName: string;
    sessionId: number;
    items: {
        id: number;
        name: string;
        quantity: number;
        note: string | null;
        options: { group: string; name: string }[];
    }[];
}
interface StaffTable {
    id: number;
    name: string;
    isOpen: boolean;
    sessionId: number | null;
    dueCents: number;
    dueLabel: string;
    activeOrders: number;
    qrOrderingEnabled: boolean;
}
interface ServiceRequest {
    id: number;
    table: string;
    type: "bill" | "waiter";
    typeLabel: string;
    note: string | null;
    claimedBy: number | null;
    claimedByName: string | null;
    isClaimedByMe: boolean;
}
interface BoardData {
    branch: { id: number; name: string } | null;
    summary: {
        pending: number;
        preparing: number;
        ready: number;
        openTables: number;
        requests: number;
    };
    orders: StaffOrder[];
    tables: StaffTable[];
    requests: ServiceRequest[];
    message?: string;
}
interface SessionData {
    session: {
        id: number;
        status: string;
        orders: StaffOrder[];
        totalCents: number;
        paidCents: number;
        dueCents: number;
        dueLabel: string;
        payments: {
            id: number;
            method: string;
            amountCents: number;
            personLabel: string | null;
            voided: boolean;
        }[];
    };
    tableName: string;
    branchName: string;
}
interface Product {
    id: number;
    name: string;
    categoryId: number;
    priceCents: number;
    optionGroups: {
        id: number;
        name: string;
        selectionMode: "single" | "multiple";
        minSelections: number;
        maxSelections: number | null;
        options: { id: number; name: string; priceDeltaCents: number }[];
    }[];
}

function friendlyError(error: unknown) {
    return error instanceof ApiClientError
        ? error.message
        : "İşlem tamamlanamadı. Tekrar deneyin.";
}

function statusName(status: OrderStatus) {
    return (
        {
            pending: "Yeni",
            accepted: "Hazırlanıyor",
            ready: "Hazır",
            served: "Servis edildi",
            paid: "Ödendi",
            cancelled: "İptal",
        } as Record<OrderStatus, string>
    )[status];
}

function timeAgo(value: string) {
    const minutes = Math.max(
        0,
        Math.floor((Date.now() - Date.parse(value)) / 60_000),
    );
    return minutes < 1 ? "Şimdi" : `${minutes} dk önce`;
}

export function StaffBoardPage({
    kitchenMode = false,
}: {
    kitchenMode?: boolean;
}) {
    const { user, csrfToken, loading: authLoading, logout } = useAuth();
    const [board, setBoard] = useState<BoardData | null>(null);
    const [selectedSessionId, setSelectedSessionId] = useState<number | null>(
        null,
    );
    const [session, setSession] = useState<SessionData | null>(null);
    const [catalog, setCatalog] = useState<Product[]>([]);
    const [selectedProduct, setSelectedProduct] = useState<Product | null>(
        null,
    );
    const [selectedOptions, setSelectedOptions] = useState<number[]>([]);
    const [paymentAmount, setPaymentAmount] = useState("");
    const [paymentMethod, setPaymentMethod] = useState<"cash" | "card">("cash");
    const [notice, setNotice] = useState("");
    const [error, setError] = useState("");
    const [busy, setBusy] = useState<string | null>(null);
    const [refreshing, setRefreshing] = useState(false);

    const refresh = useCallback(async (quiet = false) => {
        if (!quiet) setRefreshing(true);
        try {
            const data = await apiRequest<BoardData>("/api/staff/board");
            setBoard(data);
            setError("");
        } catch (reason) {
            setError(friendlyError(reason));
        } finally {
            setRefreshing(false);
        }
    }, []);

    const loadSession = useCallback(
        async (sessionId: number) => {
            setSelectedSessionId(sessionId);
            setError("");
            try {
                const data = await apiRequest<SessionData>(
                    `/api/staff/sessions/${sessionId}`,
                );
                setSession(data);
                setPaymentAmount(toDecimal(data.session.dueCents));
                const menu = await apiRequest<{ products: Product[] }>(
                    `/api/staff/branches/${board?.branch?.id}/menu`,
                );
                setCatalog(menu.products);
            } catch (reason) {
                setError(friendlyError(reason));
            }
        },
        [board?.branch?.id],
    );

    useEffect(() => {
        void refresh();
    }, [refresh]);
    useEffect(() => {
        const timer = window.setInterval(() => void refresh(true), 5000);
        return () => window.clearInterval(timer);
    }, [refresh]);
    useEffect(() => {
        if (!selectedSessionId) return;
        const timer = window.setInterval(() => {
            void apiRequest<SessionData>(
                `/api/staff/sessions/${selectedSessionId}`,
            )
                .then(setSession)
                .catch(() => undefined);
        }, 5000);
        return () => window.clearInterval(timer);
    }, [selectedSessionId]);

    const pendingOrders = useMemo(
        () => board?.orders.filter((order) => order.status === "pending") ?? [],
        [board],
    );
    const activeOrders = useMemo(
        () =>
            board?.orders.filter((order) =>
                ["accepted", "ready"].includes(order.status),
            ) ?? [],
        [board],
    );

    async function mutate(
        key: string,
        path: string,
        body: unknown,
        success: string,
    ) {
        setBusy(key);
        setError("");
        setNotice("");
        try {
            await apiPost(path, body, csrfToken);
            setNotice(success);
            await refresh(true);
            if (selectedSessionId) await loadSession(selectedSessionId);
        } catch (reason) {
            setError(friendlyError(reason));
        } finally {
            setBusy(null);
        }
    }

    async function openTable(table: StaffTable) {
        if (table.sessionId) {
            await loadSession(table.sessionId);
            return;
        }
        if (!board?.branch) return;
        setBusy(`table-${table.id}`);
        setError("");
        try {
            const data = await apiPost<{ id: number }>(
                `/api/staff/branches/${board.branch.id}/tables/${table.id}/open`,
                {},
                csrfToken,
            );
            await refresh(true);
            await loadSession(data.id);
        } catch (reason) {
            setError(friendlyError(reason));
        } finally {
            setBusy(null);
        }
    }

    function toggleOption(group: Product["optionGroups"][number], id: number) {
        setSelectedOptions((current) => {
            const selected = group.options
                .filter((option) => current.includes(option.id))
                .map((option) => option.id);
            if (selected.includes(id))
                return current.filter((optionId) => optionId !== id);
            if (group.selectionMode === "single")
                return [
                    ...current.filter(
                        (optionId) => !selected.includes(optionId),
                    ),
                    id,
                ];
            if (
                group.maxSelections !== null &&
                selected.length >= group.maxSelections
            )
                return current;
            return [...current, id];
        });
    }

    async function addManualItem() {
        if (!selectedProduct || !session) return;
        for (const group of selectedProduct.optionGroups) {
            const count = group.options.filter((option) =>
                selectedOptions.includes(option.id),
            ).length;
            if (count < group.minSelections) {
                setError(
                    `${group.name} için en az ${group.minSelections} seçenek işaretleyin.`,
                );
                return;
            }
        }
        await mutate(
            "manual-order",
            `/api/staff/sessions/${session.session.id}/orders`,
            {
                lines: [
                    {
                        productId: selectedProduct.id,
                        quantity: 1,
                        optionIds: selectedOptions,
                    },
                ],
                mode: "send",
            },
            "Ürün adisyona eklendi ve siparişe gönderildi.",
        );
        setSelectedProduct(null);
        setSelectedOptions([]);
    }

    async function pay(method: "cash" | "card", fullDue = false) {
        if (!session) return;
        let amountCents: number;
        try {
            amountCents = fullDue
                ? session.session.dueCents
                : toCents(paymentAmount);
        } catch {
            setError("Geçerli bir tutar girin.");
            return;
        }
        await mutate(
            `pay-${method}`,
            `/api/staff/sessions/${session.session.id}/payments`,
            { amountCents, method },
            `${formatCurrency(amountCents)} ${method === "cash" ? "nakit" : "kart"} ödemesi kaydedildi.`,
        );
    }

    if (authLoading)
        return (
            <main className="grid min-h-dvh place-items-center">
                <LoaderCircle className="size-7 animate-spin text-forest" />
            </main>
        );
    if (!user) return <Navigate replace to="/giris" />;

    return (
        <main className="min-h-dvh pb-8">
            <header className="sticky top-0 z-20 border-b border-line bg-cream/95 backdrop-blur-xl">
                <div className="mx-auto flex max-w-[1500px] items-center justify-between gap-3 px-4 py-3 sm:px-7">
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-forest text-amber">
                            {kitchenMode ? (
                                <ChefHat className="size-5" />
                            ) : (
                                <Coffee className="size-5" />
                            )}
                        </span>
                        <div className="min-w-0">
                            <p className="truncate font-display text-lg font-semibold">
                                {kitchenMode
                                    ? "Hazırlık ekranı"
                                    : "Servis paneli"}
                            </p>
                            <p className="truncate text-xs text-muted">
                                {board?.branch?.name ?? "Şube yükleniyor"} ·{" "}
                                {user.name}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <span className="hidden text-xs text-muted sm:inline">
                            5 saniyede bir güncellenir
                        </span>
                        <button
                            aria-label="Panoyu yenile"
                            className="btn-icon size-10"
                            disabled={refreshing}
                            onClick={() => void refresh()}
                            type="button"
                        >
                            <RefreshCw
                                className={`size-4 ${refreshing ? "animate-spin" : ""}`}
                            />
                        </button>
                        <button
                            aria-label="Çıkış yap"
                            className="btn-icon size-10"
                            onClick={() => void logout()}
                            type="button"
                        >
                            <LogOut className="size-4" />
                        </button>
                    </div>
                </div>
            </header>

            <div className="mx-auto max-w-[1500px] px-4 pt-5 sm:px-7">
                {(error || notice) && (
                    <div
                        aria-live="polite"
                        className={`mb-4 flex items-start justify-between gap-3 rounded-2xl px-4 py-3 text-sm font-semibold ${error ? "bg-danger-soft text-danger" : "bg-success-soft text-success"}`}
                    >
                        <span>{error || notice}</span>
                        <button
                            aria-label="Kapat"
                            onClick={() => {
                                setError("");
                                setNotice("");
                            }}
                            type="button"
                        >
                            <X className="size-4" />
                        </button>
                    </div>
                )}
                <section className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Metric
                        icon={<Bell className="size-4" />}
                        label="Yeni sipariş"
                        value={board?.summary.pending ?? "—"}
                        tone="amber"
                    />
                    <Metric
                        icon={<ChefHat className="size-4" />}
                        label="Hazırlanıyor"
                        value={board?.summary.preparing ?? "—"}
                        tone="forest"
                    />
                    <Metric
                        icon={<CheckCheck className="size-4" />}
                        label="Hazır"
                        value={board?.summary.ready ?? "—"}
                        tone="sage"
                    />
                    <Metric
                        icon={<Utensils className="size-4" />}
                        label="Açık masa"
                        value={board?.summary.openTables ?? "—"}
                        tone="cream"
                    />
                </section>

                {!board?.branch && (
                    <section className="card mt-5 p-6 text-center">
                        <p className="font-semibold">
                            {board?.message ?? "Şube bilgisi yükleniyor."}
                        </p>
                    </section>
                )}
                {board?.branch && (
                    <>
                        <section className="mt-7">
                            <div className="mb-3 flex items-end justify-between">
                                <div>
                                    <p className="eyebrow">Servis akışı</p>
                                    <h1 className="mt-1 font-display text-2xl font-semibold">
                                        Siparişler
                                    </h1>
                                </div>
                                <span className="text-xs text-muted">
                                    {pendingOrders.length + activeOrders.length}{" "}
                                    aktif
                                </span>
                            </div>
                            <div className="grid gap-4 lg:grid-cols-3">
                                <OrderColumn
                                    title="Yeni siparişler"
                                    count={pendingOrders.length}
                                    orders={pendingOrders}
                                    tone="amber"
                                    onAction={(order) =>
                                        void mutate(
                                            `order-${order.id}`,
                                            `/api/staff/orders/${order.id}/status`,
                                            { status: "accepted" },
                                            `#${order.number} siparişi alındı.`,
                                        )
                                    }
                                    actionLabel="Siparişi al"
                                    busy={busy}
                                />
                                <OrderColumn
                                    title="Hazırlanıyor"
                                    count={
                                        activeOrders.filter(
                                            (order) =>
                                                order.status === "accepted",
                                        ).length
                                    }
                                    orders={activeOrders.filter(
                                        (order) => order.status === "accepted",
                                    )}
                                    tone="forest"
                                    onAction={(order) =>
                                        void mutate(
                                            `order-${order.id}`,
                                            `/api/staff/orders/${order.id}/status`,
                                            { status: "ready" },
                                            `#${order.number} hazır olarak işaretlendi.`,
                                        )
                                    }
                                    actionLabel="Hazır"
                                    busy={busy}
                                />
                                <OrderColumn
                                    title="Hazır · Teslim et"
                                    count={
                                        activeOrders.filter(
                                            (order) => order.status === "ready",
                                        ).length
                                    }
                                    orders={activeOrders.filter(
                                        (order) => order.status === "ready",
                                    )}
                                    tone="sage"
                                    onAction={(order) =>
                                        void mutate(
                                            `order-${order.id}`,
                                            `/api/staff/orders/${order.id}/status`,
                                            { status: "served" },
                                            `#${order.number} servis edildi.`,
                                        )
                                    }
                                    actionLabel="Teslim edildi"
                                    busy={busy}
                                />
                            </div>
                        </section>

                        {!kitchenMode && (
                            <section className="mt-8">
                                <div className="mb-3 flex items-end justify-between">
                                    <div>
                                        <p className="eyebrow">
                                            Salon görünümü
                                        </p>
                                        <h2 className="mt-1 font-display text-2xl font-semibold">
                                            Masalar
                                        </h2>
                                    </div>
                                    <span className="text-xs text-muted">
                                        Masaya dokun, adisyonu aç
                                    </span>
                                </div>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5 xl:grid-cols-6">
                                    {board.tables.map((table) => (
                                        <button
                                            className={`card-flat relative min-h-32 p-4 text-left transition hover:-translate-y-0.5 hover:shadow-soft ${table.isOpen ? "border-forest/25" : "border-dashed"}`}
                                            key={table.id}
                                            onClick={() =>
                                                void openTable(table)
                                            }
                                            type="button"
                                        >
                                            <div className="flex items-start justify-between gap-2">
                                                <span className="font-display text-lg font-semibold">
                                                    {table.name}
                                                </span>
                                                <span
                                                    className={`size-2.5 rounded-full ${table.isOpen ? "bg-success" : "bg-line"}`}
                                                />
                                            </div>
                                            <p className="mt-1 text-xs text-muted">
                                                {table.isOpen
                                                    ? `${table.activeOrders} sipariş · ${table.dueLabel} kalan`
                                                    : "Boş masa"}
                                            </p>
                                            <span
                                                className={`mt-4 inline-flex items-center gap-1 text-xs font-bold ${table.isOpen ? "text-forest" : "text-muted"}`}
                                            >
                                                {busy ===
                                                `table-${table.id}` ? (
                                                    <LoaderCircle className="size-3 animate-spin" />
                                                ) : table.isOpen ? (
                                                    <ReceiptText className="size-3" />
                                                ) : (
                                                    <DoorOpen className="size-3" />
                                                )}
                                                {table.isOpen
                                                    ? "Adisyonu aç"
                                                    : "Masayı aç"}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </section>
                        )}

                        {!kitchenMode && (
                            <section className="mt-8">
                                <div className="mb-3 flex items-end justify-between">
                                    <div>
                                        <p className="eyebrow">
                                            Ekip koordinasyonu
                                        </p>
                                        <h2 className="mt-1 font-display text-2xl font-semibold">
                                            Garson çağrıları
                                        </h2>
                                    </div>
                                    <span className="badge-neutral">
                                        {board.requests.length} açık
                                    </span>
                                </div>
                                {board.requests.length === 0 ? (
                                    <div className="card-flat px-5 py-7 text-center text-sm text-muted">
                                        Bekleyen çağrı yok.
                                    </div>
                                ) : (
                                    <div className="grid gap-3 md:grid-cols-2">
                                        {board.requests.map((request) => (
                                            <article
                                                className="card-flat flex flex-col justify-between gap-4 p-4 sm:flex-row sm:items-center"
                                                key={request.id}
                                            >
                                                <div className="flex items-start gap-3">
                                                    <span
                                                        className={`grid size-10 shrink-0 place-items-center rounded-2xl ${request.type === "bill" ? "bg-amber-soft text-ink" : "bg-sage text-forest"}`}
                                                    >
                                                        {request.type ===
                                                        "bill" ? (
                                                            <Wallet className="size-4" />
                                                        ) : (
                                                            <Bell className="size-4" />
                                                        )}
                                                    </span>
                                                    <div>
                                                        <p className="font-bold">
                                                            {request.table} ·{" "}
                                                            {request.typeLabel}
                                                        </p>
                                                        <p className="mt-0.5 text-xs text-muted">
                                                            {request.claimedByName
                                                                ? `${request.claimedByName} ilgileniyor`
                                                                : "Henüz kimse üstlenmedi"}
                                                        </p>
                                                        {request.note && (
                                                            <p className="mt-1 text-xs">
                                                                {request.note}
                                                            </p>
                                                        )}
                                                    </div>
                                                </div>
                                                <div className="flex gap-2">
                                                    {!request.isClaimedByMe &&
                                                        !request.claimedBy && (
                                                            <button
                                                                className="btn-secondary btn-sm"
                                                                disabled={
                                                                    busy ===
                                                                    `claim-${request.id}`
                                                                }
                                                                onClick={() =>
                                                                    void mutate(
                                                                        `claim-${request.id}`,
                                                                        `/api/staff/service-requests/${request.id}/claim`,
                                                                        {},
                                                                        "Çağrı sana atandı.",
                                                                    )
                                                                }
                                                                type="button"
                                                            >
                                                                Üstlen
                                                            </button>
                                                        )}
                                                    {request.isClaimedByMe && (
                                                        <button
                                                            className="btn-primary btn-sm"
                                                            disabled={
                                                                busy ===
                                                                `resolve-${request.id}`
                                                            }
                                                            onClick={() =>
                                                                void mutate(
                                                                    `resolve-${request.id}`,
                                                                    `/api/staff/service-requests/${request.id}/resolve`,
                                                                    {},
                                                                    "Çağrı tamamlandı.",
                                                                )
                                                            }
                                                            type="button"
                                                        >
                                                            <Check className="size-3.5" />{" "}
                                                            Tamamla
                                                        </button>
                                                    )}
                                                </div>
                                            </article>
                                        ))}
                                    </div>
                                )}
                            </section>
                        )}
                    </>
                )}
            </div>

            {session && selectedSessionId && (
                <div
                    className="fixed inset-0 z-40 flex items-end justify-center bg-ink/40 p-0 backdrop-blur-sm sm:items-center sm:p-5"
                    onMouseDown={(event) => {
                        if (event.target === event.currentTarget) {
                            setSession(null);
                            setSelectedSessionId(null);
                        }
                    }}
                >
                    <section
                        aria-label={`${session.tableName} adisyonu`}
                        aria-modal="true"
                        className="max-h-[94dvh] w-full max-w-3xl overflow-y-auto rounded-t-[2rem] bg-cream p-4 shadow-lift sm:rounded-[2rem] sm:p-6"
                        role="dialog"
                    >
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <p className="eyebrow">{session.branchName}</p>
                                <h2 className="mt-1 font-display text-2xl font-semibold">
                                    {session.tableName} · Adisyon
                                </h2>
                                <p className="mt-1 text-xs text-muted">
                                    Bu masa için son siparişler ve tahsilat
                                </p>
                            </div>
                            <button
                                aria-label="Adisyonu kapat"
                                className="btn-icon size-10"
                                onClick={() => {
                                    setSession(null);
                                    setSelectedSessionId(null);
                                }}
                                type="button"
                            >
                                <X className="size-4" />
                            </button>
                        </div>
                        <div className="mt-4 grid grid-cols-3 gap-2">
                            <Metric
                                icon={<ReceiptText className="size-4" />}
                                label="Toplam"
                                value={formatCurrency(
                                    session.session.totalCents,
                                )}
                                tone="cream"
                            />
                            <Metric
                                icon={<CircleDollarSign className="size-4" />}
                                label="Ödenen"
                                value={formatCurrency(
                                    session.session.paidCents,
                                )}
                                tone="sage"
                            />
                            <Metric
                                icon={<Wallet className="size-4" />}
                                label="Kalan"
                                value={formatCurrency(session.session.dueCents)}
                                tone="amber"
                            />
                        </div>
                        <div className="mt-5">
                            <div className="flex items-center justify-between">
                                <h3 className="font-display text-lg font-semibold">
                                    Sipariş geçmişi
                                </h3>
                                <button
                                    className="btn-ghost btn-sm"
                                    onClick={() =>
                                        void loadSession(selectedSessionId)
                                    }
                                    type="button"
                                >
                                    <RefreshCw className="size-3.5" /> Yenile
                                </button>
                            </div>
                            {session.session.orders.length === 0 ? (
                                <p className="py-5 text-center text-sm text-muted">
                                    Bu adisyonda henüz ürün yok.
                                </p>
                            ) : (
                                <div className="mt-2 divide-y divide-line">
                                    {[...session.session.orders]
                                        .reverse()
                                        .map((order) => (
                                            <article
                                                className="py-3"
                                                key={order.id}
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div>
                                                        <p className="text-sm font-bold">
                                                            #{order.number}{" "}
                                                            <span className="font-normal text-muted">
                                                                ·{" "}
                                                                {order.source ===
                                                                "qr"
                                                                    ? "QR sipariş"
                                                                    : "Garson siparişi"}
                                                            </span>
                                                        </p>
                                                        <p className="mt-0.5 text-xs text-muted">
                                                            {timeAgo(
                                                                order.createdAt,
                                                            )}{" "}
                                                            ·{" "}
                                                            {statusName(
                                                                order.status,
                                                            )}
                                                        </p>
                                                    </div>
                                                    <strong className="shrink-0 text-sm">
                                                        {formatCurrency(
                                                            order.totalCents,
                                                        )}
                                                    </strong>
                                                </div>
                                                <p className="mt-2 text-xs leading-5 text-muted">
                                                    {order.items
                                                        .map(
                                                            (item) =>
                                                                `${item.quantity} × ${item.name}${item.options.length ? ` (${item.options.map((option) => option.name).join(", ")})` : ""}`,
                                                        )
                                                        .join(" · ")}
                                                </p>
                                                {order.note && (
                                                    <p className="mt-1 text-xs text-muted">
                                                        Not: {order.note}
                                                    </p>
                                                )}
                                            </article>
                                        ))}
                                </div>
                            )}
                        </div>
                        {!kitchenMode && session.session.status === "open" && (
                            <div className="mt-4 grid gap-4 border-t border-line pt-4 md:grid-cols-2">
                                <div>
                                    <h3 className="font-display text-lg font-semibold">
                                        Garson siparişi ekle
                                    </h3>
                                    <p className="mt-1 text-xs text-muted">
                                        Ürün ve tutarı sunucu menü fiyatından
                                        alır.
                                    </p>
                                    {selectedProduct ? (
                                        <div className="mt-3 rounded-2xl border border-line bg-paper p-3">
                                            <div className="flex items-start justify-between gap-2">
                                                <div>
                                                    <p className="text-sm font-bold">
                                                        {selectedProduct.name}
                                                    </p>
                                                    <p className="text-xs text-muted">
                                                        {formatCurrency(
                                                            selectedProduct.priceCents,
                                                        )}
                                                    </p>
                                                </div>
                                                <button
                                                    aria-label="Ürün seçimini kaldır"
                                                    className="btn-icon size-8"
                                                    onClick={() => {
                                                        setSelectedProduct(
                                                            null,
                                                        );
                                                        setSelectedOptions([]);
                                                    }}
                                                    type="button"
                                                >
                                                    <X className="size-3.5" />
                                                </button>
                                            </div>
                                            {selectedProduct.optionGroups.map(
                                                (group) => (
                                                    <fieldset
                                                        className="mt-3"
                                                        key={group.id}
                                                    >
                                                        <legend className="text-xs font-bold">
                                                            {group.name}
                                                            {group.minSelections
                                                                ? ` · en az ${group.minSelections}`
                                                                : ""}
                                                        </legend>
                                                        <div className="mt-1 flex flex-wrap gap-2">
                                                            {group.options.map(
                                                                (option) => {
                                                                    const active =
                                                                        selectedOptions.includes(
                                                                            option.id,
                                                                        );
                                                                    return (
                                                                        <button
                                                                            className={`chip ${active ? "chip-active" : ""}`}
                                                                            key={
                                                                                option.id
                                                                            }
                                                                            onClick={() =>
                                                                                toggleOption(
                                                                                    group,
                                                                                    option.id,
                                                                                )
                                                                            }
                                                                            type="button"
                                                                        >
                                                                            {
                                                                                option.name
                                                                            }
                                                                            {option.priceDeltaCents >
                                                                            0
                                                                                ? ` +${formatCurrency(option.priceDeltaCents)}`
                                                                                : ""}
                                                                        </button>
                                                                    );
                                                                },
                                                            )}
                                                        </div>
                                                    </fieldset>
                                                ),
                                            )}
                                            <button
                                                className="btn-primary mt-3 w-full rounded-xl"
                                                disabled={
                                                    busy === "manual-order"
                                                }
                                                onClick={() =>
                                                    void addManualItem()
                                                }
                                                type="button"
                                            >
                                                {busy === "manual-order" ? (
                                                    <LoaderCircle className="size-4 animate-spin" />
                                                ) : (
                                                    <Plus className="size-4" />
                                                )}{" "}
                                                Siparişe ekle
                                            </button>
                                        </div>
                                    ) : (
                                        <div className="mt-3 grid max-h-56 gap-2 overflow-y-auto">
                                            {catalog.map((product) => (
                                                <button
                                                    className="flex items-center justify-between gap-3 rounded-2xl border border-line bg-paper px-3 py-2.5 text-left hover:border-forest/30"
                                                    key={product.id}
                                                    onClick={() => {
                                                        setSelectedProduct(
                                                            product,
                                                        );
                                                        setSelectedOptions([]);
                                                    }}
                                                    type="button"
                                                >
                                                    <span className="text-sm font-semibold">
                                                        {product.name}
                                                    </span>
                                                    <span className="shrink-0 text-sm font-bold">
                                                        {formatCurrency(
                                                            product.priceCents,
                                                        )}
                                                    </span>
                                                </button>
                                            ))}
                                            {catalog.length === 0 && (
                                                <p className="text-sm text-muted">
                                                    Bu şubenin menüsünde ürün
                                                    bulunmuyor.
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </div>
                                <div>
                                    <h3 className="font-display text-lg font-semibold">
                                        Ödeme kaydet
                                    </h3>
                                    <p className="mt-1 text-xs text-muted">
                                        Kartı fiziksel POS cihazında alın.
                                    </p>
                                    {session.session.dueCents > 0 ? (
                                        <>
                                            <label className="mt-3 block">
                                                <span className="label">
                                                    Tutar (₺)
                                                </span>
                                                <input
                                                    className="input"
                                                    inputMode="decimal"
                                                    onChange={(event) =>
                                                        setPaymentAmount(
                                                            event.target.value,
                                                        )
                                                    }
                                                    value={paymentAmount}
                                                />
                                            </label>
                                            <div className="mt-3 grid grid-cols-2 gap-2">
                                                <button
                                                    className={`btn-secondary rounded-xl ${paymentMethod === "cash" ? "border-forest text-forest" : ""}`}
                                                    onClick={() =>
                                                        setPaymentMethod("cash")
                                                    }
                                                    type="button"
                                                >
                                                    <Wallet className="size-4" />{" "}
                                                    Nakit
                                                </button>
                                                <button
                                                    className={`btn-secondary rounded-xl ${paymentMethod === "card" ? "border-forest text-forest" : ""}`}
                                                    onClick={() =>
                                                        setPaymentMethod("card")
                                                    }
                                                    type="button"
                                                >
                                                    <CreditCard className="size-4" />{" "}
                                                    Kart
                                                </button>
                                            </div>
                                            <button
                                                className="btn-primary mt-3 w-full rounded-xl"
                                                disabled={Boolean(
                                                    busy?.startsWith("pay-"),
                                                )}
                                                onClick={() =>
                                                    void pay(paymentMethod)
                                                }
                                                type="button"
                                            >
                                                {busy?.startsWith("pay-") ? (
                                                    <LoaderCircle className="size-4 animate-spin" />
                                                ) : (
                                                    <Check className="size-4" />
                                                )}{" "}
                                                Ödemeyi kaydet
                                            </button>
                                            <div className="mt-2 grid grid-cols-2 gap-2">
                                                <button
                                                    className="btn-ghost btn-sm"
                                                    onClick={() =>
                                                        void pay("cash", true)
                                                    }
                                                    type="button"
                                                >
                                                    Kalanı nakit al
                                                </button>
                                                <button
                                                    className="btn-ghost btn-sm"
                                                    onClick={() =>
                                                        void pay("card", true)
                                                    }
                                                    type="button"
                                                >
                                                    Kalanı kart al
                                                </button>
                                            </div>
                                        </>
                                    ) : (
                                        <div className="mt-4 rounded-2xl bg-success-soft p-4 text-sm font-semibold text-success">
                                            Adisyon tamamen ödendi. Masa
                                            kapatıldı.
                                        </div>
                                    )}
                                    <div className="mt-4 border-t border-line pt-3">
                                        <p className="text-xs font-bold text-muted">
                                            TAHSİLAT GEÇMİŞİ
                                        </p>
                                        {session.session.payments.length ===
                                        0 ? (
                                            <p className="mt-2 text-xs text-muted">
                                                Henüz ödeme kaydı yok.
                                            </p>
                                        ) : (
                                            session.session.payments.map(
                                                (payment) => (
                                                    <div
                                                        className={`flex justify-between gap-2 py-1.5 text-xs ${payment.voided ? "text-muted line-through" : ""}`}
                                                        key={payment.id}
                                                    >
                                                        <span>
                                                            {payment.method ===
                                                            "cash"
                                                                ? "Nakit"
                                                                : "Kart"}
                                                            {payment.personLabel
                                                                ? ` · ${payment.personLabel}`
                                                                : ""}
                                                            {payment.voided
                                                                ? " · İptal"
                                                                : ""}
                                                        </span>
                                                        <span className="font-semibold">
                                                            {formatCurrency(
                                                                payment.amountCents,
                                                            )}
                                                        </span>
                                                    </div>
                                                ),
                                            )
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}
                        {session.session.status === "closed" && (
                            <div className="mt-4 rounded-2xl bg-sage p-4 text-sm font-semibold text-forest">
                                Bu adisyon kapalı. Düzenleme için kafe
                                yöneticisine başvurun.
                            </div>
                        )}
                    </section>
                </div>
            )}
            <Link aria-label="Ana sayfaya dön" className="sr-only" to="/">
                <ArrowLeft className="size-4" />
            </Link>
        </main>
    );
}

function Metric({
    icon,
    label,
    value,
    tone,
}: {
    icon: React.ReactNode;
    label: string;
    value: string | number;
    tone: "amber" | "forest" | "sage" | "cream";
}) {
    const background = {
        amber: "bg-amber-soft",
        forest: "bg-forest text-white",
        sage: "bg-sage",
        cream: "bg-paper",
    }[tone];
    const iconTone = tone === "forest" ? "text-amber" : "text-forest";
    return (
        <div
            className={`rounded-2xl border border-line/70 p-3 sm:p-4 ${background}`}
        >
            <span
                className={`inline-flex items-center gap-1.5 text-[10px] font-semibold sm:text-xs ${tone === "forest" ? "text-white/70" : "text-muted"}`}
            >
                <span className={iconTone}>{icon}</span>
                {label}
            </span>
            <p className="mt-1 font-display text-lg font-semibold sm:text-2xl">
                {value}
            </p>
        </div>
    );
}

function OrderColumn({
    title,
    count,
    orders,
    tone,
    onAction,
    actionLabel,
    busy,
}: {
    title: string;
    count: number;
    orders: StaffOrder[];
    tone: "amber" | "forest" | "sage";
    onAction: (order: StaffOrder) => void;
    actionLabel: string;
    busy: string | null;
}) {
    return (
        <section className="min-w-0 rounded-3xl border border-line bg-paper p-3 sm:p-4">
            <div className="mb-3 flex items-center justify-between gap-2">
                <h2 className="font-display text-base font-semibold sm:text-lg">
                    {title}
                </h2>
                <span
                    className={`badge ${tone === "amber" ? "badge-pending" : tone === "forest" ? "badge-preparing" : "badge-ready"}`}
                >
                    {count}
                </span>
            </div>
            {orders.length === 0 ? (
                <div className="grid min-h-28 place-items-center rounded-2xl border border-dashed border-line px-4 text-center text-xs text-muted">
                    Burada bekleyen sipariş yok.
                </div>
            ) : (
                <div className="space-y-2">
                    {orders.map((order) => (
                        <article
                            className="rounded-2xl border border-line/80 bg-cream/60 p-3"
                            key={order.id}
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div>
                                    <p className="text-sm font-bold">
                                        {order.tableName}{" "}
                                        <span className="font-normal text-muted">
                                            · #{order.number}
                                        </span>
                                    </p>
                                    <p className="mt-0.5 inline-flex items-center gap-1 text-[11px] text-muted">
                                        <Clock3 className="size-3" />
                                        {timeAgo(order.createdAt)} ·{" "}
                                        {order.source === "qr"
                                            ? "QR"
                                            : "Garson"}
                                    </p>
                                </div>
                                <strong className="shrink-0 text-xs">
                                    {order.totalLabel}
                                </strong>
                            </div>
                            <ul className="mt-2 space-y-1 text-xs leading-5">
                                {order.items.map((item) => (
                                    <li key={item.id}>
                                        <span className="font-bold">
                                            {item.quantity}×
                                        </span>{" "}
                                        {item.name}
                                        {item.options.length > 0 && (
                                            <span className="block pl-5 text-[11px] text-muted">
                                                {item.options
                                                    .map(
                                                        (option) =>
                                                            `${option.group}: ${option.name}`,
                                                    )
                                                    .join(" · ")}
                                            </span>
                                        )}
                                        {item.note && (
                                            <span className="block pl-5 text-[11px] italic text-muted">
                                                Not: {item.note}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                            {order.note && (
                                <p className="mt-2 rounded-xl bg-paper px-2.5 py-2 text-xs text-muted">
                                    Sipariş notu: {order.note}
                                </p>
                            )}
                            <button
                                className="btn-primary mt-3 w-full rounded-xl"
                                disabled={busy === `order-${order.id}`}
                                onClick={() => onAction(order)}
                                type="button"
                            >
                                {busy === `order-${order.id}` ? (
                                    <LoaderCircle className="size-4 animate-spin" />
                                ) : (
                                    <Check className="size-4" />
                                )}
                                {actionLabel}
                            </button>
                        </article>
                    ))}
                </div>
            )}
        </section>
    );
}
