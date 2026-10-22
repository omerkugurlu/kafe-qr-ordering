import {
    ArrowRight,
    BadgeCheck,
    Bell,
    ChevronLeft,
    ChevronRight,
    CircleUserRound,
    Coffee,
    Minus,
    Plus,
    Search,
    ShoppingBag,
    X,
} from "lucide-react";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { useAuth } from "../auth/AuthProvider.js";
import { apiPost, apiRequest, ApiClientError } from "../lib/api.js";
import { formatCurrency } from "../domain/money.js";

interface MenuOption {
    id: number;
    name: string;
    priceDeltaCents: number;
}
interface OptionGroup {
    id: number;
    name: string;
    selectionMode: "single" | "multiple";
    minSelections: number;
    maxSelections: number | null;
    options: MenuOption[];
}
interface MenuProduct {
    id: number;
    name: string;
    description: string | null;
    imageUrl: string | null;
    priceCents: number;
    priceLabel: string;
    prepMinutes: number;
    isFeatured: boolean;
    categoryId: number;
    categoryName: string;
    optionGroups: OptionGroup[];
    suggestions: number[];
}
interface MenuData {
    table: { id: number; name: string; token: string };
    cafe: {
        id: number;
        name: string;
        description: string | null;
        logoUrl: string | null;
        coverUrl: string | null;
        registrationPolicy: string;
        loyaltyEnabled: boolean;
    };
    branch: { id: number; name: string; address: string | null };
    customer: { id: number; name: string } | null;
    categories: {
        id: number;
        name: string;
        description: string | null;
        productIds: number[];
    }[];
    products: MenuProduct[];
    featuredIds: number[];
    session: {
        isOpen: boolean;
        qrOrderingEnabled: boolean;
        qrOrderPending: boolean;
        dueCents: number;
        orders: {
            id: number;
            number: string;
            status: string;
            totalCents: number;
        }[];
    } | null;
    orderingAvailable: boolean;
    loyalty: {
        points: number;
        completedOrders: number;
        campaigns: {
            id: number;
            name: string;
            type: string;
            required: number;
            rewardType: string;
            rewardCents: number;
            rewardBasisPoints: number;
        }[];
    } | null;
    announcements: {
        id: number;
        title: string;
        body: string | null;
        type: string;
    }[];
    skyKitePromotion: { headline: string; description: string; url: string };
}

interface CartLine {
    key: string;
    product: MenuProduct;
    quantity: number;
    optionIds: number[];
    optionLabels: string[];
    note: string;
    unitPriceCents: number;
}

function initials(value: string) {
    return value
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((word) => word[0])
        .join("")
        .toLocaleUpperCase("tr-TR");
}

