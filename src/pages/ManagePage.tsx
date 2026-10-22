import {
    ArrowRight,
    BadgeCheck,
    Coffee,
    Copy,
    ExternalLink,
    LoaderCircle,
    LogOut,
    Plus,
    Printer,
    RefreshCw,
    Settings2,
    Store,
    Tags,
    ToggleLeft,
    ToggleRight,
    Trash2,
    X,
} from "lucide-react";
import { QRCodeSVG } from "qrcode.react";
import {
    useCallback,
    useEffect,
    useMemo,
    useState,
    type FormEvent,
} from "react";
import { Navigate } from "react-router-dom";
import { useAuth } from "../auth/AuthProvider.js";
import { formatCurrency, toCents, toDecimal } from "../domain/money.js";
import {
    ApiClientError,
    apiPatch,
    apiPost,
    apiRequest,
    apiUpload,
} from "../lib/api.js";

interface Branch {
    id: number;
    name: string;
    address: string | null;
    phone: string | null;
    usesSharedMenu: boolean;
    active: boolean;
    activeTables: number;
    openTables: number;
}
interface Overview {
    cafe: {
        id: number;
        name: string;
        slug: string;
        status: string;
        registrationPolicy: string;
        loyaltyEnabled: boolean;
        pointsPer100: number;
    };
    branches: Branch[];
    metrics: {
        activeBranches: number;
        activeTables: number;
        openTables: number;
        activeCustomers: number;
        todayCollectedCents: number;
    };
}
interface Category {
    id: number;
    name: string;
    description: string;
    sortOrder: number;
    isActive: boolean;
}
interface OptionEditor {
    name: string;
    price: string;
}
interface OptionGroupEditor {
    id?: number;
    name: string;
    selectionMode: "single" | "multiple";
    minSelections: number;
    maxSelections: string;
    options: OptionEditor[];
}
interface Product {
    id: number;
    categoryId: number;
    name: string;
    description: string;
    imageUrl: string;
    basePriceCents: number;
    priceCents: number;
    prepMinutes: number;
    isAvailable: boolean;
    isFeatured: boolean;
    optionGroups: {
        id: number;
        name: string;
        selectionMode: "single" | "multiple";
        minSelections: number;
        maxSelections: number | null;
        options: {
            id: number;
            name: string;
            priceDeltaCents: number;
            isAvailable: boolean;
        }[];
    }[];
}
interface ProductForm {
    categoryId: number;
    name: string;
    description: string;
    imageUrl: string;
    price: string;
    prepMinutes: number;
    isAvailable: boolean;
    isFeatured: boolean;
    optionGroups: OptionGroupEditor[];
}
interface MenuData {
    branchId: number;
    menuMode: "shared" | "independent";
    categories: Category[];
    products: Product[];
}
interface DiningTable {
    id: number;
    name: string;
    active: boolean;
    sessionOpen: boolean;
    sessionId: number | null;
    qrUrl: string;
}
interface DashboardTable {
    id: number;
    name: string;
    slug: string;
    status: string;
    createdAt: string;
    owner: { id: number; name: string; email: string } | null;
    branches: number;
    activeTables: number;
    activeCustomers: number;
}
interface StaffMember {
    id: number;
    name: string;
    email: string;
    role: "cafe_manager" | "waiter" | "kitchen";
    active: boolean;
    branches: { id: number; name: string }[];
}
interface StaffForm {
    name: string;
    email: string;
    role: StaffMember["role"];
    branchIds: number[];
}
interface LoyaltyCampaign {
    id: number;
    name: string;
    type: "order_count" | "points";
    threshold: number;
    pointsRequired: number;
    rewardType: "fixed" | "percent";
    rewardCents: number;
    rewardBasisPoints: number;
    minimumOrderCents: number;
    active: boolean;
}

const emptyProduct = (): ProductForm => ({
    categoryId: 0,
    name: "",
    description: "",
    imageUrl: "",
    price: "0,00",
    prepMinutes: 0,
    isAvailable: true,
    isFeatured: false,
    optionGroups: [],
});
function messageOf(error: unknown) {
    return error instanceof ApiClientError
        ? error.message
        : "İşlem tamamlanamadı. Tekrar deneyin.";
}