export function CustomerMenuPage() {
    const { token = "" } = useParams();
    const navigate = useNavigate();
    const { user, csrfToken } = useAuth();
    const [menu, setMenu] = useState<MenuData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [categoryId, setCategoryId] = useState<number | "all">("all");
    const [search, setSearch] = useState("");
    const [cart, setCart] = useState<CartLine[]>([]);
    const [selectedProduct, setSelectedProduct] = useState<MenuProduct | null>(
        null,
    );
    const [selectedOptions, setSelectedOptions] = useState<number[]>([]);
    const [productNote, setProductNote] = useState("");
    const [cartOpen, setCartOpen] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [suggestedIds, setSuggestedIds] = useState<number[]>([]);
    const [submitting, setSubmitting] = useState(false);
    const [toast, setToast] = useState("");
    const popularRef = useRef<HTMLDivElement>(null);

    const loadMenu = useCallback(
        async (quiet = false) => {
            try {
                const data = await apiRequest<MenuData>(
                    `/api/tables/${encodeURIComponent(token)}/menu`,
                );
                setMenu(data);
                setError("");
            } catch (reason) {
                if (
                    reason instanceof ApiClientError &&
                    reason.code === "customer_login_required"
                ) {
                    navigate(`/masa/${encodeURIComponent(token)}/giris`, {
                        replace: true,
                    });
                    return;
                }
                setError(
                    reason instanceof ApiClientError
                        ? reason.message
                        : "Menü yüklenemedi. Bağlantını kontrol et.",
                );
            } finally {
                if (!quiet) setLoading(false);
            }
        },
        [navigate, token],
    );

    useEffect(() => {
        void loadMenu();
    }, [loadMenu]);
    useEffect(() => {
        const timer = window.setInterval(() => void loadMenu(true), 5000);
        return () => window.clearInterval(timer);
    }, [loadMenu]);
    useEffect(() => {
        if (!toast) return;
        const timer = window.setTimeout(() => setToast(""), 2800);
        return () => window.clearTimeout(timer);
    }, [toast]);

    const productsById = useMemo(
        () =>
            new Map(
                (menu?.products ?? []).map((product) => [product.id, product]),
            ),
        [menu],
    );
    const featured = useMemo(
        () =>
            (menu?.featuredIds ?? [])
                .map((id) => productsById.get(id))
                .filter((product): product is MenuProduct => Boolean(product)),
        [menu, productsById],
    );
    const visibleProducts = useMemo(
        () =>
            (menu?.products ?? []).filter(
                (product) =>
                    (categoryId === "all" ||
                        product.categoryId === categoryId) &&
                    (product.name
                        .toLocaleLowerCase("tr-TR")
                        .includes(search.toLocaleLowerCase("tr-TR")) ||
                        product.description
                            ?.toLocaleLowerCase("tr-TR")
                            .includes(search.toLocaleLowerCase("tr-TR"))),
            ),
        [categoryId, menu, search],
    );
    const totalCents = cart.reduce(
        (sum, line) => sum + line.unitPriceCents * line.quantity,
        0,
    );
    const itemCount = cart.reduce((sum, line) => sum + line.quantity, 0);

    function openProduct(product: MenuProduct) {
        if (!menu?.orderingAvailable) {
            setToast(
                menu?.session?.qrOrderPending
                    ? "Önceki siparişin garson tarafından alınmasını bekleyin."
                    : "Sipariş için garsonun masayı açması gerekiyor.",
            );
            return;
        }
        setSelectedProduct(product);
        setSelectedOptions([]);
        setProductNote("");
    }

    function toggleOption(group: OptionGroup, optionId: number) {
        setSelectedOptions((current) => {
            const selectedInGroup = group.options
                .filter((option) => current.includes(option.id))
                .map((option) => option.id);
            if (selectedInGroup.includes(optionId))
                return current.filter((id) => id !== optionId);
            if (group.selectionMode === "single")
                return [
                    ...current.filter((id) => !selectedInGroup.includes(id)),
                    optionId,
                ];
            if (
                group.maxSelections !== null &&
                selectedInGroup.length >= group.maxSelections
            )
                return current;
            return [...current, optionId];
        });
    }

    function addProduct(product: MenuProduct, optionIds: number[], note = "") {
        const optionLabels = product.optionGroups.flatMap((group) =>
            group.options
                .filter((option) => optionIds.includes(option.id))
                .map((option) => `${group.name}: ${option.name}`),
        );
        const unitPriceCents =
            product.priceCents +
            product.optionGroups
                .flatMap((group) => group.options)
                .filter((option) => optionIds.includes(option.id))
                .reduce((sum, option) => sum + option.priceDeltaCents, 0);
        setCart((current) => [
            ...current,
            {
                key: crypto.randomUUID(),
                product,
                quantity: 1,
                optionIds,
                optionLabels,
                note: note.trim(),
                unitPriceCents,
            },
        ]);
        setSelectedProduct(null);
        setToast(`${product.name} sepete eklendi.`);
    }

    function addSuggested(product: MenuProduct) {
        const requiredGroups = product.optionGroups.some(
            (group) => group.minSelections > 0,
        );
        if (requiredGroups) {
            setConfirmOpen(false);
            openProduct(product);
            return;
        }
        addProduct(product, []);
        setSuggestedIds((current) => [...current, product.id]);
    }

    function updateQuantity(key: string, step: number) {
        setCart((current) =>
            current.flatMap((line) =>
                line.key !== key
                    ? [line]
                    : line.quantity + step <= 0
                      ? []
                      : [{ ...line, quantity: line.quantity + step }],
            ),
        );
    }

    function beginCheckout() {
        if (!menu?.orderingAvailable || cart.length === 0) return;
        const recommendations = [
            ...new Set(cart.flatMap((line) => line.product.suggestions)),
        ]
            .filter(
                (id) =>
                    !cart.some((line) => line.product.id === id) &&
                    !suggestedIds.includes(id),
            )
            .slice(0, 3);
        setSuggestedIds(recommendations);
        setCartOpen(false);
        setConfirmOpen(true);
    }

    async function sendOrder() {
        if (!menu) return;
        setSubmitting(true);
        setError("");
        try {
            const data = await apiPost<{ order: { number: string } }>(
                `/api/tables/${encodeURIComponent(token)}/orders`,
                {
                    lines: cart.map((line) => ({
                        productId: line.product.id,
                        quantity: line.quantity,
                        optionIds: line.optionIds,
                        note: line.note,
                    })),
                },
                csrfToken,
            );
            setCart([]);
            setConfirmOpen(false);
            navigate(
                `/masa/${encodeURIComponent(token)}/siparis/${data.order.number}`,
            );
        } catch (reason) {
            if (
                reason instanceof ApiClientError &&
                reason.code === "customer_login_required"
            ) {
                navigate(`/masa/${encodeURIComponent(token)}/giris`);
                return;
            }
            setError(
                reason instanceof ApiClientError
                    ? reason.message
                    : "Sipariş gönderilemedi. Lütfen tekrar deneyin.",
            );
        } finally {
            setSubmitting(false);
        }
    }

    async function callWaiter(type: "waiter" | "bill") {
        try {
            const result = await apiPost<{ message: string }>(
                `/api/tables/${encodeURIComponent(token)}/service-requests`,
                { type },
                csrfToken,
            );
            setToast(result.message);
        } catch (reason) {
            setToast(
                reason instanceof ApiClientError
                    ? reason.message
                    : "İstek gönderilemedi.",
            );
        }
    }

    function scrollPopular(direction: -1 | 1) {
        popularRef.current?.scrollBy({
            left:
                direction * Math.max(260, popularRef.current.clientWidth * 0.8),
            behavior: "smooth",
        });
    }

    if (loading) return <LoadingScreen />;
    if (error && !menu)
        return <ErrorScreen message={error} retry={() => void loadMenu()} />;
    if (!menu)
        return (
            <ErrorScreen
                message="Menü bulunamadı."
                retry={() => void loadMenu()}
            />
        );

    const customerLinks =
        user?.role === "customer" ? (
            <Link
                aria-label="Hesabım"
                className="btn-icon size-10"
                to={`/masa/${encodeURIComponent(token)}/hesabim`}
            >
                <CircleUserRound aria-hidden="true" className="size-5" />
            </Link>
        ) : (
            menu.cafe.registrationPolicy !== "guest" && (
                <Link
                    className="btn-secondary btn-sm hidden sm:inline-flex"
                    to={`/masa/${encodeURIComponent(token)}/giris`}
                >
                    Hesabım
                </Link>
            )
        );

    return (
        <main className="min-h-dvh pb-28 sm:pb-10">
            <header className="sticky top-0 z-30 border-b border-line/80 bg-cream/95 backdrop-blur-xl">
                <div className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-7 lg:px-10">
                    <div className="flex min-w-0 items-center gap-3">
                        <div className="grid size-11 shrink-0 place-items-center overflow-hidden rounded-2xl bg-forest text-amber">
                            {menu.cafe.logoUrl ? (
                                <img
                                    alt=""
                                    className="size-full object-cover"
                                    src={menu.cafe.logoUrl}
                                />
                            ) : (
                                <span className="font-display text-sm font-bold">
                                    {initials(menu.cafe.name)}
                                </span>
                            )}
                        </div>
                        <div className="min-w-0">
                            <p className="truncate font-display text-lg font-semibold leading-tight">
                                {menu.cafe.name}
                            </p>
                            <p className="truncate text-[11px] font-medium text-muted">
                                {menu.branch.name}{" "}
                                <span className="mx-1">·</span>{" "}
                                {menu.table.name}
                            </p>
                        </div>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        {menu.session?.isOpen && (
                            <button
                                aria-label="Garson çağır"
                                className="btn-icon size-10"
                                onClick={() => void callWaiter("waiter")}
                                type="button"
                            >
                                <Bell aria-hidden="true" className="size-4" />
                            </button>
                        )}
                        {customerLinks}
                        {user?.role === "customer" && (
                            <Link
                                aria-label="Adisyon"
                                className="btn-icon size-10"
                                to={`/masa/${encodeURIComponent(token)}/adisyon`}
                            >
                                <ShoppingBag
                                    aria-hidden="true"
                                    className="size-4"
                                />
                            </Link>
                        )}
                        <button
                            className="btn-primary btn-sm relative"
                            disabled={!itemCount}
                            onClick={() => setCartOpen(true)}
                            type="button"
                        >
                            <ShoppingBag
                                aria-hidden="true"
                                className="size-4"
                            />
                            <span className="hidden sm:inline">Sepet</span>
                            <span className="grid min-w-5 place-items-center rounded-full bg-amber px-1.5 text-[10px] text-ink">
                                {itemCount}
                            </span>
                        </button>
                    </div>
                </div>
                {menu.session?.qrOrderPending && (
                    <div className="mx-auto max-w-7xl px-4 pb-3 sm:px-7 lg:px-10">
                        <div className="flex items-center gap-2 rounded-2xl border border-amber/30 bg-amber-soft px-4 py-2.5 text-xs font-semibold text-ink">
                            <span className="size-2 rounded-full bg-amber" />{" "}
                            Önceki siparişiniz garson tarafından alınmayı
                            bekliyor. Bu sırada yeni sipariş gönderemezsiniz.
                        </div>
                    </div>
                )}
            </header>

            <section className="mx-auto max-w-7xl px-4 pb-4 pt-5 sm:px-7 sm:pt-8 lg:px-10">
                {menu.cafe.coverUrl && (
                    <div className="relative mb-5 h-44 overflow-hidden rounded-[1.75rem] bg-sage sm:h-56">
                        <img
                            alt=""
                            className="size-full object-cover"
                            src={menu.cafe.coverUrl}
                        />
                        <div className="absolute inset-0 bg-gradient-to-t from-ink/65 to-transparent" />
                        <div className="absolute bottom-5 left-5 text-white sm:left-7">
                            <p className="text-[10px] font-bold uppercase tracking-[.18em] text-white/75">
                                {menu.branch.name}
                            </p>
                            <h1 className="mt-1 font-display text-3xl font-semibold sm:text-4xl">
                                {menu.cafe.name}
                            </h1>
                        </div>
                    </div>
                )}
                <div className="mb-5 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p className="eyebrow">Masaya özel menü</p>
                        <h1 className="mt-1 font-display text-3xl font-semibold tracking-tight sm:text-4xl">
                            Ne istersin?
                        </h1>
                    </div>
                    {menu.loyalty && (
                        <Link
                            className="inline-flex items-center gap-2 self-start rounded-2xl border border-forest/10 bg-sage px-4 py-3 text-sm font-semibold text-forest"
                            to={`/masa/${encodeURIComponent(token)}/hesabim`}
                        >
                            <BadgeCheck aria-hidden="true" className="size-5" />
                            <span>
                                <span className="block">
                                    {menu.loyalty.points} puanın var
                                </span>
                                <span className="block text-xs font-medium text-forest/70">
                                    Ödüllerini görüntüle
                                </span>
                            </span>
                            <ArrowRight aria-hidden="true" className="size-4" />
                        </Link>
                    )}
                </div>
                {menu.announcements.length > 0 && (
                    <div className="mb-5 grid gap-2 sm:grid-cols-2">
                        {menu.announcements.map((announcement) => (
                            <aside
                                className="rounded-2xl border border-terracotta/15 bg-terracotta-soft/60 px-4 py-3"
                                key={announcement.id}
                            >
                                <p className="text-sm font-bold text-ink">
                                    {announcement.title}
                                </p>
                                {announcement.body && (
                                    <p className="mt-0.5 text-xs leading-5 text-muted">
                                        {announcement.body}
                                    </p>
                                )}
                            </aside>
                        ))}
                    </div>
                )}
                <label className="relative block">
                    <Search
                        aria-hidden="true"
                        className="absolute left-4 top-1/2 size-4 -translate-y-1/2 text-muted"
                    />
                    <input
                        aria-label="Menüde ara"
                        className="input rounded-full bg-paper pl-11"
                        onChange={(event) => {
                            setSearch(event.target.value);
                            if (event.target.value) setCategoryId("all");
                        }}
                        placeholder="Menüde ara..."
                        value={search}
                    />
                </label>
                <nav
                    aria-label="Menü kategorileri"
                    className="mt-3 flex gap-2 overflow-x-auto pb-2"
                >
                    {[
                        { id: "all" as const, name: "Tümü" },
                        ...menu.categories,
                    ].map((category) => (
                        <button
                            className={
                                categoryId === category.id
                                    ? "chip-active"
                                    : "chip"
                            }
                            key={category.id}
                            onClick={() => setCategoryId(category.id)}
                            type="button"
                        >
                            {category.name}
                        </button>
                    ))}
                </nav>
            </section>

            {!search && featured.length > 0 && (
                <section className="border-y border-line bg-paper/50 py-6 sm:py-8">
                    <div className="mx-auto max-w-7xl px-4 sm:px-7 lg:px-10">
                        <div className="mb-4 flex items-end justify-between gap-3">
                            <div>
                                <p className="eyebrow">
                                    Sizin için seçtiklerimiz
                                </p>
                                <h2 className="mt-1 font-display text-2xl font-semibold">
                                    Popüler
                                </h2>
                            </div>
                            <div className="hidden gap-2 sm:flex">
                                <button
                                    aria-label="Önceki ürünler"
                                    className="btn-icon size-9"
                                    onClick={() => scrollPopular(-1)}
                                    type="button"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                <button
                                    aria-label="Sonraki ürünler"
                                    className="btn-icon size-9"
                                    onClick={() => scrollPopular(1)}
                                    type="button"
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                            </div>
                        </div>
                        <div
                            className="flex snap-x snap-mandatory gap-3 overflow-x-auto pb-2 [scrollbar-width:thin]"
                            ref={popularRef}
                        >
                            {featured.map((product) => (
                                <ProductCard
                                    key={`featured-${product.id}`}
                                    product={product}
                                    onAdd={() => openProduct(product)}
                                    featured
                                />
                            ))}
                        </div>
                        {featured.length > 1 && (
                            <div className="mt-2 flex justify-center gap-2 sm:hidden">
                                <button
                                    aria-label="Önceki popüler ürünler"
                                    className="btn-icon size-9"
                                    onClick={() => scrollPopular(-1)}
                                    type="button"
                                >
                                    <ChevronLeft className="size-4" />
                                </button>
                                <span className="self-center text-[11px] font-semibold text-muted">
                                    Kaydırarak keşfet
                                </span>
                                <button
                                    aria-label="Sonraki popüler ürünler"
                                    className="btn-icon size-9"
                                    onClick={() => scrollPopular(1)}
                                    type="button"
                                >
                                    <ChevronRight className="size-4" />
                                </button>
                            </div>
                        )}
                    </div>
                </section>
            )}

            <section className="mx-auto max-w-7xl px-4 py-7 sm:px-7 sm:py-9 lg:px-10">
                <div className="mb-5 flex items-end justify-between">
                    <div>
                        <p className="eyebrow">Menüyü keşfet</p>
                        <h2 className="mt-1 font-display text-2xl font-semibold">
                            {categoryId === "all"
                                ? "Tüm ürünler"
                                : menu.categories.find(
                                      (category) => category.id === categoryId,
                                  )?.name}
                        </h2>
                    </div>
                    <span className="text-xs font-medium text-muted">
                        {visibleProducts.length} ürün
                    </span>
                </div>
                {visibleProducts.length === 0 ? (
                    <div className="card-flat px-5 py-12 text-center">
                        <Coffee
                            aria-hidden="true"
                            className="mx-auto size-8 text-muted/60"
                        />
                        <p className="mt-3 font-semibold">
                            Aradığın ürün bulunamadı
                        </p>
                        <p className="mt-1 text-sm text-muted">
                            Başka bir kelime deneyebilirsin.
                        </p>
                    </div>
                ) : (
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-4">
                        {visibleProducts.map((product) => (
                            <ProductCard
                                key={product.id}
                                onAdd={() => openProduct(product)}
                                product={product}
                            />
                        ))}
                    </div>
                )}
            </section>

            {menu.loyalty?.campaigns?.length ? (
                <section className="mx-auto max-w-7xl px-4 pb-7 sm:px-7 lg:px-10">
                    <div className="rounded-[1.75rem] bg-forest px-5 py-5 text-white sm:flex sm:items-center sm:justify-between sm:px-7">
                        <div>
                            <p className="text-[10px] font-bold uppercase tracking-[.18em] text-amber">
                                Sadakat ödülleri
                            </p>
                            <h2 className="mt-1 font-display text-2xl font-semibold">
                                Sipariş verdikçe kazan.
                            </h2>
                            <p className="mt-1 text-sm text-white/70">
                                {menu.loyalty.campaigns
                                    .slice(0, 2)
                                    .map((campaign) => campaign.name)
                                    .join(" · ")}
                            </p>
                        </div>
                        <Link
                            className="btn-amber mt-4 sm:mt-0"
                            to={`/masa/${encodeURIComponent(token)}/hesabim`}
                        >
                            Ödüllerime bak <ArrowRight className="size-4" />
                        </Link>
                    </div>
                </section>
            ) : null}

            <section className="mx-auto max-w-7xl px-4 pb-8 sm:px-7 lg:px-10">
                <div className="flex flex-col gap-4 rounded-[1.75rem] border border-line bg-paper p-5 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                    <div>
                        <p className="text-[10px] font-bold uppercase tracking-[.16em] text-terracotta">
                            Öğrenci misin?
                        </p>
                        <h2 className="mt-1 font-display text-2xl font-semibold">
                            SkyKite tam senin için.
                        </h2>
                        <p className="mt-1 max-w-xl text-sm leading-6 text-muted">
                            KKTC’den Türkiye’ye ürün getirtmenin kolay yolu.
                            İhtiyacın olan ürünleri keşfet, SkyKite ile kapına
                            getirt.
                        </p>
                    </div>
                    <a
                        className="btn-secondary shrink-0"
                        href={menu.skyKitePromotion.url}
                        rel="noreferrer"
                        target="_blank"
                    >
                        SkyKite’ı keşfet <ArrowRight className="size-4" />
                    </a>
                </div>
            </section>

            <footer className="border-t border-line px-4 py-5 text-center text-[11px] text-muted">
                {menu.cafe.name} · {menu.table.name}{" "}
                <span className="mx-1">·</span> Powered by{" "}
                <a
                    className="font-bold text-forest"
                    href={menu.skyKitePromotion.url}
                    rel="noreferrer"
                    target="_blank"
                >
                    SkyKite
                </a>
            </footer>

            {itemCount > 0 && (
                <div className="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-paper/95 p-3 shadow-bar backdrop-blur-xl sm:hidden">
                    <button
                        className="btn-primary w-full rounded-2xl"
                        onClick={() => setCartOpen(true)}
                        type="button"
                    >
                        <ShoppingBag className="size-4" /> Sepeti görüntüle{" "}
                        <span className="rounded-full bg-amber px-2 py-0.5 text-xs text-ink">
                            {itemCount}
                        </span>
                        <span className="ml-auto">
                            {formatCurrency(totalCents)}
                        </span>
                    </button>
                </div>
            )}

            {selectedProduct && (
                <ProductDialog
                    product={selectedProduct}
                    selected={selectedOptions}
                    note={productNote}
                    onNote={setProductNote}
                    onToggle={toggleOption}
                    onClose={() => setSelectedProduct(null)}
                    onAdd={() => {
                        const invalid = selectedProduct.optionGroups.find(
                            (group) => {
                                const count = group.options.filter((option) =>
                                    selectedOptions.includes(option.id),
                                ).length;
                                const max =
                                    group.selectionMode === "single"
                                        ? 1
                                        : (group.maxSelections ??
                                          group.options.length);
                                return (
                                    count < group.minSelections || count > max
                                );
                            },
                        );
                        if (invalid) {
                            setToast(`${invalid.name} seçimini kontrol et.`);
                            return;
                        }
                        addProduct(
                            selectedProduct,
                            selectedOptions,
                            productNote,
                        );
                    }}
                />
            )}

            {cartOpen && (
                <Drawer title="Sepetin" onClose={() => setCartOpen(false)}>
                    {cart.length === 0 ? (
                        <EmptyCart />
                    ) : (
                        <>
                            <div className="flex-1 space-y-3 overflow-y-auto p-4">
                                {cart.map((line) => (
                                    <CartLineView
                                        key={line.key}
                                        line={line}
                                        onQuantity={(step) =>
                                            updateQuantity(line.key, step)
                                        }
                                    />
                                ))}
                            </div>
                            <div className="border-t border-line p-4">
                                <div className="mb-3 flex justify-between text-sm">
                                    <span className="text-muted">
                                        Ara toplam
                                    </span>
                                    <strong>
                                        {formatCurrency(totalCents)}
                                    </strong>
                                </div>
                                <button
                                    className="btn-primary w-full rounded-2xl"
                                    disabled={!menu.orderingAvailable}
                                    onClick={beginCheckout}
                                    type="button"
                                >
                                    Siparişi gözden geçir{" "}
                                    <ArrowRight className="size-4" />
                                </button>
                                <p className="mt-2 text-center text-[11px] text-muted">
                                    Onaylamadan önce ürünleri ve toplamı
                                    göreceksin.
                                </p>
                            </div>
                        </>
                    )}
                </Drawer>
            )}

            {confirmOpen && (
                <Modal
                    onClose={() => setConfirmOpen(false)}
                    title="Siparişini onayla"
                >
                    {error && (
                        <p className="mb-3 rounded-xl bg-danger-soft px-3 py-2.5 text-sm font-semibold text-danger">
                            {error}
                        </p>
                    )}
                    <div className="max-h-[38dvh] space-y-3 overflow-y-auto">
                        {cart.map((line) => (
                            <div
                                className="flex justify-between gap-3 text-sm"
                                key={line.key}
                            >
                                <div>
                                    <p className="font-semibold">
                                        {line.quantity} × {line.product.name}
                                    </p>
                                    {line.optionLabels.length > 0 && (
                                        <p className="mt-0.5 text-xs text-muted">
                                            {line.optionLabels.join(" · ")}
                                        </p>
                                    )}
                                    {line.note && (
                                        <p className="mt-0.5 text-xs text-muted">
                                            Not: {line.note}
                                        </p>
                                    )}
                                </div>
                                <span className="shrink-0 font-semibold">
                                    {formatCurrency(
                                        line.unitPriceCents * line.quantity,
                                    )}
                                </span>
                            </div>
                        ))}
                    </div>
                    {suggestedIds.length > 0 && (
                        <div className="mt-4 rounded-2xl bg-amber-soft/65 p-3">
                            <p className="text-sm font-bold">
                                Yanına ister misin?
                            </p>
                            <p className="mt-0.5 text-xs text-muted">
                                Sepetine ekleyebileceğin birkaç ürün.
                            </p>
                            <div className="mt-2 space-y-2">
                                {suggestedIds
                                    .map((id) => productsById.get(id))
                                    .filter((product): product is MenuProduct =>
                                        Boolean(product),
                                    )
                                    .map((product) => (
                                        <div
                                            className="flex items-center justify-between gap-2 rounded-xl bg-paper px-3 py-2"
                                            key={product.id}
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold">
                                                    {product.name}
                                                </p>
                                                <p className="text-xs text-muted">
                                                    {formatCurrency(
                                                        product.priceCents,
                                                    )}
                                                </p>
                                            </div>
                                            <button
                                                className="btn-secondary btn-sm shrink-0"
                                                onClick={() =>
                                                    addSuggested(product)
                                                }
                                                type="button"
                                            >
                                                <Plus className="size-3.5" />{" "}
                                                Ekle
                                            </button>
                                        </div>
                                    ))}
                            </div>
                        </div>
                    )}
                    <div className="mt-4 flex justify-between border-t border-line pt-3 text-base">
                        <span className="font-semibold">Toplam</span>
                        <strong>{formatCurrency(totalCents)}</strong>
                    </div>
                    <div className="mt-4 grid grid-cols-2 gap-2">
                        <button
                            className="btn-secondary rounded-2xl"
                            disabled={submitting}
                            onClick={() => {
                                setConfirmOpen(false);
                                setCartOpen(true);
                            }}
                            type="button"
                        >
                            Sepeti düzenle
                        </button>
                        <button
                            className="btn-primary rounded-2xl"
                            disabled={submitting}
                            onClick={() => void sendOrder()}
                            type="button"
                        >
                            {submitting ? "Gönderiliyor…" : "Siparişi gönder"}
                        </button>
                    </div>
                    <p className="mt-2 text-center text-[11px] leading-5 text-muted">
                        Göndererek siparişini kafeye iletmiş olursun. Kafe,
                        siparişi garson onayladıktan sonra hazırlar.
                    </p>
                </Modal>
            )}

            {toast && (
                <div
                    aria-live="polite"
                    className="toast-success fixed bottom-20 left-1/2 z-[70] w-max max-w-[calc(100%-2rem)] -translate-x-1/2 sm:bottom-6"
                >
                    {toast}
                </div>
            )}
        </main>
    );
}

function ProductCard({
    product,
    onAdd,
    featured = false,
}: {
    product: MenuProduct;
    onAdd: () => void;
    featured?: boolean;
}) {
    return (
        <article
            className={`group overflow-hidden rounded-[1.5rem] border border-line bg-paper shadow-soft ${featured ? "w-[78vw] min-w-[78vw] snap-start sm:w-[calc((100%-2rem)/3)] sm:min-w-[calc((100%-2rem)/3)] lg:w-[calc((100%-3rem)/4)] lg:min-w-[calc((100%-3rem)/4)]" : ""}`}
        >
            <button
                className="block w-full text-left"
                onClick={onAdd}
                type="button"
            >
                <div
                    className={`relative overflow-hidden bg-gradient-to-br from-sage via-amber-soft to-terracotta-soft ${featured ? "h-40 sm:h-48" : "aspect-[1.18] sm:aspect-[1.25]"}`}
                >
                    {product.imageUrl ? (
                        <img
                            alt={product.name}
                            className="size-full object-cover transition duration-500 group-hover:scale-105"
                            loading="lazy"
                            src={product.imageUrl}
                        />
                    ) : (
                        <div className="grid size-full place-items-center text-forest/50">
                            <Coffee aria-hidden="true" className="size-9" />
                        </div>
                    )}
                    {product.prepMinutes > 0 && (
                        <span className="absolute right-2 top-2 rounded-full bg-paper/95 px-2.5 py-1 text-[10px] font-bold text-ink">
                            {product.prepMinutes} dk
                        </span>
                    )}
                </div>
                <div className="flex items-start justify-between gap-2 p-3 sm:p-4">
                    <div className="min-w-0">
                        <p className="truncate font-display text-base font-semibold sm:text-lg">
                            {product.name}
                        </p>
                        {product.description && (
                            <p className="mt-0.5 line-clamp-2 text-xs leading-5 text-muted">
                                {product.description}
                            </p>
                        )}
                        <p className="mt-1.5 text-sm font-bold text-forest">
                            {formatCurrency(product.priceCents)}
                        </p>
                    </div>
                    <span
                        aria-hidden="true"
                        className="grid size-9 shrink-0 place-items-center self-end rounded-full bg-forest text-white transition group-hover:bg-amber group-hover:text-ink"
                    >
                        <Plus className="size-4" />
                    </span>
                </div>
            </button>
        </article>
    );
}