export function ManagePage() {
    const { user, csrfToken, loading, logout } = useAuth();
    const [overview, setOverview] = useState<Overview | null>(null);
    const [branchId, setBranchId] = useState<number | null>(null);
    const [menu, setMenu] = useState<MenuData | null>(null);
    const [tables, setTables] = useState<DiningTable[]>([]);
    const [tab, setTab] = useState<"menu" | "tables" | "branches" | "settings">(
        "menu",
    );
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [busy, setBusy] = useState(false);
    const [productEditor, setProductEditor] = useState<Product | null | "new">(
        null,
    );
    const [categoryName, setCategoryName] = useState("");
    const [tableName, setTableName] = useState("");
    const [branchName, setBranchName] = useState("");
    const [sourceBranchId, setSourceBranchId] = useState("");
    const [newTable, setNewTable] = useState<{
        name: string;
        qrUrl: string;
    } | null>(null);
    const [switchDialog, setSwitchDialog] = useState(false);
    const [staff, setStaff] = useState<StaffMember[]>([]);
    const [campaigns, setCampaigns] = useState<LoyaltyCampaign[]>([]);
    const [campaignType, setCampaignType] =
        useState<LoyaltyCampaign["type"]>("order_count");
    const [campaignRewardType, setCampaignRewardType] =
        useState<LoyaltyCampaign["rewardType"]>("fixed");
    const [staffEditor, setStaffEditor] = useState<StaffMember | "new" | null>(
        null,
    );
    const [staffCredential, setStaffCredential] = useState<{
        name: string;
        email: string;
        password: string;
    } | null>(null);

    const refreshOverview = useCallback(async () => {
        try {
            const data = await apiRequest<Overview>("/api/manage/overview");
            setOverview(data);
            setBranchId((current) =>
                current && data.branches.some((branch) => branch.id === current)
                    ? current
                    : (data.branches[0]?.id ?? null),
            );
            setError("");
        } catch (reason) {
            setError(messageOf(reason));
        }
    }, []);
    const refreshBranch = useCallback(async () => {
        if (!branchId) return;
        try {
            const [menuData, tableData] = await Promise.all([
                apiRequest<MenuData>(`/api/manage/menu?branchId=${branchId}`),
                apiRequest<{ tables: DiningTable[] }>(
                    `/api/manage/branches/${branchId}/tables`,
                ),
            ]);
            setMenu(menuData);
            setTables(tableData.tables);
            setError("");
        } catch (reason) {
            setError(messageOf(reason));
        }
    }, [branchId]);
    const refreshStaff = useCallback(async () => {
        try {
            const data = await apiRequest<{ staff: StaffMember[] }>(
                "/api/manage/staff",
            );
            setStaff(data.staff);
        } catch (reason) {
            setError(messageOf(reason));
        }
    }, []);
    const refreshCampaigns = useCallback(async () => {
        try {
            const data = await apiRequest<{ campaigns: LoyaltyCampaign[] }>(
                "/api/manage/loyalty-campaigns",
            );
            setCampaigns(data.campaigns);
        } catch (reason) {
            setError(messageOf(reason));
        }
    }, []);
    useEffect(() => {
        if (user?.role === "cafe_owner" || user?.role === "cafe_manager")
            void refreshOverview();
    }, [refreshOverview, user]);
    useEffect(() => {
        void refreshBranch();
    }, [refreshBranch]);
    useEffect(() => {
        if (tab === "settings") {
            void refreshStaff();
            void refreshCampaigns();
        }
    }, [refreshCampaigns, refreshStaff, tab]);

    const productsByCategory = useMemo(
        () =>
            new Map(
                (menu?.categories ?? []).map((category) => [
                    category.id,
                    menu?.products.filter(
                        (product) => product.categoryId === category.id,
                    ) ?? [],
                ]),
            ),
        [menu],
    );

    async function post(path: string, body: unknown, success: string) {
        setBusy(true);
        setError("");
        setNotice("");
        try {
            await apiPost(path, body, csrfToken);
            setNotice(success);
            await refreshOverview();
            await refreshBranch();
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    async function patch(path: string, body: unknown, success: string) {
        setBusy(true);
        setError("");
        setNotice("");
        try {
            await apiPatch(path, body, csrfToken);
            setNotice(success);
            await refreshOverview();
            await refreshBranch();
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    async function saveStaff(values: StaffForm, existing: StaffMember | null) {
        setBusy(true);
        setError("");
        try {
            if (existing) {
                await apiPatch(
                    `/api/manage/staff/${existing.id}`,
                    values,
                    csrfToken,
                );
                setNotice("Çalışan bilgileri güncellendi.");
            } else {
                const result = await apiPost<{ temporaryPassword: string }>(
                    "/api/manage/staff",
                    values,
                    csrfToken,
                );
                setStaffCredential({
                    name: values.name,
                    email: values.email,
                    password: result.temporaryPassword,
                });
                setNotice(
                    "Çalışan hesabı açıldı. Geçici şifreyi güvenli şekilde iletin.",
                );
            }
            setStaffEditor(null);
            await refreshStaff();
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    async function resetStaffPassword(person: StaffMember) {
        if (
            !window.confirm(
                `${person.name} için geçici şifre yenilensin mi? Mevcut oturumları kapatılır.`,
            )
        )
            return;
        setBusy(true);
        try {
            const result = await apiPost<{
                email: string;
                temporaryPassword: string;
            }>(`/api/manage/staff/${person.id}/reset-password`, {}, csrfToken);
            setStaffCredential({
                name: person.name,
                email: result.email,
                password: result.temporaryPassword,
            });
            setNotice(
                "Geçici şifre hazır. Bu ekranı kapatınca tekrar gösterilmez.",
            );
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    async function changeStaffActive(person: StaffMember) {
        setBusy(true);
        try {
            await apiPatch(
                `/api/manage/staff/${person.id}`,
                { active: !person.active },
                csrfToken,
            );
            await refreshStaff();
            setNotice(
                person.active
                    ? "Çalışan hesabı kapatıldı."
                    : "Çalışan hesabı etkinleştirildi.",
            );
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    async function saveLoyaltyCampaign(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const target = event.currentTarget;
        const form = new FormData(target);
        const type = String(form.get("type")) as LoyaltyCampaign["type"];
        const rewardType = String(
            form.get("rewardType"),
        ) as LoyaltyCampaign["rewardType"];
        const rawValue = String(form.get("rewardValue") ?? "");
        let valueCents = 0;
        let valueBasisPoints = 0;
        try {
            if (rewardType === "fixed") valueCents = toCents(rawValue);
            else {
                valueBasisPoints = Math.round(
                    Number(rawValue.replace(",", ".")) * 100,
                );
                if (
                    !Number.isInteger(valueBasisPoints) ||
                    valueBasisPoints < 1 ||
                    valueBasisPoints > 10000
                )
                    throw new RangeError(
                        "İndirim yüzde 0 ile 100 arasında olmalı.",
                    );
            }
            const minimumOrderCents = toCents(
                String(form.get("minimumOrder") ?? "0"),
            );
            setBusy(true);
            setError("");
            await apiPost(
                "/api/manage/loyalty-campaigns",
                {
                    name: String(form.get("name") ?? ""),
                    type,
                    threshold:
                        type === "order_count"
                            ? Number(form.get("threshold"))
                            : 0,
                    pointsRequired:
                        type === "points"
                            ? Number(form.get("pointsRequired"))
                            : 0,
                    rewardType,
                    rewardCents: valueCents,
                    rewardBasisPoints: valueBasisPoints,
                    minimumOrderCents,
                },
                csrfToken,
            );
            target.reset();
            setCampaignType("order_count");
            setCampaignRewardType("fixed");
            setNotice("Sadakat ödülü oluşturuldu.");
            await refreshCampaigns();
        } catch (reason) {
            setError(
                reason instanceof Error ? reason.message : messageOf(reason),
            );
        } finally {
            setBusy(false);
        }
    }
    async function changeCampaignActive(campaign: LoyaltyCampaign) {
        setBusy(true);
        setError("");
        try {
            await apiPatch(
                `/api/manage/loyalty-campaigns/${campaign.id}`,
                { active: !campaign.active },
                csrfToken,
            );
            setNotice(
                campaign.active ? "Ödül pasife alındı." : "Ödül tekrar açıldı.",
            );
            await refreshCampaigns();
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }

    async function submitCategory(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!branchId) return;
        await post(
            "/api/manage/categories",
            { branchId, name: categoryName },
            "Kategori eklendi.",
        );
        setCategoryName("");
    }
    async function submitTable(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!branchId) return;
        setBusy(true);
        setError("");
        setNewTable(null);
        try {
            const created = await apiPost<{ name: string; qrUrl: string }>(
                `/api/manage/branches/${branchId}/tables`,
                { name: tableName },
                csrfToken,
            );
            setTableName("");
            setNewTable(created);
            setNotice("Masa ve yeni QR bağlantısı oluşturuldu.");
            await refreshOverview();
            await refreshBranch();
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    async function submitBranch(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setBusy(true);
        setError("");
        try {
            await apiPost(
                "/api/manage/branches",
                { name: branchName },
                csrfToken,
            );
            setBranchName("");
            setNotice("Şube eklendi.");
            await refreshOverview();
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    async function saveProduct(values: ProductForm, existing: Product | null) {
        if (!branchId) return;
        let priceCents: number;
        try {
            priceCents = toCents(values.price);
        } catch {
            setError("Geçerli bir fiyat girin.");
            return;
        }
        const payload = {
            branchId,
            categoryId: values.categoryId,
            name: values.name,
            description: values.description,
            imageUrl: values.imageUrl,
            priceCents,
            prepMinutes: values.prepMinutes,
            isAvailable: values.isAvailable,
            isFeatured: values.isFeatured,
            optionGroups: values.optionGroups.map((group) => ({
                name: group.name,
                selectionMode: group.selectionMode,
                minSelections: group.minSelections,
                maxSelections:
                    group.maxSelections === ""
                        ? null
                        : Number(group.maxSelections),
                options: group.options
                    .filter((option) => option.name.trim())
                    .map((option) => ({
                        name: option.name,
                        priceDeltaCents: toCents(option.price),
                    })),
            })),
        };
        setBusy(true);
        setError("");
        setNotice("");
        try {
            if (existing)
                await apiPatch(
                    `/api/manage/products/${existing.id}`,
                    payload,
                    csrfToken,
                );
            else await apiPost("/api/manage/products", payload, csrfToken);
            setProductEditor(null);
            setNotice(existing ? "Ürün güncellendi." : "Ürün menüye eklendi.");
            await refreshBranch();
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    async function uploadProductImage(file: File) {
        const uploaded = await apiUpload<{ url: string }>(
            "/api/manage/media",
            file,
            csrfToken,
        );
        return uploaded.url;
    }
    async function changeProductAvailability(product: Product) {
        if (!branchId) return;
        await patch(
            `/api/manage/products/${product.id}`,
            { branchId, isAvailable: !product.isAvailable },
            product.isAvailable
                ? "Ürün satışa kapatıldı."
                : "Ürün satışa açıldı.",
        );
    }
    async function switchMenu(mode: "shared" | "independent") {
        if (!branchId) return;
        const source =
            mode === "independent"
                ? sourceBranchId
                    ? Number(sourceBranchId)
                    : null
                : null;
        await post(
            `/api/manage/branches/${branchId}/menu`,
            { mode, sourceBranchId: source },
            mode === "shared"
                ? "Ortak menü açıldı."
                : "Şube menüsü bağımsız hale getirildi.",
        );
        setSwitchDialog(false);
    }
    async function patchTable(
        table: DiningTable,
        body: { active?: boolean; rotateQr?: boolean },
    ) {
        if (!branchId) return;
        if (
            body.rotateQr &&
            !window.confirm(
                `${table.name} için eski QR kodu artık çalışmayacak. Yeni QR oluşturulsun mu?`,
            )
        )
            return;
        setBusy(true);
        setError("");
        try {
            const result = await apiPatch<{ qrUrl?: string }>(
                `/api/manage/branches/${branchId}/tables/${table.id}`,
                { ...body, ...(body.rotateQr ? { rotateQr: true } : {}) },
                csrfToken,
            );
            if (result.qrUrl)
                setNewTable({ name: table.name, qrUrl: result.qrUrl });
            await refreshBranch();
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }

    if (loading) return <Loading />;
    if (!user) return <Navigate replace to="/giris" />;
    if (!["cafe_owner", "cafe_manager"].includes(user.role))
        return (
            <Navigate
                replace
                to={user.role === "platform_admin" ? "/platform" : "/garson"}
            />
        );

    return (
        <main className="min-h-dvh pb-10">
            <header className="sticky top-0 z-20 border-b border-line bg-cream/95 backdrop-blur-xl">
                <div className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-7">
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="grid size-10 shrink-0 place-items-center rounded-2xl bg-forest text-amber">
                            <Coffee className="size-5" />
                        </span>
                        <div className="min-w-0">
                            <p className="truncate font-display text-lg font-semibold">
                                Kafe yönetimi
                            </p>
                            <p className="truncate text-xs text-muted">
                                {overview?.cafe.name ?? user.name}
                            </p>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <select
                            aria-label="Şube seç"
                            className="input min-h-10 max-w-44 rounded-full py-2 text-xs"
                            onChange={(event) =>
                                setBranchId(Number(event.target.value))
                            }
                            value={branchId ?? ""}
                        >
                            {overview?.branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>
                                    {branch.name}
                                </option>
                            ))}
                        </select>
                        <button
                            aria-label="Yenile"
                            className="btn-icon size-10"
                            onClick={() => {
                                void refreshOverview();
                                void refreshBranch();
                            }}
                            type="button"
                        >
                            <RefreshCw className="size-4" />
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
            <div className="mx-auto max-w-7xl px-4 pt-5 sm:px-7">
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
                        label="Şubeler"
                        value={overview?.metrics.activeBranches ?? 0}
                    />
                    <Metric
                        label="Aktif masalar"
                        value={overview?.metrics.activeTables ?? 0}
                    />
                    <Metric
                        label="Açık adisyon"
                        value={overview?.metrics.openTables ?? 0}
                    />
                    <Metric
                        label="Bugün tahsilat"
                        value={formatCurrency(
                            overview?.metrics.todayCollectedCents ?? 0,
                        )}
                    />
                </section>
                <nav
                    aria-label="Kafe yönetim bölümleri"
                    className="mt-5 flex gap-2 overflow-x-auto pb-1"
                >
                    {(
                        [
                            ["menu", "Menü"],
                            ["tables", "Masalar ve QR"],
                            ["branches", "Şubeler"],
                            ["settings", "Kafe ayarları"],
                        ] as const
                    ).map(([id, label]) => (
                        <button
                            className={tab === id ? "chip-active" : "chip"}
                            key={id}
                            onClick={() => setTab(id)}
                            type="button"
                        >
                            {label}
                        </button>
                    ))}
                </nav>

                {tab === "menu" && (
                    <section className="mt-5">
                        <div className="card flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                            <div>
                                <p className="eyebrow">Şube menü biçimi</p>
                                <h1 className="mt-1 font-display text-xl font-semibold">
                                    {menu?.menuMode === "shared"
                                        ? "Ortak kafe menüsü"
                                        : "Bu şubeye özel menü"}
                                </h1>
                                <p className="mt-1 text-sm text-muted">
                                    {menu?.menuMode === "shared"
                                        ? "Ürün ve kategori değişiklikleri tüm şubelere yansır. Şubeye özel fiyat ve bulunurluk verebilirsin."
                                        : "Bu şubedeki değişiklikler diğer şubeleri etkilemez."}
                                </p>
                            </div>
                            <button
                                className="btn-secondary shrink-0"
                                onClick={() => setSwitchDialog(true)}
                                type="button"
                            >
                                <Settings2 className="size-4" /> Menü biçimini
                                değiştir
                            </button>
                        </div>
                        <div className="mt-5 grid gap-5 lg:grid-cols-[280px_1fr]">
                            <aside className="card h-fit p-4">
                                <div className="flex items-center justify-between">
                                    <h2 className="font-display text-lg font-semibold">
                                        Kategoriler
                                    </h2>
                                    <Tags className="size-4 text-forest" />
                                </div>
                                <form
                                    className="mt-3 flex gap-2"
                                    onSubmit={(event) =>
                                        void submitCategory(event)
                                    }
                                >
                                    <input
                                        className="input min-w-0 py-2.5"
                                        onChange={(event) =>
                                            setCategoryName(event.target.value)
                                        }
                                        placeholder="Yeni kategori"
                                        value={categoryName}
                                    />
                                    <button
                                        aria-label="Kategori ekle"
                                        className="btn-primary size-11 shrink-0 rounded-2xl px-0"
                                        disabled={busy || !categoryName.trim()}
                                        type="submit"
                                    >
                                        <Plus className="size-4" />
                                    </button>
                                </form>
                                <div className="mt-3 space-y-1">
                                    {menu?.categories.map((category) => (
                                        <div
                                            className={`flex items-center justify-between gap-2 rounded-xl px-3 py-2 ${category.isActive ? "bg-cream" : "bg-cream/50 text-muted"}`}
                                            key={category.id}
                                        >
                                            <span className="min-w-0 truncate text-sm font-semibold">
                                                {category.name}
                                            </span>
                                            <button
                                                aria-label={
                                                    category.isActive
                                                        ? "Kategoriyi gizle"
                                                        : "Kategoriyi aç"
                                                }
                                                className="text-forest"
                                                onClick={() =>
                                                    void patch(
                                                        `/api/manage/categories/${category.id}`,
                                                        {
                                                            branchId,
                                                            isActive:
                                                                !category.isActive,
                                                        },
                                                        category.isActive
                                                            ? "Kategori gizlendi."
                                                            : "Kategori açıldı.",
                                                    )
                                                }
                                                type="button"
                                            >
                                                {category.isActive ? (
                                                    <ToggleRight className="size-5" />
                                                ) : (
                                                    <ToggleLeft className="size-5" />
                                                )}
                                            </button>
                                        </div>
                                    ))}
                                </div>
                            </aside>
                            <div>
                                <div className="mb-3 flex items-end justify-between gap-3">
                                    <div>
                                        <p className="eyebrow">
                                            Ürün ve fiyatlar
                                        </p>
                                        <h2 className="mt-1 font-display text-2xl font-semibold">
                                            Menün
                                        </h2>
                                    </div>
                                    <button
                                        className="btn-primary btn-sm"
                                        disabled={
                                            !menu?.categories.some(
                                                (category) => category.isActive,
                                            )
                                        }
                                        onClick={() => setProductEditor("new")}
                                        type="button"
                                    >
                                        <Plus className="size-4" /> Ürün ekle
                                    </button>
                                </div>
                                {menu?.categories
                                    .filter((category) => category.isActive)
                                    .map((category) => (
                                        <section
                                            className="mb-4"
                                            key={category.id}
                                        >
                                            <h3 className="mb-2 text-sm font-bold text-muted">
                                                {category.name}
                                            </h3>
                                            <div className="space-y-2">
                                                {(
                                                    productsByCategory.get(
                                                        category.id,
                                                    ) ?? []
                                                ).map((product) => (
                                                    <article
                                                        className={`card-flat flex items-center gap-3 p-3 sm:p-4 ${!product.isAvailable ? "opacity-65" : ""}`}
                                                        key={product.id}
                                                    >
                                                        <div className="grid size-12 shrink-0 place-items-center overflow-hidden rounded-xl bg-sage text-forest">
                                                            {product.imageUrl ? (
                                                                <img
                                                                    alt=""
                                                                    className="size-full object-cover"
                                                                    src={
                                                                        product.imageUrl
                                                                    }
                                                                />
                                                            ) : (
                                                                <Coffee className="size-5" />
                                                            )}
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <p className="truncate text-sm font-bold">
                                                                {product.name}
                                                                {product.isFeatured && (
                                                                    <span className="ml-2 badge-neutral align-middle">
                                                                        Popüler
                                                                    </span>
                                                                )}
                                                            </p>
                                                            <p className="truncate text-xs text-muted">
                                                                {product.description ||
                                                                    "Açıklama eklenmemiş"}{" "}
                                                                ·{" "}
                                                                {
                                                                    product
                                                                        .optionGroups
                                                                        .length
                                                                }{" "}
                                                                seçenek grubu
                                                            </p>
                                                        </div>
                                                        <strong className="shrink-0 text-sm">
                                                            {formatCurrency(
                                                                product.priceCents,
                                                            )}
                                                        </strong>
                                                        <button
                                                            className="btn-secondary btn-sm hidden sm:inline-flex"
                                                            onClick={() =>
                                                                setProductEditor(
                                                                    product,
                                                                )
                                                            }
                                                            type="button"
                                                        >
                                                            Düzenle
                                                        </button>
                                                        <button
                                                            aria-label={
                                                                product.isAvailable
                                                                    ? "Ürünü satışa kapat"
                                                                    : "Ürünü satışa aç"
                                                            }
                                                            className="btn-icon size-9 shrink-0"
                                                            onClick={() =>
                                                                void changeProductAvailability(
                                                                    product,
                                                                )
                                                            }
                                                            type="button"
                                                        >
                                                            {product.isAvailable ? (
                                                                <ToggleRight className="size-4 text-success" />
                                                            ) : (
                                                                <ToggleLeft className="size-4 text-muted" />
                                                            )}
                                                        </button>
                                                        <button
                                                            className="btn-ghost btn-sm sm:hidden"
                                                            onClick={() =>
                                                                setProductEditor(
                                                                    product,
                                                                )
                                                            }
                                                            type="button"
                                                        >
                                                            Düzenle
                                                        </button>
                                                    </article>
                                                ))}
                                            </div>
                                        </section>
                                    ))}
                            </div>
                        </div>
                    </section>
                )}

                {tab === "tables" && (
                    <section className="mt-5">
                        <div className="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p className="eyebrow">QR masalar</p>
                                <h1 className="mt-1 font-display text-2xl font-semibold">
                                    {overview?.branches.find(
                                        (branch) => branch.id === branchId,
                                    )?.name ?? "Şube"}{" "}
                                    masaları
                                </h1>
                            </div>
                            <button
                                className="btn-secondary"
                                onClick={() => window.print()}
                                type="button"
                            >
                                <Printer className="size-4" /> QR kodlarını
                                yazdır
                            </button>
                        </div>
                        <form
                            className="card mt-4 flex flex-col gap-2 p-4 sm:flex-row"
                            onSubmit={(event) => void submitTable(event)}
                        >
                            <input
                                className="input flex-1"
                                onChange={(event) =>
                                    setTableName(event.target.value)
                                }
                                placeholder="Örn. Masa 12"
                                value={tableName}
                            />
                            <button
                                className="btn-primary"
                                disabled={busy || !tableName.trim()}
                                type="submit"
                            >
                                <Plus className="size-4" /> Masa ekle
                            </button>
                        </form>
                        {newTable && (
                            <QrReveal
                                name={newTable.name}
                                url={newTable.qrUrl}
                                onClose={() => setNewTable(null)}
                            />
                        )}
                        <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {tables.map((table) => (
                                <article
                                    className="qr-print-card card-flat flex gap-4 p-4"
                                    key={table.id}
                                >
                                    <QRCodeSVG
                                        className="qr-print-code size-24 shrink-0 rounded-lg bg-white p-1"
                                        value={`${window.location.origin}${table.qrUrl}`}
                                        size={96}
                                        level="M"
                                        includeMargin
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex items-center justify-between gap-2">
                                            <h2 className="truncate font-display text-lg font-semibold">
                                                {table.name}
                                            </h2>
                                            <span
                                                className={`size-2.5 shrink-0 rounded-full ${table.sessionOpen ? "bg-success" : "bg-line"}`}
                                            />
                                        </div>
                                        <p className="mt-0.5 text-xs text-muted">
                                            {table.sessionOpen
                                                ? "Adisyon açık"
                                                : "Adisyon kapalı"}{" "}
                                            ·{" "}
                                            {table.active
                                                ? "QR aktif"
                                                : "QR kapalı"}
                                        </p>
                                        <div className="mt-3 flex flex-wrap gap-1.5">
                                            <button
                                                className="btn-secondary btn-sm"
                                                onClick={() =>
                                                    void navigator.clipboard
                                                        .writeText(
                                                            `${window.location.origin}${table.qrUrl}`,
                                                        )
                                                        .then(() =>
                                                            setNotice(
                                                                "QR bağlantısı kopyalandı.",
                                                            ),
                                                        )
                                                        .catch(() =>
                                                            setError(
                                                                "Bağlantı kopyalanamadı.",
                                                            ),
                                                        )
                                                }
                                                type="button"
                                            >
                                                <Copy className="size-3" />{" "}
                                                Kopyala
                                            </button>
                                            <a
                                                className="btn-secondary btn-sm"
                                                href={table.qrUrl}
                                                rel="noreferrer"
                                                target="_blank"
                                            >
                                                <ExternalLink className="size-3" />{" "}
                                                Aç
                                            </a>
                                            <button
                                                className="btn-ghost btn-sm"
                                                onClick={() =>
                                                    void patchTable(table, {
                                                        active: !table.active,
                                                    })
                                                }
                                                type="button"
                                            >
                                                {table.active
                                                    ? "QR kapat"
                                                    : "QR aç"}
                                            </button>
                                            <button
                                                className="btn-ghost btn-sm"
                                                onClick={() =>
                                                    void patchTable(table, {
                                                        rotateQr: true,
                                                    })
                                                }
                                                type="button"
                                            >
                                                Yenile
                                            </button>
                                        </div>
                                    </div>
                                </article>
                            ))}
                        </div>
                        {tables.length === 0 && (
                            <div className="card-flat mt-4 p-10 text-center text-sm text-muted">
                                Bu şubede masa yok. İlk masanı ekle, QR kodunu
                                yazdır.
                            </div>
                        )}
                    </section>
                )}

                {tab === "branches" && (
                    <section className="mt-5">
                        <div>
                            <p className="eyebrow">Çok şubeli yönetim</p>
                            <h1 className="mt-1 font-display text-2xl font-semibold">
                                Şubeler
                            </h1>
                        </div>
                        <form
                            className="card mt-4 flex flex-col gap-2 p-4 sm:flex-row"
                            onSubmit={(event) => void submitBranch(event)}
                        >
                            <input
                                className="input flex-1"
                                onChange={(event) =>
                                    setBranchName(event.target.value)
                                }
                                placeholder="Yeni şube adı"
                                value={branchName}
                            />
                            <button
                                className="btn-primary"
                                disabled={busy || !branchName.trim()}
                                type="submit"
                            >
                                <Plus className="size-4" /> Şube ekle
                            </button>
                        </form>
                        <div className="mt-4 grid gap-3 md:grid-cols-2">
                            {overview?.branches.map((branch) => (
                                <article
                                    className="card-flat flex items-center gap-4 p-4"
                                    key={branch.id}
                                >
                                    <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-sage text-forest">
                                        <Store className="size-5" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <h2 className="truncate font-display text-lg font-semibold">
                                            {branch.name}
                                        </h2>
                                        <p className="text-xs text-muted">
                                            {branch.activeTables} aktif masa ·{" "}
                                            {branch.openTables} açık adisyon
                                        </p>
                                        <p className="mt-0.5 text-xs text-muted">
                                            {branch.usesSharedMenu
                                                ? "Ortak menü"
                                                : "Şubeye özel menü"}
                                        </p>
                                    </div>
                                    <button
                                        className="btn-secondary btn-sm"
                                        onClick={() => {
                                            setBranchId(branch.id);
                                            setTab("menu");
                                        }}
                                        type="button"
                                    >
                                        Yönet{" "}
                                        <ArrowRight className="size-3.5" />
                                    </button>
                                </article>
                            ))}
                        </div>
                    </section>
                )}

                {tab === "settings" && (
                    <section className="mt-5 max-w-3xl">
                        <div>
                            <p className="eyebrow">Kafe tercihleri</p>
                            <h1 className="mt-1 font-display text-2xl font-semibold">
                                Müşteri ve sadakat
                            </h1>
                        </div>
                        <article className="card mt-4 p-5">
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                <div>
                                    <p className="font-semibold">
                                        Müşteri hesabı
                                    </p>
                                    <p className="mt-1 text-sm text-muted">
                                        Sipariş öncesi hesap kuralını seç.
                                    </p>
                                </div>
                                <select
                                    aria-label="Müşteri kayıt politikası"
                                    className="input max-w-sm"
                                    onChange={(event) =>
                                        void patch(
                                            "/api/manage/settings",
                                            {
                                                registrationPolicy:
                                                    event.target.value,
                                            },
                                            "Müşteri kayıt ayarı güncellendi.",
                                        )
                                    }
                                    value={
                                        overview?.cafe.registrationPolicy ??
                                        "optional"
                                    }
                                >
                                    <option value="guest">
                                        Kayıtsız sipariş
                                    </option>
                                    <option value="optional">
                                        Hesap isteğe bağlı
                                    </option>
                                    <option value="required">
                                        Hesap zorunlu
                                    </option>
                                </select>
                            </div>
                        </article>
                        <article className="card mt-3 flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p className="font-semibold">
                                    Sadakat puanları
                                </p>
                                <p className="mt-1 text-sm text-muted">
                                    Hesaplı müşteri, her ₺100 harcamada
                                    belirlediğin puanı kazanır.
                                </p>
                            </div>
                            <label className="inline-flex shrink-0 items-center gap-2 text-sm font-semibold">
                                <input
                                    checked={
                                        overview?.cafe.loyaltyEnabled ?? false
                                    }
                                    onChange={(event) =>
                                        void patch(
                                            "/api/manage/settings",
                                            {
                                                loyaltyEnabled:
                                                    event.target.checked,
                                            },
                                            event.target.checked
                                                ? "Sadakat programı açıldı."
                                                : "Sadakat programı kapatıldı.",
                                        )
                                    }
                                    type="checkbox"
                                />{" "}
                                Program açık
                            </label>
                        </article>
                        <form
                            className="card mt-3 flex flex-col gap-3 p-5 sm:flex-row sm:items-end"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const pointsPer100 = Number(
                                    new FormData(event.currentTarget).get(
                                        "pointsPer100",
                                    ),
                                );
                                void patch(
                                    "/api/manage/settings",
                                    { pointsPer100 },
                                    "Puan kazanma kuralı kaydedildi.",
                                );
                            }}
                        >
                            <label className="flex-1">
                                <span className="label">
                                    Her ₺100 için kazanılan puan
                                </span>
                                <input
                                    className="input max-w-xs"
                                    key={overview?.cafe.pointsPer100}
                                    defaultValue={
                                        overview?.cafe.pointsPer100 ?? 1
                                    }
                                    min="0"
                                    max="100000"
                                    name="pointsPer100"
                                    required
                                    type="number"
                                />
                            </label>
                            <button
                                className="btn-primary"
                                disabled={busy}
                                type="submit"
                            >
                                Puan kuralını kaydet
                            </button>
                        </form>
                        <section className="card mt-5 p-4 sm:p-5">
                            <div>
                                <p className="eyebrow">Promosyonlar</p>
                                <h2 className="mt-1 font-display text-xl font-semibold">
                                    Sadakat ödülü oluştur
                                </h2>
                                <p className="mt-1 text-sm text-muted">
                                    Ödüller sadece ödenmiş siparişler üzerinden
                                    kazanılır. Müşteriler QR menüde ve
                                    hesaplarında görür.
                                </p>
                            </div>
                            <form
                                className="mt-4 grid gap-3 sm:grid-cols-2"
                                onSubmit={(event) =>
                                    void saveLoyaltyCampaign(event)
                                }
                            >
                                <label className="sm:col-span-2">
                                    <span className="label">Ödül adı</span>
                                    <input
                                        className="input"
                                        maxLength={120}
                                        minLength={2}
                                        name="name"
                                        placeholder="Örn. 10 siparişe kahve ikramı"
                                        required
                                    />
                                </label>
                                <label>
                                    <span className="label">
                                        Kazanma koşulu
                                    </span>
                                    <select
                                        className="input"
                                        name="type"
                                        onChange={(event) =>
                                            setCampaignType(
                                                event.target
                                                    .value as LoyaltyCampaign["type"],
                                            )
                                        }
                                        value={campaignType}
                                    >
                                        <option value="order_count">
                                            Tamamlanan sipariş
                                        </option>
                                        <option value="points">
                                            Puan harcama
                                        </option>
                                    </select>
                                </label>
                                {campaignType === "order_count" ? (
                                    <label>
                                        <span className="label">
                                            Gerekli sipariş sayısı
                                        </span>
                                        <input
                                            className="input"
                                            max="10000"
                                            min="1"
                                            name="threshold"
                                            required
                                            type="number"
                                        />
                                    </label>
                                ) : (
                                    <label>
                                        <span className="label">
                                            Gerekli puan
                                        </span>
                                        <input
                                            className="input"
                                            max="1000000"
                                            min="1"
                                            name="pointsRequired"
                                            required
                                            type="number"
                                        />
                                    </label>
                                )}
                                <label>
                                    <span className="label">İndirim türü</span>
                                    <select
                                        className="input"
                                        name="rewardType"
                                        onChange={(event) =>
                                            setCampaignRewardType(
                                                event.target
                                                    .value as LoyaltyCampaign["rewardType"],
                                            )
                                        }
                                        value={campaignRewardType}
                                    >
                                        <option value="fixed">
                                            Sabit tutar
                                        </option>
                                        <option value="percent">Yüzde</option>
                                    </select>
                                </label>
                                <label>
                                    <span className="label">
                                        {campaignRewardType === "fixed"
                                            ? "İndirim tutarı (₺)"
                                            : "İndirim oranı (%)"}
                                    </span>
                                    <input
                                        className="input"
                                        inputMode="decimal"
                                        min="0.01"
                                        max={
                                            campaignRewardType === "fixed"
                                                ? "1000000"
                                                : "100"
                                        }
                                        name="rewardValue"
                                        placeholder={
                                            campaignRewardType === "fixed"
                                                ? "50,00"
                                                : "10"
                                        }
                                        required
                                        step="0.01"
                                        type="number"
                                    />
                                </label>
                                <label>
                                    <span className="label">
                                        Minimum sepet (₺)
                                    </span>
                                    <input
                                        className="input"
                                        defaultValue="0"
                                        inputMode="decimal"
                                        min="0"
                                        name="minimumOrder"
                                        required
                                        step="0.01"
                                        type="number"
                                    />
                                </label>
                                <div className="flex items-end">
                                    <button
                                        className="btn-primary w-full sm:w-auto"
                                        disabled={
                                            busy ||
                                            !overview?.cafe.loyaltyEnabled
                                        }
                                        type="submit"
                                    >
                                        <Plus className="size-4" /> Ödül ekle
                                    </button>
                                </div>
                                {!overview?.cafe.loyaltyEnabled && (
                                    <p className="rounded-xl bg-amber-soft p-3 text-sm text-ink sm:col-span-2">
                                        Önce sadakat programını açın.
                                    </p>
                                )}
                            </form>
                            <div className="mt-4 space-y-2">
                                {campaigns.length === 0 ? (
                                    <p className="rounded-xl bg-cream p-4 text-sm text-muted">
                                        Henüz sadakat ödülü yok.
                                    </p>
                                ) : (
                                    campaigns.map((campaign) => (
                                        <article
                                            className="flex flex-wrap items-center gap-3 rounded-2xl border border-line p-3"
                                            key={campaign.id}
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="font-semibold">
                                                    {campaign.name}{" "}
                                                    <span
                                                        className={`ml-1 badge ${campaign.active ? "badge-paid" : "badge-cancelled"}`}
                                                    >
                                                        {campaign.active
                                                            ? "Yayında"
                                                            : "Pasif"}
                                                    </span>
                                                </p>
                                                <p className="mt-1 text-xs text-muted">
                                                    {campaign.type ===
                                                    "order_count"
                                                        ? `${campaign.threshold} tamamlanmış sipariş`
                                                        : `${campaign.pointsRequired} puan`}{" "}
                                                    ·{" "}
                                                    {campaign.rewardType ===
                                                    "fixed"
                                                        ? formatCurrency(
                                                              campaign.rewardCents,
                                                          )
                                                        : `%${(campaign.rewardBasisPoints / 100).toLocaleString("tr-TR")}`}{" "}
                                                    indirim{" "}
                                                    {campaign.minimumOrderCents >
                                                    0
                                                        ? `· min. ${formatCurrency(campaign.minimumOrderCents)}`
                                                        : ""}
                                                </p>
                                            </div>
                                            <button
                                                className="btn-secondary btn-sm"
                                                disabled={busy}
                                                onClick={() =>
                                                    void changeCampaignActive(
                                                        campaign,
                                                    )
                                                }
                                                type="button"
                                            >
                                                {campaign.active
                                                    ? "Pasife al"
                                                    : "Yayına al"}
                                            </button>
                                        </article>
                                    ))
                                )}
                            </div>
                        </section>
                        <section className="card mt-5 p-4 sm:p-5">
                            <div className="flex items-end justify-between gap-3">
                                <div>
                                    <p className="eyebrow">Hesap erişimi</p>
                                    <h2 className="mt-1 font-display text-xl font-semibold">
                                        Çalışanlar
                                    </h2>
                                </div>
                                <button
                                    className="btn-primary btn-sm"
                                    onClick={() => setStaffEditor("new")}
                                    type="button"
                                >
                                    <Plus className="size-4" /> Çalışan ekle
                                </button>
                            </div>
                            <div className="mt-3 space-y-2">
                                {staff.length === 0 ? (
                                    <p className="rounded-2xl bg-cream p-4 text-sm text-muted">
                                        Henüz çalışan eklenmemiş.
                                    </p>
                                ) : (
                                    staff.map((person) => (
                                        <article
                                            className="flex flex-wrap items-center gap-3 rounded-2xl border border-line p-3"
                                            key={person.id}
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-bold">
                                                    {person.name}{" "}
                                                    <span
                                                        className={`ml-1 badge ${person.active ? "badge-paid" : "badge-cancelled"}`}
                                                    >
                                                        {person.active
                                                            ? "Aktif"
                                                            : "Kapalı"}
                                                    </span>
                                                </p>
                                                <p className="truncate text-xs text-muted">
                                                    {person.email} ·{" "}
                                                    {person.role === "waiter"
                                                        ? "Garson"
                                                        : person.role ===
                                                            "kitchen"
                                                          ? "Mutfak"
                                                          : "Şube yöneticisi"}
                                                </p>
                                                <p className="mt-0.5 truncate text-xs text-muted">
                                                    {person.role ===
                                                    "cafe_manager"
                                                        ? "Tüm şubeler"
                                                        : person.branches
                                                              .map(
                                                                  (branch) =>
                                                                      branch.name,
                                                              )
                                                              .join(", ")}
                                                </p>
                                            </div>
                                            <div className="flex gap-1">
                                                <button
                                                    className="btn-secondary btn-sm"
                                                    onClick={() =>
                                                        setStaffEditor(person)
                                                    }
                                                    type="button"
                                                >
                                                    Düzenle
                                                </button>
                                                <button
                                                    className="btn-ghost btn-sm"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        void resetStaffPassword(
                                                            person,
                                                        )
                                                    }
                                                    type="button"
                                                >
                                                    Şifre
                                                </button>
                                                <button
                                                    className="btn-ghost btn-sm"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        void changeStaffActive(
                                                            person,
                                                        )
                                                    }
                                                    type="button"
                                                >
                                                    {person.active
                                                        ? "Kapat"
                                                        : "Aç"}
                                                </button>
                                            </div>
                                        </article>
                                    ))
                                )}
                            </div>
                        </section>
                    </section>
                )}
            </div>
            {productEditor !== null && menu && (
                <ProductEditor
                    key={productEditor === "new" ? "new" : productEditor.id}
                    categories={menu.categories.filter(
                        (category) => category.isActive,
                    )}
                    product={productEditor === "new" ? null : productEditor}
                    busy={busy}
                    onUpload={uploadProductImage}
                    onClose={() => setProductEditor(null)}
                    onSave={(values) =>
                        void saveProduct(
                            values,
                            productEditor === "new" ? null : productEditor,
                        )
                    }
                />
            )}
            {staffEditor !== null && (
                <StaffEditor
                    key={staffEditor === "new" ? "new" : staffEditor.id}
                    branches={
                        overview?.branches.filter((branch) => branch.active) ??
                        []
                    }
                    person={staffEditor === "new" ? null : staffEditor}
                    busy={busy}
                    onClose={() => setStaffEditor(null)}
                    onSave={(values) =>
                        void saveStaff(
                            values,
                            staffEditor === "new" ? null : staffEditor,
                        )
                    }
                />
            )}
            {staffCredential && (
                <CredentialDialog
                    title={staffCredential.name}
                    email={staffCredential.email}
                    password={staffCredential.password}
                    onClose={() => setStaffCredential(null)}
                />
            )}
            {switchDialog && (
                <MenuModeDialog
                    current={menu?.menuMode ?? "shared"}
                    branches={overview?.branches ?? []}
                    branchId={branchId ?? 0}
                    sourceBranchId={sourceBranchId}
                    onSourceChange={setSourceBranchId}
                    onClose={() => setSwitchDialog(false)}
                    onSelect={(mode) => void switchMenu(mode)}
                    busy={busy}
                />
            )}
        </main>
    );
}

function StaffEditor({
    branches,
    person,
    busy,
    onClose,
    onSave,
}: {
    branches: Branch[];
    person: StaffMember | null;
    busy: boolean;
    onClose: () => void;
    onSave: (values: StaffForm) => void;
}) {
    const [name, setName] = useState(person?.name ?? "");
    const [email, setEmail] = useState(person?.email ?? "");
    const [role, setRole] = useState<StaffMember["role"]>(
        person?.role ?? "waiter",
    );
    const [branchIds, setBranchIds] = useState<number[]>(
        person?.branches.map((branch) => branch.id) ?? [],
    );

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        onSave({
            name: name.trim(),
            email: email.trim(),
            role,
            branchIds: role === "cafe_manager" ? [] : branchIds,
        });
    }

    return (
        <div
            className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-ink/45 p-4"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
        >
            <section
                aria-labelledby="staff-editor-title"
                aria-modal="true"
                className="card my-auto w-full max-w-lg p-5 sm:p-6"
                role="dialog"
            >
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <p className="eyebrow">Hesap erişimi</p>
                        <h2
                            className="mt-1 font-display text-2xl font-semibold"
                            id="staff-editor-title"
                        >
                            {person ? "Çalışanı düzenle" : "Çalışan hesabı aç"}
                        </h2>
                    </div>
                    <button
                        aria-label="Kapat"
                        className="btn-icon size-9"
                        onClick={onClose}
                        type="button"
                    >
                        <X className="size-4" />
                    </button>
                </div>
                <form className="mt-5 space-y-4" onSubmit={submit}>
                    <label className="block">
                        <span className="label">Ad soyad</span>
                        <input
                            autoComplete="name"
                            className="input"
                            maxLength={120}
                            minLength={2}
                            onChange={(event) => setName(event.target.value)}
                            required
                            value={name}
                        />
                    </label>
                    <label className="block">
                        <span className="label">E-posta</span>
                        <input
                            autoComplete="email"
                            className="input"
                            disabled={Boolean(person)}
                            maxLength={254}
                            onChange={(event) => setEmail(event.target.value)}
                            required
                            type="email"
                            value={email}
                        />
                        <span className="mt-1 block text-xs text-muted">
                            {person
                                ? "E-posta adresini değiştirmek için ayrı bir hesap açın."
                                : "Geçici şifre bir kez gösterilecek."}
                        </span>
                    </label>
                    <label className="block">
                        <span className="label">Yetki</span>
                        <select
                            className="input"
                            onChange={(event) =>
                                setRole(
                                    event.target.value as StaffMember["role"],
                                )
                            }
                            value={role}
                        >
                            <option value="waiter">Garson</option>
                            <option value="kitchen">Mutfak</option>
                            <option value="cafe_manager">
                                Şube yöneticisi
                            </option>
                        </select>
                    </label>
                    {role === "cafe_manager" ? (
                        <p className="rounded-xl bg-sage/60 p-3 text-sm text-forest">
                            Şube yöneticisi bu kafenin tüm şubelerine
                            erişebilir.
                        </p>
                    ) : (
                        <fieldset className="rounded-2xl border border-line p-3">
                            <legend className="px-1 text-sm font-semibold">
                                Yetkili şubeler
                            </legend>
                            {branches.length ? (
                                <div className="mt-1 grid gap-2 sm:grid-cols-2">
                                    {branches.map((branch) => (
                                        <label
                                            className="flex items-center gap-2 rounded-xl bg-cream px-3 py-2 text-sm"
                                            key={branch.id}
                                        >
                                            <input
                                                checked={branchIds.includes(
                                                    branch.id,
                                                )}
                                                onChange={(event) =>
                                                    setBranchIds((current) =>
                                                        event.target.checked
                                                            ? [
                                                                  ...current,
                                                                  branch.id,
                                                              ]
                                                            : current.filter(
                                                                  (id) =>
                                                                      id !==
                                                                      branch.id,
                                                              ),
                                                    )
                                                }
                                                type="checkbox"
                                            />
                                            {branch.name}
                                        </label>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-sm text-muted">
                                    Önce aktif bir şube oluşturun.
                                </p>
                            )}
                        </fieldset>
                    )}
                    <div className="flex justify-end gap-2 pt-1">
                        <button
                            className="btn-secondary"
                            onClick={onClose}
                            type="button"
                        >
                            Vazgeç
                        </button>
                        <button
                            className="btn-primary"
                            disabled={
                                busy ||
                                (!branches.length && role !== "cafe_manager")
                            }
                            type="submit"
                        >
                            {busy
                                ? "Kaydediliyor…"
                                : person
                                  ? "Kaydet"
                                  : "Hesap oluştur"}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    );
}

function CredentialDialog({
    title,
    email,
    password,
    onClose,
}: {
    title: string;
    email: string;
    password: string;
    onClose: () => void;
}) {
    const [copied, setCopied] = useState(false);
    return (
        <div className="fixed inset-0 z-[60] grid place-items-center bg-ink/45 p-4">
            <section
                aria-labelledby="credential-title"
                aria-modal="true"
                className="card w-full max-w-md p-5 sm:p-6"
                role="dialog"
            >
                <p className="eyebrow">Geçici giriş bilgisi</p>
                <h2
                    className="mt-1 font-display text-2xl font-semibold"
                    id="credential-title"
                >
                    {title}
                </h2>
                <p className="mt-2 text-sm text-muted">
                    Bu şifre yalnızca şimdi gösterilir. Çalışandan ilk girişte
                    değiştirmesini isteyin.
                </p>
                <dl className="mt-5 space-y-3 rounded-2xl bg-cream p-4">
                    <div>
                        <dt className="text-xs font-semibold text-muted">
                            E-posta
                        </dt>
                        <dd className="mt-1 break-all font-semibold">
                            {email}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs font-semibold text-muted">
                            Geçici şifre
                        </dt>
                        <dd className="mt-1 select-all break-all font-mono font-semibold">
                            {password}
                        </dd>
                    </div>
                </dl>
                <div className="mt-4 grid grid-cols-2 gap-2">
                    <button
                        className="btn-secondary"
                        onClick={() =>
                            void navigator.clipboard
                                .writeText(
                                    `E-posta: ${email}\nGeçici şifre: ${password}`,
                                )
                                .then(() => setCopied(true))
                                .catch(() => setCopied(false))
                        }
                        type="button"
                    >
                        <Copy className="size-4" />
                        {copied ? "Kopyalandı" : "Kopyala"}
                    </button>
                    <button
                        className="btn-primary"
                        onClick={onClose}
                        type="button"
                    >
                        Tamam
                    </button>
                </div>
            </section>
        </div>
    );
}

function Metric({ label, value }: { label: string; value: string | number }) {
    return (
        <div className="kpi p-3 sm:p-4">
            <p className="text-[10px] font-bold uppercase tracking-[.12em] text-muted sm:text-xs">
                {label}
            </p>
            <p className="mt-1 truncate font-display text-xl font-semibold sm:text-2xl">
                {value}
            </p>
        </div>
    );
}
function Loading() {
    return (
        <main className="grid min-h-dvh place-items-center">
            <LoaderCircle className="size-7 animate-spin text-forest" />
        </main>
    );
}

function QrReveal({
    name,
    url,
    onClose,
}: {
    name: string;
    url: string;
    onClose: () => void;
}) {
    const full = `${window.location.origin}${url}`;
    return (
        <div
            className="fixed inset-0 z-50 grid place-items-center bg-ink/45 p-4"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
        >
            <section
                aria-modal="true"
                className="card w-full max-w-sm p-6 text-center"
                role="dialog"
            >
                <button
                    aria-label="Kapat"
                    className="btn-icon absolute right-5 top-5 size-9"
                    onClick={onClose}
                    type="button"
                >
                    <X className="size-4" />
                </button>
                <p className="eyebrow">Yeni masa QR kodu</p>
                <h2 className="mt-1 font-display text-2xl font-semibold">
                    {name}
                </h2>
                <QRCodeSVG
                    className="mx-auto my-5 size-52 bg-white p-3"
                    value={full}
                    size={208}
                    level="H"
                    includeMargin
                />
                <p className="break-all text-xs text-muted">{full}</p>
                <div className="mt-4 grid grid-cols-2 gap-2">
                    <button
                        className="btn-secondary"
                        onClick={() =>
                            void navigator.clipboard
                                .writeText(full)
                                .then(() => onClose())
                        }
                        type="button"
                    >
                        <Copy className="size-4" /> Bağlantıyı kopyala
                    </button>
                    <button
                        className="btn-primary"
                        onClick={() => {
                            window.print();
                            onClose();
                        }}
                        type="button"
                    >
                        <Printer className="size-4" /> Yazdır
                    </button>
                </div>
            </section>
        </div>
    );
}

function ProductEditor({
    categories,
    product,
    busy,
    onUpload,
    onClose,
    onSave,
}: {
    categories: Category[];
    product: Product | null;
    busy: boolean;
    onUpload: (file: File) => Promise<string>;
    onClose: () => void;
    onSave: (values: ProductForm) => void;
}) {
    const [form, setForm] = useState<ProductForm>(() => {
        if (!product)
            return { ...emptyProduct(), categoryId: categories[0]?.id ?? 0 };
        return {
            categoryId: product.categoryId,
            name: product.name,
            description: product.description,
            imageUrl: product.imageUrl,
            price: toDecimal(product.priceCents),
            prepMinutes: product.prepMinutes,
            isAvailable: product.isAvailable,
            isFeatured: product.isFeatured,
            optionGroups: product.optionGroups.map((group) => ({
                id: group.id,
                name: group.name,
                selectionMode: group.selectionMode,
                minSelections: group.minSelections,
                maxSelections:
                    group.maxSelections === null
                        ? ""
                        : String(group.maxSelections),
                options: group.options.map((option) => ({
                    name: option.name,
                    price: toDecimal(option.priceDeltaCents),
                })),
            })),
        };
    });
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState("");
    const set = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => setForm((current) => ({ ...current, [key]: value }));
    function updateGroup(index: number, changes: Partial<OptionGroupEditor>) {
        set(
            "optionGroups",
            form.optionGroups.map((group, position) =>
                position === index ? { ...group, ...changes } : group,
            ),
        );
    }
    function updateOption(
        groupIndex: number,
        optionIndex: number,
        changes: Partial<OptionEditor>,
    ) {
        set(
            "optionGroups",
            form.optionGroups.map((group, index) =>
                index === groupIndex
                    ? {
                          ...group,
                          options: group.options.map(
                              (option, optionPosition) =>
                                  optionPosition === optionIndex
                                      ? { ...option, ...changes }
                                      : option,
                          ),
                      }
                    : group,
            ),
        );
    }
    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        onSave(form);
    }
    async function uploadImage(event: React.ChangeEvent<HTMLInputElement>) {
        const file = event.target.files?.[0];
        event.target.value = "";
        if (!file) return;
        setUploading(true);
        setUploadError("");
        try {
            set("imageUrl", await onUpload(file));
        } catch (reason) {
            setUploadError(messageOf(reason));
        } finally {
            setUploading(false);
        }
    }
    return (
        <div
            className="fixed inset-0 z-40 flex items-end justify-center bg-ink/40 p-0 sm:items-center sm:p-4"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
        >
            <section
                aria-modal="true"
                className="max-h-[94dvh] w-full max-w-2xl overflow-y-auto rounded-t-[2rem] bg-cream p-4 shadow-lift sm:rounded-[2rem] sm:p-6"
                role="dialog"
            >
                <div className="flex items-start justify-between">
                    <div>
                        <p className="eyebrow">Menü düzenleyici</p>
                        <h2 className="mt-1 font-display text-2xl font-semibold">
                            {product ? "Ürünü düzenle" : "Yeni ürün"}
                        </h2>
                    </div>
                    <button
                        aria-label="Kapat"
                        className="btn-icon size-9"
                        onClick={onClose}
                        type="button"
                    >
                        <X className="size-4" />
                    </button>
                </div>
                <form className="mt-4 space-y-4" onSubmit={submit}>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label>
                            <span className="label">Ürün adı</span>
                            <input
                                className="input"
                                onChange={(event) =>
                                    set("name", event.target.value)
                                }
                                required
                                value={form.name}
                            />
                        </label>
                        <label>
                            <span className="label">Kategori</span>
                            <select
                                className="input"
                                onChange={(event) =>
                                    set(
                                        "categoryId",
                                        Number(event.target.value),
                                    )
                                }
                                required
                                value={form.categoryId}
                            >
                                {categories.map((category) => (
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    <label className="block">
                        <span className="label">Açıklama</span>
                        <textarea
                            className="input min-h-20 resize-y"
                            onChange={(event) =>
                                set("description", event.target.value)
                            }
                            value={form.description}
                        />
                    </label>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label>
                            <span className="label">Fiyat (₺)</span>
                            <input
                                className="input"
                                inputMode="decimal"
                                onChange={(event) =>
                                    set("price", event.target.value)
                                }
                                required
                                value={form.price}
                            />
                        </label>
                        <label>
                            <span className="label">Hazırlık dk</span>
                            <input
                                className="input"
                                min="0"
                                onChange={(event) =>
                                    set(
                                        "prepMinutes",
                                        Number(event.target.value),
                                    )
                                }
                                type="number"
                                value={form.prepMinutes}
                            />
                        </label>
                        <label>
                            <span className="label">Fotoğraf URL</span>
                            <input
                                className="input"
                                onChange={(event) =>
                                    set("imageUrl", event.target.value)
                                }
                                placeholder="https://..."
                                type="text"
                                value={form.imageUrl}
                            />
                        </label>
                        <label>
                            <span className="label">
                                Cihazından görsel yükle
                            </span>
                            <input
                                accept="image/jpeg,image/png,image/webp"
                                className="input py-2.5 file:mr-3 file:rounded-full file:border-0 file:bg-sage file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-forest"
                                disabled={uploading}
                                onChange={(event) => void uploadImage(event)}
                                type="file"
                            />
                            <span className="mt-1 block text-[11px] text-muted">
                                JPG, PNG veya WebP · en fazla 4 MB
                            </span>
                            {uploadError && (
                                <span className="mt-1 block text-xs text-danger">
                                    {uploadError}
                                </span>
                            )}
                        </label>
                    </div>
                    <div className="flex flex-wrap gap-4 text-sm">
                        <label className="inline-flex items-center gap-2">
                            <input
                                checked={form.isAvailable}
                                onChange={(event) =>
                                    set("isAvailable", event.target.checked)
                                }
                                type="checkbox"
                            />{" "}
                            Satışta
                        </label>
                        <label className="inline-flex items-center gap-2">
                            <input
                                checked={form.isFeatured}
                                onChange={(event) =>
                                    set("isFeatured", event.target.checked)
                                }
                                type="checkbox"
                            />{" "}
                            Popüler bölümünde göster
                        </label>
                    </div>
                    <div className="border-t border-line pt-3">
                        <div className="flex items-center justify-between">
                            <div>
                                <h3 className="font-display text-lg font-semibold">
                                    Ürün seçenekleri
                                </h3>
                                <p className="text-xs text-muted">
                                    Ücretsiz ya da fiyat farkı olan seçenekler
                                    ekle.
                                </p>
                            </div>
                            <button
                                className="btn-secondary btn-sm"
                                onClick={() =>
                                    set("optionGroups", [
                                        ...form.optionGroups,
                                        {
                                            name: "",
                                            selectionMode: "single",
                                            minSelections: 0,
                                            maxSelections: "1",
                                            options: [
                                                { name: "", price: "0,00" },
                                            ],
                                        },
                                    ])
                                }
                                type="button"
                            >
                                <Plus className="size-3.5" /> Grup ekle
                            </button>
                        </div>
                        {form.optionGroups.map((group, groupIndex) => (
                            <fieldset
                                className="mt-3 rounded-2xl border border-line bg-paper p-3"
                                key={group.id ?? groupIndex}
                            >
                                <div className="grid gap-2 sm:grid-cols-[1fr_auto_auto]">
                                    <input
                                        aria-label="Seçenek grubu adı"
                                        className="input py-2.5"
                                        onChange={(event) =>
                                            updateGroup(groupIndex, {
                                                name: event.target.value,
                                            })
                                        }
                                        placeholder="Örn. Süt seçimi"
                                        required
                                        value={group.name}
                                    />
                                    <select
                                        aria-label="Seçim türü"
                                        className="input py-2.5"
                                        onChange={(event) =>
                                            updateGroup(groupIndex, {
                                                selectionMode: event.target
                                                    .value as
                                                    "single" | "multiple",
                                                maxSelections:
                                                    event.target.value ===
                                                    "single"
                                                        ? "1"
                                                        : group.maxSelections,
                                            })
                                        }
                                        value={group.selectionMode}
                                    >
                                        <option value="single">
                                            Tek seçim
                                        </option>
                                        <option value="multiple">
                                            Çoklu seçim
                                        </option>
                                    </select>
                                    <button
                                        aria-label="Grubu kaldır"
                                        className="btn-icon size-10"
                                        onClick={() =>
                                            set(
                                                "optionGroups",
                                                form.optionGroups.filter(
                                                    (_, index) =>
                                                        index !== groupIndex,
                                                ),
                                            )
                                        }
                                        type="button"
                                    >
                                        <Trash2 className="size-4 text-danger" />
                                    </button>
                                </div>
                                <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                                    <label className="inline-flex items-center gap-2">
                                        Zorunlu seçim
                                        <input
                                            className="input w-20 py-2"
                                            min="0"
                                            max="20"
                                            onChange={(event) =>
                                                updateGroup(groupIndex, {
                                                    minSelections: Number(
                                                        event.target.value,
                                                    ),
                                                })
                                            }
                                            type="number"
                                            value={group.minSelections}
                                        />
                                    </label>
                                    {group.selectionMode === "multiple" && (
                                        <label className="inline-flex items-center gap-2">
                                            En fazla
                                            <input
                                                className="input w-20 py-2"
                                                min="1"
                                                max="20"
                                                onChange={(event) =>
                                                    updateGroup(groupIndex, {
                                                        maxSelections:
                                                            event.target.value,
                                                    })
                                                }
                                                type="number"
                                                value={group.maxSelections}
                                            />
                                        </label>
                                    )}
                                </div>
                                {group.options.map((option, optionIndex) => (
                                    <div
                                        className="mt-2 grid grid-cols-[1fr_110px_40px] gap-2"
                                        key={optionIndex}
                                    >
                                        <input
                                            aria-label="Seçenek adı"
                                            className="input py-2.5"
                                            onChange={(event) =>
                                                updateOption(
                                                    groupIndex,
                                                    optionIndex,
                                                    {
                                                        name: event.target
                                                            .value,
                                                    },
                                                )
                                            }
                                            placeholder="Karamelli"
                                            required
                                            value={option.name}
                                        />
                                        <input
                                            aria-label="Fiyat farkı"
                                            className="input py-2.5"
                                            inputMode="decimal"
                                            onChange={(event) =>
                                                updateOption(
                                                    groupIndex,
                                                    optionIndex,
                                                    {
                                                        price: event.target
                                                            .value,
                                                    },
                                                )
                                            }
                                            value={option.price}
                                        />
                                        <button
                                            aria-label="Seçeneği kaldır"
                                            className="btn-icon size-10"
                                            disabled={
                                                group.options.length === 1
                                            }
                                            onClick={() =>
                                                updateGroup(groupIndex, {
                                                    options:
                                                        group.options.filter(
                                                            (_, index) =>
                                                                index !==
                                                                optionIndex,
                                                        ),
                                                })
                                            }
                                            type="button"
                                        >
                                            <X className="size-4" />
                                        </button>
                                    </div>
                                ))}
                                <button
                                    className="btn-ghost btn-sm mt-2"
                                    onClick={() =>
                                        updateGroup(groupIndex, {
                                            options: [
                                                ...group.options,
                                                { name: "", price: "0,00" },
                                            ],
                                        })
                                    }
                                    type="button"
                                >
                                    <Plus className="size-3.5" /> Seçenek ekle
                                </button>
                            </fieldset>
                        ))}
                    </div>
                    <div className="grid grid-cols-2 gap-2 border-t border-line pt-4">
                        <button
                            className="btn-secondary"
                            onClick={onClose}
                            type="button"
                        >
                            Vazgeç
                        </button>
                        <button
                            className="btn-primary"
                            disabled={busy}
                            type="submit"
                        >
                            {busy ? (
                                <LoaderCircle className="size-4 animate-spin" />
                            ) : (
                                <BadgeCheck className="size-4" />
                            )}
                            {product ? "Değişiklikleri kaydet" : "Ürünü ekle"}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    );
}

function MenuModeDialog({
    current,
    branches,
    branchId,
    sourceBranchId,
    onSourceChange,
    onClose,
    onSelect,
    busy,
}: {
    current: "shared" | "independent";
    branches: Branch[];
    branchId: number;
    sourceBranchId: string;
    onSourceChange: (value: string) => void;
    onClose: () => void;
    onSelect: (mode: "shared" | "independent") => void;
    busy: boolean;
}) {
    return (
        <div
            className="fixed inset-0 z-40 grid place-items-center bg-ink/45 p-4"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) onClose();
            }}
        >
            <section
                aria-modal="true"
                className="card w-full max-w-lg p-5"
                role="dialog"
            >
                <div className="flex items-start justify-between">
                    <div>
                        <p className="eyebrow">Şubeye özel menü</p>
                        <h2 className="mt-1 font-display text-2xl font-semibold">
                            Menüyü nasıl yönetelim?
                        </h2>
                    </div>
                    <button
                        aria-label="Kapat"
                        className="btn-icon size-9"
                        onClick={onClose}
                        type="button"
                    >
                        <X className="size-4" />
                    </button>
                </div>
                <p className="mt-2 text-sm leading-6 text-muted">
                    Ortak menüde ürünleri tüm şubeler paylaşır. Bağımsız menü
                    seçince aktif menüyü kopyalayıp bu şubede ayrı
                    düzenleyebilirsin.
                </p>
                <label className="mt-4 block">
                    <span className="label">Kopyalanacak menü</span>
                    <select
                        className="input"
                        onChange={(event) => onSourceChange(event.target.value)}
                        value={sourceBranchId}
                    >
                        <option value="">Ortak menü</option>
                        {branches
                            .filter((branch) => branch.id !== branchId)
                            .map((branch) => (
                                <option key={branch.id} value={branch.id}>
                                    {branch.name} menüsü
                                </option>
                            ))}
                    </select>
                </label>
                <div className="mt-4 grid gap-2 sm:grid-cols-2">
                    <button
                        className={`btn-secondary ${current === "shared" ? "border-forest text-forest" : ""}`}
                        disabled={busy}
                        onClick={() => onSelect("shared")}
                        type="button"
                    >
                        <Store className="size-4" /> Ortak menüyü kullan
                    </button>
                    <button
                        className="btn-primary"
                        disabled={busy}
                        onClick={() => onSelect("independent")}
                        type="button"
                    >
                        <Copy className="size-4" /> Kopyala ve ayır
                    </button>
                </div>
                <p className="mt-3 text-[11px] leading-5 text-muted">
                    Bağımsız kopya kaynak menüyü değiştirmez. Şube fiyatları ve
                    seçenekleri kopyaya işlenir.
                </p>
            </section>
        </div>
    );
}

export function PlatformPage() {
    const { user, csrfToken, loading, logout } = useAuth();
    const [data, setData] = useState<{
        cafes: DashboardTable[];
        metrics: {
            cafes: number;
            activeCafes: number;
            activeTables: number;
            activeCustomers: number;
        };
    } | null>(null);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");
    const [busy, setBusy] = useState(false);
    const [form, setForm] = useState({
        cafeName: "",
        branchName: "",
        ownerName: "",
        ownerEmail: "",
    });
    const [credential, setCredential] = useState<{
        email: string;
        password: string;
        cafe: string;
    } | null>(null);
    const [editing, setEditing] = useState<DashboardTable | null>(null);
    const refresh = useCallback(async () => {
        try {
            setData(await apiRequest("/api/platform/dashboard"));
            setError("");
        } catch (reason) {
            setError(messageOf(reason));
        }
    }, []);
    useEffect(() => {
        if (user?.role === "platform_admin") void refresh();
    }, [refresh, user]);
    async function createCafe(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setBusy(true);
        setError("");
        try {
            const created = await apiPost<{
                ownerEmail: string;
                temporaryPassword: string;
            }>("/api/platform/cafes", form, csrfToken);
            setCredential({
                cafe: form.cafeName,
                email: created.ownerEmail,
                password: created.temporaryPassword,
            });
            setForm({
                cafeName: "",
                branchName: "",
                ownerName: "",
                ownerEmail: "",
            });
            setNotice(
                "Kafe oluşturuldu. Geçici şifreyi güvenli şekilde sahibine iletin.",
            );
            await refresh();
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    async function changeStatus(cafe: DashboardTable) {
        setBusy(true);
        try {
            await apiPatch(
                `/api/platform/cafes/${cafe.id}`,
                { status: cafe.status === "active" ? "suspended" : "active" },
                csrfToken,
            );
            setNotice(
                cafe.status === "active"
                    ? "Kafe hesabı askıya alındı."
                    : "Kafe hesabı yeniden açıldı.",
            );
            await refresh();
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    async function resetOwner(cafe: DashboardTable) {
        if (
            !cafe.owner ||
            !window.confirm(
                `${cafe.owner.name} hesabının şifresi yenilensin mi? Eski oturumlar kapatılır.`,
            )
        )
            return;
        setBusy(true);
        try {
            const result = await apiPost<{
                ownerEmail: string;
                temporaryPassword: string;
            }>(
                `/api/platform/cafes/${cafe.id}/reset-owner`,
                { ownerId: cafe.owner.id },
                csrfToken,
            );
            setCredential({
                cafe: cafe.name,
                email: result.ownerEmail,
                password: result.temporaryPassword,
            });
            setNotice(
                "Yeni geçici şifre oluşturuldu. Şifre bu ekrandan ayrılınca tekrar gösterilmez.",
            );
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    async function saveCafeName(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!editing) return;
        const target = event.currentTarget;
        const name = new FormData(target).get("name")?.toString().trim() ?? "";
        setBusy(true);
        try {
            await apiPatch(
                `/api/platform/cafes/${editing.id}`,
                { name },
                csrfToken,
            );
            setEditing(null);
            setNotice("Kafe bilgisi güncellendi.");
            await refresh();
        } catch (reason) {
            setError(messageOf(reason));
        } finally {
            setBusy(false);
        }
    }
    if (loading) return <Loading />;
    if (!user) return <Navigate replace to="/giris" />;
    if (user.role !== "platform_admin")
        return <Navigate replace to="/yonetim" />;
    return (
        <main className="min-h-dvh pb-10">
            <header className="sticky top-0 z-20 border-b border-line bg-cream/95 backdrop-blur-xl">
                <div className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-4 py-3 sm:px-7">
                    <div className="flex items-center gap-3">
                        <span className="grid size-10 place-items-center rounded-2xl bg-forest text-amber">
                            <Coffee className="size-5" />
                        </span>
                        <div>
                            <p className="font-display text-lg font-semibold">
                                Süper admin
                            </p>
                            <p className="text-xs text-muted">
                                Kafeler ve platform
                            </p>
                        </div>
                    </div>
                    <div className="flex gap-2">
                        <button
                            className="btn-icon size-10"
                            onClick={() => void refresh()}
                            type="button"
                        >
                            <RefreshCw className="size-4" />
                        </button>
                        <button
                            className="btn-icon size-10"
                            onClick={() => void logout()}
                            type="button"
                        >
                            <LogOut className="size-4" />
                        </button>
                    </div>
                </div>
            </header>
            <div className="mx-auto max-w-7xl px-4 pt-5 sm:px-7">
                {(error || notice) && (
                    <div
                        aria-live="polite"
                        className={`mb-4 rounded-2xl p-3 text-sm font-semibold ${error ? "bg-danger-soft text-danger" : "bg-success-soft text-success"}`}
                    >
                        {error || notice}
                    </div>
                )}
                <section className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Metric
                        label="Toplam kafe"
                        value={data?.metrics.cafes ?? 0}
                    />
                    <Metric
                        label="Aktif kafe"
                        value={data?.metrics.activeCafes ?? 0}
                    />
                    <Metric
                        label="Aktif masa"
                        value={data?.metrics.activeTables ?? 0}
                    />
                    <Metric
                        label="Aktif müşteri"
                        value={data?.metrics.activeCustomers ?? 0}
                    />
                </section>
                <div className="mt-7 grid gap-5 lg:grid-cols-[360px_1fr]">
                    <section className="card h-fit p-4 sm:p-5">
                        <p className="eyebrow">Yeni işletme</p>
                        <h1 className="mt-1 font-display text-2xl font-semibold">
                            Kafe oluştur
                        </h1>
                        <p className="mt-1 text-sm text-muted">
                            İlk şube ve kafe sahibi hesabı birlikte açılır.
                        </p>
                        <form
                            className="mt-4 space-y-3"
                            onSubmit={(event) => void createCafe(event)}
                        >
                            <label className="block">
                                <span className="label">Kafe adı</span>
                                <input
                                    className="input"
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            cafeName: event.target.value,
                                        })
                                    }
                                    required
                                    value={form.cafeName}
                                />
                            </label>
                            <label className="block">
                                <span className="label">İlk şube</span>
                                <input
                                    className="input"
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            branchName: event.target.value,
                                        })
                                    }
                                    required
                                    value={form.branchName}
                                />
                            </label>
                            <label className="block">
                                <span className="label">Sahip adı</span>
                                <input
                                    className="input"
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            ownerName: event.target.value,
                                        })
                                    }
                                    required
                                    value={form.ownerName}
                                />
                            </label>
                            <label className="block">
                                <span className="label">Sahip e-posta</span>
                                <input
                                    className="input"
                                    onChange={(event) =>
                                        setForm({
                                            ...form,
                                            ownerEmail: event.target.value,
                                        })
                                    }
                                    required
                                    type="email"
                                    value={form.ownerEmail}
                                />
                            </label>
                            <button
                                className="btn-primary w-full"
                                disabled={busy}
                                type="submit"
                            >
                                <Plus className="size-4" /> Kafeyi oluştur
                            </button>
                        </form>
                    </section>
                    <section>
                        <div className="mb-3 flex items-end justify-between">
                            <div>
                                <p className="eyebrow">İşletme hesapları</p>
                                <h2 className="mt-1 font-display text-2xl font-semibold">
                                    Kafeler
                                </h2>
                            </div>
                            <span className="badge-neutral">
                                {data?.cafes.length ?? 0} kayıt
                            </span>
                        </div>
                        <div className="space-y-3">
                            {data?.cafes.map((cafe) => (
                                <article
                                    className="card flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:p-5"
                                    key={cafe.id}
                                >
                                    <div className="grid size-12 shrink-0 place-items-center rounded-2xl bg-sage text-forest">
                                        <Store className="size-5" />
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h3 className="font-display text-lg font-semibold">
                                                {cafe.name}
                                            </h3>
                                            <span
                                                className={
                                                    cafe.status === "active"
                                                        ? "badge-paid"
                                                        : "badge-cancelled"
                                                }
                                            >
                                                {cafe.status === "active"
                                                    ? "Aktif"
                                                    : "Askıda"}
                                            </span>
                                        </div>
                                        <p className="mt-0.5 text-xs text-muted">
                                            {cafe.owner?.name ??
                                                "Sahip atanmamış"}{" "}
                                            · {cafe.owner?.email ?? "—"}
                                        </p>
                                        <p className="mt-1 text-xs text-muted">
                                            {cafe.branches} şube ·{" "}
                                            {cafe.activeTables} masa ·{" "}
                                            {cafe.activeCustomers} müşteri
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <button
                                            className="btn-secondary btn-sm"
                                            onClick={() => setEditing(cafe)}
                                            type="button"
                                        >
                                            Düzenle
                                        </button>
                                        <button
                                            className="btn-secondary btn-sm"
                                            disabled={busy || !cafe.owner}
                                            onClick={() =>
                                                void resetOwner(cafe)
                                            }
                                            type="button"
                                        >
                                            Şifre sıfırla
                                        </button>
                                        <button
                                            className={
                                                cafe.status === "active"
                                                    ? "btn-danger btn-sm"
                                                    : "btn-primary btn-sm"
                                            }
                                            disabled={busy}
                                            onClick={() =>
                                                void changeStatus(cafe)
                                            }
                                            type="button"
                                        >
                                            {cafe.status === "active"
                                                ? "Askıya al"
                                                : "Etkinleştir"}
                                        </button>
                                    </div>
                                </article>
                            ))}
                        </div>
                    </section>
                </div>
            </div>
            {credential && (
                <div className="fixed inset-0 z-50 grid place-items-center bg-ink/45 p-4">
                    <section className="card w-full max-w-md p-5">
                        <p className="eyebrow">Geçici giriş bilgisi</p>
                        <h2 className="mt-1 font-display text-2xl font-semibold">
                            {credential.cafe}
                        </h2>
                        <p className="mt-3 text-sm text-muted">
                            Şifre yalnızca burada gösteriliyor. Kopyalayıp
                            işletme sahibine güvenli kanaldan ilet.
                        </p>
                        <div className="mt-4 space-y-2 rounded-2xl bg-cream p-4 font-mono text-sm">
                            <p>E-posta: {credential.email}</p>
                            <p className="break-all">
                                Geçici şifre: {credential.password}
                            </p>
                        </div>
                        <button
                            className="btn-primary mt-4 w-full"
                            onClick={() => setCredential(null)}
                            type="button"
                        >
                            Güvenli şekilde not aldım
                        </button>
                    </section>
                </div>
            )}
            {editing && (
                <div className="fixed inset-0 z-40 grid place-items-center bg-ink/45 p-4">
                    <form
                        className="card w-full max-w-md p-5"
                        onSubmit={(event) => void saveCafeName(event)}
                    >
                        <p className="eyebrow">Kafe düzenle</p>
                        <h2 className="mt-1 font-display text-2xl font-semibold">
                            {editing.name}
                        </h2>
                        <label className="mt-4 block">
                            <span className="label">Kafe adı</span>
                            <input
                                className="input"
                                defaultValue={editing.name}
                                name="name"
                                required
                            />
                        </label>
                        <div className="mt-4 grid grid-cols-2 gap-2">
                            <button
                                className="btn-secondary"
                                onClick={() => setEditing(null)}
                                type="button"
                            >
                                Vazgeç
                            </button>
                            <button
                                className="btn-primary"
                                disabled={busy}
                                type="submit"
                            >
                                Kaydet
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </main>
    );
}