function ProductDialog({
    product,
    selected,
    note,
    onNote,
    onToggle,
    onClose,
    onAdd,
}: {
    product: MenuProduct;
    selected: number[];
    note: string;
    onNote: (note: string) => void;
    onToggle: (group: OptionGroup, option: number) => void;
    onClose: () => void;
    onAdd: () => void;
}) {
    const delta = product.optionGroups
        .flatMap((group) => group.options)
        .filter((option) => selected.includes(option.id))
        .reduce((sum, option) => sum + option.priceDeltaCents, 0);
    return (
        <Modal onClose={onClose} title={product.name}>
            {product.imageUrl && (
                <img
                    alt={product.name}
                    className="mb-4 max-h-44 w-full rounded-2xl object-cover"
                    src={product.imageUrl}
                />
            )}
            {product.description && (
                <p className="-mt-1 mb-4 text-sm leading-6 text-muted">
                    {product.description}
                </p>
            )}
            <div className="max-h-[42dvh] space-y-4 overflow-y-auto pr-1">
                {product.optionGroups.map((group) => {
                    const pickedCount = group.options.filter((option) =>
                        selected.includes(option.id),
                    ).length;
                    const required = group.minSelections > 0;
                    return (
                        <fieldset
                            className="rounded-2xl border border-line p-3"
                            key={group.id}
                        >
                            <legend className="px-1 text-sm font-bold">
                                {group.name}{" "}
                                <span className="ml-1 text-[10px] font-medium text-muted">
                                    {required ? "Gerekli" : "İsteğe bağlı"}
                                    {group.selectionMode === "multiple"
                                        ? ` · en fazla ${group.maxSelections ?? group.options.length}`
                                        : ""}
                                </span>
                            </legend>
                            <div className="mt-1 space-y-1">
                                {group.options.map((option) => {
                                    const checked = selected.includes(
                                        option.id,
                                    );
                                    return (
                                        <label
                                            className="flex cursor-pointer items-center gap-3 rounded-xl px-2 py-2.5 hover:bg-cream"
                                            key={option.id}
                                        >
                                            <input
                                                checked={checked}
                                                className="size-4 accent-forest"
                                                onChange={() =>
                                                    onToggle(group, option.id)
                                                }
                                                type={
                                                    group.selectionMode ===
                                                    "single"
                                                        ? "radio"
                                                        : "checkbox"
                                                }
                                                name={`group-${group.id}`}
                                            />
                                            <span className="flex-1 text-sm font-medium">
                                                {option.name}
                                            </span>
                                            <span className="text-xs font-semibold text-muted">
                                                {option.priceDeltaCents === 0
                                                    ? "Ücretsiz"
                                                    : `+${formatCurrency(option.priceDeltaCents)}`}
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                            {required && pickedCount < group.minSelections && (
                                <p className="mt-1 text-[11px] font-semibold text-terracotta">
                                    En az {group.minSelections} seçim yap.
                                </p>
                            )}
                        </fieldset>
                    );
                })}
            </div>
            <label className="mt-4 block">
                <span className="label">
                    Ürün notu{" "}
                    <span className="font-medium normal-case tracking-normal">
                        (isteğe bağlı)
                    </span>
                </span>
                <input
                    className="input"
                    maxLength={140}
                    onChange={(event) => onNote(event.target.value)}
                    placeholder="Örn. az buzlu"
                    value={note}
                />
            </label>
            <button
                className="btn-primary mt-4 w-full rounded-2xl"
                onClick={onAdd}
                type="button"
            >
                <Plus className="size-4" /> Sepete ekle{" "}
                <span className="ml-auto">
                    {formatCurrency(product.priceCents + delta)}
                </span>
            </button>
        </Modal>
    );
}

function CartLineView({
    line,
    onQuantity,
}: {
    line: CartLine;
    onQuantity: (step: number) => void;
}) {
    return (
        <article className="rounded-2xl border border-line bg-paper p-3">
            <div className="flex gap-3">
                <div className="size-14 shrink-0 overflow-hidden rounded-xl bg-sage">
                    {line.product.imageUrl ? (
                        <img
                            alt=""
                            className="size-full object-cover"
                            src={line.product.imageUrl}
                        />
                    ) : (
                        <div className="grid size-full place-items-center text-forest">
                            <Coffee className="size-5" />
                        </div>
                    )}
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex justify-between gap-2">
                        <p className="truncate text-sm font-bold">
                            {line.product.name}
                        </p>
                        <strong className="shrink-0 text-sm">
                            {formatCurrency(
                                line.unitPriceCents * line.quantity,
                            )}
                        </strong>
                    </div>
                    {line.optionLabels.length > 0 && (
                        <p className="mt-0.5 text-[11px] leading-4 text-muted">
                            {line.optionLabels.join(" · ")}
                        </p>
                    )}
                    {line.note && (
                        <p className="mt-0.5 text-[11px] text-muted">
                            Not: {line.note}
                        </p>
                    )}
                    <div className="mt-2 flex items-center gap-2">
                        <button
                            aria-label="Bir tane azalt"
                            className="btn-icon size-8"
                            onClick={() => onQuantity(-1)}
                            type="button"
                        >
                            <Minus className="size-3.5" />
                        </button>
                        <span className="min-w-5 text-center text-sm font-bold">
                            {line.quantity}
                        </span>
                        <button
                            aria-label="Bir tane artır"
                            className="btn-icon size-8"
                            onClick={() => onQuantity(1)}
                            type="button"
                        >
                            <Plus className="size-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </article>
    );
}

function Drawer({
    title,
    onClose,
    children,
}: {
    title: string;
    onClose: () => void;
    children: React.ReactNode;
}) {
    return (
        <div
            className="fixed inset-0 z-50 bg-ink/45 backdrop-blur-[2px]"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
        >
            <section
                aria-label={title}
                className="absolute inset-x-0 bottom-0 flex max-h-[92dvh] flex-col rounded-t-[2rem] border border-line bg-paper shadow-lift sm:inset-y-0 sm:left-auto sm:right-0 sm:max-h-none sm:w-full sm:max-w-md sm:rounded-none sm:rounded-l-[2rem]"
            >
                <div className="flex items-center justify-between border-b border-line px-4 py-4 sm:px-6">
                    <div>
                        <p className="eyebrow">{title}</p>
                        <p className="font-display text-xl font-semibold">
                            Ürünlerini kontrol et
                        </p>
                    </div>
                    <button
                        aria-label="Sepeti kapat"
                        className="btn-icon size-10"
                        onClick={onClose}
                        type="button"
                    >
                        <X className="size-4" />
                    </button>
                </div>
                {children}
            </section>
        </div>
    );
}

function Modal({
    title,
    onClose,
    children,
}: {
    title: string;
    onClose: () => void;
    children: React.ReactNode;
}) {
    return (
        <div
            className="fixed inset-0 z-50 flex items-end justify-center bg-ink/45 p-0 backdrop-blur-[2px] sm:items-center sm:p-5"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
        >
            <section
                aria-label={title}
                aria-modal="true"
                className="max-h-[92dvh] w-full overflow-y-auto rounded-t-[2rem] border border-line bg-paper p-4 shadow-lift sm:max-w-lg sm:rounded-[2rem] sm:p-6"
                role="dialog"
            >
                <div className="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <p className="eyebrow">
                            {title === "Siparişini onayla"
                                ? "Son kontrol"
                                : "Ürün detayları"}
                        </p>
                        <h2 className="mt-1 font-display text-2xl font-semibold">
                            {title}
                        </h2>
                    </div>
                    <button
                        aria-label="Kapat"
                        className="btn-icon size-9 shrink-0"
                        onClick={onClose}
                        type="button"
                    >
                        <X className="size-4" />
                    </button>
                </div>
                {children}
            </section>
        </div>
    );
}

function EmptyCart() {
    return (
        <div className="grid flex-1 place-items-center p-8 text-center">
            <div>
                <ShoppingBag className="mx-auto size-9 text-muted/50" />
                <p className="mt-3 font-semibold">Sepetin henüz boş</p>
                <p className="mt-1 text-sm text-muted">
                    Menüden ürün seçip buraya ekleyebilirsin.
                </p>
            </div>
        </div>
    );
}

function LoadingScreen() {
    return (
        <main className="grid min-h-dvh place-items-center p-5">
            <div className="w-full max-w-3xl space-y-4">
                <div className="skeleton h-14" />
                <div className="skeleton h-12 w-2/3" />
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {Array.from({ length: 8 }, (_, index) => (
                        <div className="skeleton aspect-[.9]" key={index} />
                    ))}
                </div>
            </div>
        </main>
    );
}
function ErrorScreen({
    message,
    retry,
}: {
    message: string;
    retry: () => void;
}) {
    return (
        <main className="grid min-h-dvh place-items-center p-5">
            <div className="card w-full max-w-md p-7 text-center">
                <Coffee className="mx-auto size-9 text-forest" />
                <h1 className="mt-4 font-display text-2xl font-semibold">
                    Menü açılamadı
                </h1>
                <p className="mt-2 text-sm leading-6 text-muted">{message}</p>
                <button
                    className="btn-primary mt-5"
                    onClick={retry}
                    type="button"
                >
                    Tekrar dene
                </button>
                <Link
                    className="mt-4 block text-sm font-semibold text-forest"
                    to="/"
                >
                    Ana sayfaya dön
                </Link>
            </div>
        </main>
    );
}
