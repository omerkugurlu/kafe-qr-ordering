import { randomBytes } from "node:crypto";
import { z } from "zod";
import type { Client } from "@libsql/client";
import {
    currentUser,
    getCustomerForToken,
    getTableByToken,
    requireCsrf,
} from "./auth.js";
import { formatCurrency } from "../domain/money.js";
import {
    ApiError,
    checkSameOrigin,
    getDatabase,
    nowIso,
    readJson,
    type ApiEnvironment,
    withTransaction,
} from "./http.js";

const orderSchema = z.object({
    lines: z
        .array(
            z.object({
                productId: z.number().int().positive(),
                quantity: z.number().int().min(1).max(20),
                optionIds: z
                    .array(z.number().int().positive())
                    .max(20)
                    .default([]),
                note: z.string().trim().max(140).optional().default(""),
            }),
        )
        .min(1)
        .max(50),
    customerNote: z.string().trim().max(500).optional().default(""),
    couponCode: z.string().trim().max(40).optional().default(""),
    loyaltyCampaignId: z.number().int().positive().optional(),
});

function parseInput<T>(schema: z.ZodType<T>, value: unknown): T {
    const parsed = schema.safeParse(value);
    if (!parsed.success) {
        throw new ApiError(
            422,
            parsed.error.issues[0]?.message ?? "Bilgileri kontrol edin.",
            "validation_failed",
            parsed.error.flatten(),
        );
    }
    return parsed.data;
}

function parseJson(value: unknown): unknown {
    if (typeof value !== "string") return value;
    try {
        return JSON.parse(value);
    } catch {
        return null;
    }
}

function bool(value: unknown): boolean {
    return Number(value) === 1;
}

async function findOpenSession(database: Client, tableId: number) {
    const result = await database.execute({
        sql: `SELECT id, branch_id, status, qr_ordering_enabled, opened_at, closed_at
              FROM table_sessions WHERE dining_table_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1`,
        args: [tableId],
    });
    return result.rows[0] ?? null;
}

export async function getOrder(database: Client, orderId: number) {
    const result = await database.execute({
        sql: `SELECT id, order_number, source, status, subtotal_cents, discount_total_cents, total_cents,
                     coupon_code, customer_note, cancel_reason, created_at, accepted_at, ready_at,
                     served_at, cancelled_at, paid_at FROM orders WHERE id = ?`,
        args: [orderId],
    });
    const order = result.rows[0];
    if (!order) return null;
    const itemRows = await database.execute({
        sql: `SELECT id, name_snapshot, quantity, unit_price_cents, line_total_cents, customer_note
              FROM order_items WHERE order_id = ? ORDER BY id`,
        args: [orderId],
    });
    const items = [];
    for (const item of itemRows.rows) {
        const options = await database.execute({
            sql: "SELECT group_name_snapshot, name_snapshot, price_delta_cents FROM order_item_options WHERE order_item_id = ? ORDER BY id",
            args: [Number(item.id)],
        });
        items.push({
            id: Number(item.id),
            name: String(item.name_snapshot),
            quantity: Number(item.quantity),
            unitPriceCents: Number(item.unit_price_cents),
            lineTotalCents: Number(item.line_total_cents),
            note:
                item.customer_note === null ? null : String(item.customer_note),
            options: options.rows.map((option) => ({
                group: String(option.group_name_snapshot),
                name: String(option.name_snapshot),
                priceDeltaCents: Number(option.price_delta_cents),
            })),
        });
    }
    return {
        id: Number(order.id),
        number: String(order.order_number),
        source: String(order.source),
        status: String(order.status),
        subtotalCents: Number(order.subtotal_cents),
        discountCents: Number(order.discount_total_cents),
        totalCents: Number(order.total_cents),
        totalLabel: formatCurrency(Number(order.total_cents)),
        couponCode:
            order.coupon_code === null ? null : String(order.coupon_code),
        note: order.customer_note === null ? null : String(order.customer_note),
        cancelReason:
            order.cancel_reason === null ? null : String(order.cancel_reason),
        createdAt: String(order.created_at),
        acceptedAt: order.accepted_at ? String(order.accepted_at) : null,
        readyAt: order.ready_at ? String(order.ready_at) : null,
        servedAt: order.served_at ? String(order.served_at) : null,
        cancelledAt: order.cancelled_at ? String(order.cancelled_at) : null,
        paidAt: order.paid_at ? String(order.paid_at) : null,
        items,
    };
}

export async function sessionSnapshot(
    database: Client,
    session: Record<string, unknown>,
) {
    const sessionId = Number(session.id);
    const ordersResult = await database.execute({
        sql: `SELECT id, order_number, source, status, subtotal_cents, discount_total_cents, total_cents,
                     coupon_code, customer_note, cancel_reason, created_at, accepted_at, ready_at,
                     served_at, cancelled_at, paid_at FROM orders
              WHERE table_session_id = ? AND status <> 'cancelled' ORDER BY created_at, id`,
        args: [sessionId],
    });
    const orders = [];
    for (const row of ordersResult.rows) {
        const order = await getOrder(database, Number(row.id));
        if (order) orders.push(order);
    }
    const paymentsResult = await database.execute({
        sql: `SELECT id, method, amount_cents, person_label, recorded_at, voided_at
              FROM payments WHERE table_session_id = ? ORDER BY recorded_at, id`,
        args: [sessionId],
    });
    const payments = paymentsResult.rows.map((payment) => ({
        id: Number(payment.id),
        method: String(payment.method),
        amountCents: Number(payment.amount_cents),
        personLabel: payment.person_label ? String(payment.person_label) : null,
        recordedAt: String(payment.recorded_at),
        voided: Boolean(payment.voided_at),
    }));
    const totalCents = orders.reduce((sum, order) => sum + order.totalCents, 0);
    const paidCents = payments
        .filter((payment) => !payment.voided)
        .reduce((sum, payment) => sum + payment.amountCents, 0);
    const activeOrders = orders.filter((order) => order.status !== "cancelled");
    return {
        id: sessionId,
        status: String(session.status),
        isOpen: String(session.status) === "open",
        qrOrderingEnabled: bool(session.qr_ordering_enabled),
        openedAt: String(session.opened_at),
        closedAt: session.closed_at ? String(session.closed_at) : null,
        qrOrderPending: activeOrders.some(
            (order) => order.source === "qr" && order.status === "pending",
        ),
        orders: activeOrders,
        ordersCount: activeOrders.length,
        totalCents,
        totalLabel: formatCurrency(totalCents),
        paidCents,
        paidLabel: formatCurrency(paidCents),
        dueCents: Math.max(0, totalCents - paidCents),
        dueLabel: formatCurrency(Math.max(0, totalCents - paidCents)),
        canRequestBill:
            String(session.status) === "open" &&
            activeOrders.some((order) =>
                ["accepted", "ready", "served", "paid"].includes(order.status),
            ),
        payments,
    };
}

async function menuResponse(
    request: Request,
    token: string,
    environment: ApiEnvironment,
): Promise<Response> {
    const database = await getDatabase(environment);
    const table = await getTableByToken(database, token);
    const customer = await currentUser(request, environment);
    const isCustomer =
        customer?.role === "customer" &&
        customer.cafeId === Number(table.cafe_id) &&
        customer;
    if (String(table.registration_policy) === "required" && !isCustomer) {
        return new Response(
            JSON.stringify({
                error: "Bu kafede sipariş vermek için müşteri hesabınızla giriş yapın.",
                code: "customer_login_required",
            }),
            {
                status: 401,
                headers: {
                    "content-type": "application/json; charset=utf-8",
                    "cache-control": "no-store",
                },
            },
        );
    }

    const branchMenuId = bool(table.uses_shared_menu)
        ? null
        : Number(table.branch_id);
    const categoriesResult = await database.execute({
        sql: `SELECT id, name, description, image_path, sort_order FROM menu_categories
              WHERE cafe_id = ? AND ((branch_id IS NULL AND ? IS NULL) OR branch_id = ?) AND is_active = 1
              ORDER BY sort_order, name`,
        args: [Number(table.cafe_id), branchMenuId, branchMenuId],
    });
    const categories = [];
    const products = [];

    for (const category of categoriesResult.rows) {
        const productResult = await database.execute({
            sql: `SELECT p.id, p.name, p.description, p.image_path, p.price_cents, p.prep_minutes,
                         p.is_featured, p.suggestions_json, p.sort_order,
                         COALESCE(bp.is_available, p.is_available) AS effective_available,
                         COALESCE(bp.price_cents, p.price_cents) AS effective_price
                  FROM menu_products p LEFT JOIN branch_product bp ON bp.menu_product_id = p.id AND bp.branch_id = ?
                  WHERE p.cafe_id = ? AND p.category_id = ?
                    AND ((p.branch_id IS NULL AND ? IS NULL) OR p.branch_id = ?)
                    AND COALESCE(bp.is_available, p.is_available) = 1
                  ORDER BY p.sort_order, p.name`,
            args: [
                Number(table.branch_id),
                Number(table.cafe_id),
                Number(category.id),
                branchMenuId,
                branchMenuId,
            ],
        });
        const productIds = productResult.rows.map((product) =>
            Number(product.id),
        );
        categories.push({
            id: Number(category.id),
            name: String(category.name),
            description: category.description
                ? String(category.description)
                : null,
            imageUrl: category.image_path ? String(category.image_path) : null,
            productIds,
        });

        for (const product of productResult.rows) {
            const groupsResult = await database.execute({
                sql: "SELECT id, name, selection_mode, min_selections, max_selections FROM menu_option_groups WHERE menu_product_id = ? AND is_active = 1 ORDER BY sort_order, id",
                args: [Number(product.id)],
            });
            const optionGroups = [];
            for (const group of groupsResult.rows) {
                const optionResult = await database.execute({
                    sql: "SELECT id, name, price_delta_cents FROM menu_options WHERE menu_option_group_id = ? AND is_available = 1 ORDER BY sort_order, id",
                    args: [Number(group.id)],
                });
                optionGroups.push({
                    id: Number(group.id),
                    name: String(group.name),
                    selectionMode: String(group.selection_mode),
                    minSelections: Number(group.min_selections),
                    maxSelections:
                        group.max_selections === null
                            ? null
                            : Number(group.max_selections),
                    options: optionResult.rows.map((option) => ({
                        id: Number(option.id),
                        name: String(option.name),
                        priceDeltaCents: Number(option.price_delta_cents),
                    })),
                });
            }
            products.push({
                id: Number(product.id),
                name: String(product.name),
                description: product.description
                    ? String(product.description)
                    : null,
                imageUrl: product.image_path
                    ? String(product.image_path)
                    : null,
                priceCents: Number(product.effective_price),
                priceLabel: formatCurrency(Number(product.effective_price)),
                prepMinutes: Number(product.prep_minutes),
                isFeatured: bool(product.is_featured),
                categoryId: Number(category.id),
                categoryName: String(category.name),
                optionGroups,
                suggestions:
                    (parseJson(product.suggestions_json) as unknown[] | null)
                        ?.map(Number)
                        .filter((id) => productIds.includes(id)) ?? [],
            });
        }
    }

    const session = await findOpenSession(database, Number(table.table_id));
    const snapshot = session
        ? await sessionSnapshot(database, session as Record<string, unknown>)
        : null;
    const announcements = await database.execute({
        sql: `SELECT id, type, title, body FROM announcements WHERE cafe_id = ? AND is_active = 1
              AND (branch_id IS NULL OR branch_id = ?) AND (starts_at IS NULL OR starts_at <= ?)
              AND (ends_at IS NULL OR ends_at >= ?) ORDER BY created_at DESC LIMIT 6`,
        args: [
            Number(table.cafe_id),
            Number(table.branch_id),
            nowIso(environment),
            nowIso(environment),
        ],
    });

    let loyalty = null;
    if (isCustomer && bool(table.loyalty_enabled)) {
        const accountResult = await database.execute({
            sql: "SELECT points, completed_order_count FROM loyalty_accounts WHERE cafe_id = ? AND user_id = ?",
            args: [Number(table.cafe_id), isCustomer.id],
        });
        const campaigns = await database.execute({
            sql: `SELECT id, name, type, threshold, points_required, reward_type, reward_cents,
                         reward_basis_points, minimum_order_cents FROM loyalty_campaigns
                  WHERE cafe_id = ? AND is_active = 1 AND (starts_at IS NULL OR starts_at <= ?)
                    AND (ends_at IS NULL OR ends_at >= ?) ORDER BY name`,
            args: [
                Number(table.cafe_id),
                nowIso(environment),
                nowIso(environment),
            ],
        });
        const account = accountResult.rows[0];
        loyalty = {
            points: Number(account?.points ?? 0),
            completedOrders: Number(account?.completed_order_count ?? 0),
            campaigns: campaigns.rows.map((campaign) => ({
                id: Number(campaign.id),
                name: String(campaign.name),
                type: String(campaign.type),
                required:
                    String(campaign.type) === "points"
                        ? Number(campaign.points_required)
                        : Number(campaign.threshold),
                rewardType: String(campaign.reward_type),
                rewardCents: Number(campaign.reward_cents),
                rewardBasisPoints: Number(campaign.reward_basis_points),
                minimumOrderCents: Number(campaign.minimum_order_cents),
            })),
        };
    }

    const featured = products
        .filter((product) => product.isFeatured)
        .slice(0, 8);
    const selectedFeatured =
        featured.length > 0 ? featured : products.slice(0, 6);
    return new Response(
        JSON.stringify({
            table: {
                id: Number(table.table_id),
                name: String(table.table_name),
                token,
            },
            cafe: {
                id: Number(table.cafe_id),
                name: String(table.brand_name || table.cafe_name),
                legalName: String(table.cafe_name),
                description: table.cafe_description
                    ? String(table.cafe_description)
                    : null,
                logoUrl: table.logo_path ? String(table.logo_path) : null,
                coverUrl: table.cover_path ? String(table.cover_path) : null,
                address: table.cafe_address ? String(table.cafe_address) : null,
                phone: table.cafe_phone ? String(table.cafe_phone) : null,
                instagram: table.instagram ? String(table.instagram) : null,
                registrationPolicy: String(table.registration_policy),
                loyaltyEnabled: bool(table.loyalty_enabled),
            },
            branch: {
                id: Number(table.branch_id),
                name: String(table.branch_name),
                address: table.branch_address
                    ? String(table.branch_address)
                    : null,
            },
            customer: isCustomer
                ? { id: isCustomer.id, name: isCustomer.name }
                : null,
            categories,
            products,
            featuredIds: selectedFeatured.map((product) => product.id),
            session: snapshot,
            orderingAvailable: Boolean(
                snapshot?.isOpen &&
                snapshot.qrOrderingEnabled &&
                !snapshot.qrOrderPending,
            ),
            loyalty,
            announcements: announcements.rows.map((announcement) => ({
                id: Number(announcement.id),
                type: String(announcement.type),
                title: String(announcement.title),
                body: announcement.body ? String(announcement.body) : null,
            })),
            skyKitePromotion: {
                headline: "Öğrenci misin? SkyKite tam senin için.",
                description: "KKTC’den Türkiye’ye ürün getirtmenin kolay yolu.",
                url: "https://skykite.com.tr/?utm_source=masa_qr&utm_medium=menu&utm_campaign=cross_promotion",
            },
        }),
        {
            headers: {
                "content-type": "application/json; charset=utf-8",
                "cache-control":
                    "private, no-store, max-age=0, must-revalidate",
            },
        },
    );
}

async function getOptionsForLine(
    database: Client,
    productId: number,
    optionIds: number[],
) {
    const groups = await database.execute({
        sql: "SELECT id, name, selection_mode, min_selections, max_selections FROM menu_option_groups WHERE menu_product_id = ? AND is_active = 1 ORDER BY sort_order, id",
        args: [productId],
    });
    const selectedIds = new Set(optionIds);
    if (selectedIds.size !== optionIds.length)
        throw new ApiError(
            422,
            "Aynı ürün seçeneği birden fazla seçilemez.",
            "invalid_options",
        );
    const snapshots = [];
    let priceDeltaCents = 0;
    const allowedIds = new Set<number>();

    for (const group of groups.rows) {
        const options = await database.execute({
            sql: "SELECT id, name, price_delta_cents FROM menu_options WHERE menu_option_group_id = ? AND is_available = 1 ORDER BY sort_order, id",
            args: [Number(group.id)],
        });
        const groupOptions = options.rows.filter((option) =>
            selectedIds.has(Number(option.id)),
        );
        for (const option of options.rows) allowedIds.add(Number(option.id));
        const maximum =
            String(group.selection_mode) === "single"
                ? 1
                : Number(group.max_selections ?? options.rows.length);
        if (
            groupOptions.length < Number(group.min_selections) ||
            groupOptions.length > maximum
        ) {
            throw new ApiError(
                422,
                `${String(group.name)}: seçilen seçenek sayısını kontrol edin.`,
                "invalid_options",
            );
        }
        for (const option of groupOptions) {
            const delta = Number(option.price_delta_cents);
            priceDeltaCents += delta;
            snapshots.push({
                groupName: String(group.name),
                name: String(option.name),
                priceDeltaCents: delta,
                optionId: Number(option.id),
            });
        }
    }
    if ([...selectedIds].some((id) => !allowedIds.has(id))) {
        throw new ApiError(
            422,
            "Menüde bulunmayan bir seçenek gönderildi. Menüyü yenileyip tekrar deneyin.",
            "invalid_options",
        );
    }
    return { snapshots, priceDeltaCents };
}

async function createOrder(
    request: Request,
    token: string,
    environment: ApiEnvironment,
): Promise<Response> {
    const input = parseInput(orderSchema, await readJson(request));
    const database = await getDatabase(environment);
    const table = await getTableByToken(database, token);
    const customer = await getCustomerForToken(
        request,
        environment,
        table as unknown as { cafe_id: number; registration_policy: string },
    );
    const productIds = [...new Set(input.lines.map((line) => line.productId))];
    if (productIds.length > 30)
        throw new ApiError(
            422,
            "Bir siparişte en fazla 30 farklı ürün seçebilirsiniz.",
            "too_many_products",
        );
    if (input.couponCode && input.loyaltyCampaignId)
        throw new ApiError(
            422,
            "Kupon ile sadakat ödülü aynı siparişte kullanılamaz.",
            "discount_conflict",
        );

    const order = await withTransaction(environment, async (transaction) => {
        const currentTable = await transaction.execute({
            sql: `SELECT t.id, t.branch_id, b.cafe_id, b.uses_shared_menu, c.registration_policy, c.loyalty_enabled,
                         c.points_per_100 FROM dining_tables t JOIN branches b ON b.id = t.branch_id JOIN cafes c ON c.id = b.cafe_id
                  WHERE t.id = ? AND t.is_active = 1 AND b.is_active = 1 AND c.status = 'active'`,
            args: [Number(table.table_id)],
        });
        const current = currentTable.rows[0];
        if (!current)
            throw new ApiError(404, "Masa kullanılamıyor.", "table_not_found");
        if (String(current.registration_policy) === "required" && !customer)
            throw new ApiError(
                401,
                "Sipariş vermek için müşteri hesabınızla giriş yapın.",
                "customer_login_required",
            );
        if (customer && customer.role !== "customer")
            throw new ApiError(
                403,
                "Müşteri hesabıyla giriş yapın.",
                "customer_role_required",
            );

        const sessionResult = await transaction.execute({
            sql: `SELECT id, branch_id, status, qr_ordering_enabled FROM table_sessions
                  WHERE dining_table_id = ? AND status = 'open' ORDER BY id DESC LIMIT 1`,
            args: [Number(current.id)],
        });
        const session = sessionResult.rows[0];
        if (!session || !bool(session.qr_ordering_enabled)) {
            throw new ApiError(
                409,
                "Bu masa şu anda QR siparişine açık değil. Garsondan masayı açmasını isteyin.",
                "ordering_closed",
            );
        }
        const pendingResult = await transaction.execute({
            sql: `SELECT 1 FROM orders WHERE table_session_id = ? AND source = 'qr' AND status = 'pending' LIMIT 1`,
            args: [Number(session.id)],
        });
        if (pendingResult.rows.length > 0)
            throw new ApiError(
                409,
                "Bu masa için önceki siparişin garson tarafından alınmasını bekleyin.",
                "order_pending",
            );

        const menuBranchId = bool(current.uses_shared_menu)
            ? null
            : Number(current.branch_id);
        const placeholders = productIds.map(() => "?").join(",");
        const productsResult = await transaction.execute({
            sql: `SELECT p.id, p.name, p.price_cents, p.category_id,
                         COALESCE(bp.price_cents, p.price_cents) AS effective_price,
                         COALESCE(bp.is_available, p.is_available) AS effective_available,
                         c.is_active AS category_active
                  FROM menu_products p JOIN menu_categories c ON c.id = p.category_id
                  LEFT JOIN branch_product bp ON bp.menu_product_id = p.id AND bp.branch_id = ?
                  WHERE p.cafe_id = ? AND ((p.branch_id IS NULL AND ? IS NULL) OR p.branch_id = ?)
                    AND p.id IN (${placeholders})`,
            args: [
                Number(current.branch_id),
                Number(current.cafe_id),
                menuBranchId,
                menuBranchId,
                ...productIds,
            ],
        });
        const products = new Map(
            productsResult.rows.map((product) => [Number(product.id), product]),
        );
        if (
            products.size !== productIds.length ||
            productsResult.rows.some(
                (product) =>
                    !bool(product.effective_available) ||
                    !bool(product.category_active),
            )
        ) {
            throw new ApiError(
                409,
                "Menüdeki ürünlerden biri artık siparişe açık değil. Menüyü yenileyip tekrar deneyin.",
                "product_unavailable",
            );
        }

        const lineSnapshots = [];
        let subtotalCents = 0;
        for (const line of input.lines) {
            const product = products.get(line.productId);
            if (!product)
                throw new ApiError(
                    422,
                    "Sipariş ürünü menüde bulunamadı.",
                    "product_not_found",
                );
            const options = await getOptionsForLine(
                transaction as unknown as Client,
                line.productId,
                line.optionIds,
            );
            const unitPrice =
                Number(product.effective_price) + options.priceDeltaCents;
            const lineTotal = unitPrice * line.quantity;
            subtotalCents += lineTotal;
            lineSnapshots.push({
                productId: line.productId,
                name: String(product.name),
                unitPrice,
                quantity: line.quantity,
                lineTotal,
                note: line.note.trim() || null,
                options: options.snapshots,
            });
        }

        let discountCents = 0;
        let couponId: number | null = null;
        let couponCode: string | null = null;
        let campaignId: number | null = null;
        let campaignRedemption: {
            pointsDelta: number;
            ordersDelta: number;
            description: string;
        } | null = null;
        const now = nowIso(environment);

        if (input.couponCode.trim()) {
            const normalized = input.couponCode.trim().toUpperCase();
            const couponResult = await transaction.execute({
                sql: `SELECT id, code, type, value_cents, value_basis_points, min_subtotal_cents,
                             starts_at, ends_at, usage_limit, used_count FROM coupons
                      WHERE cafe_id = ? AND code = ? COLLATE NOCASE AND is_active = 1`,
                args: [Number(current.cafe_id), normalized],
            });
            const coupon = couponResult.rows[0];
            const active =
                coupon &&
                (!coupon.starts_at || String(coupon.starts_at) <= now) &&
                (!coupon.ends_at || String(coupon.ends_at) >= now) &&
                (coupon.usage_limit === null ||
                    Number(coupon.used_count) < Number(coupon.usage_limit));
            if (!active)
                throw new ApiError(
                    422,
                    "Bu kupon kodu kullanılamıyor.",
                    "coupon_unavailable",
                );
            if (subtotalCents < Number(coupon.min_subtotal_cents))
                throw new ApiError(
                    422,
                    `Kupon için minimum sepet tutarı ${formatCurrency(Number(coupon.min_subtotal_cents))}.`,
                    "coupon_minimum",
                );
            discountCents =
                String(coupon.type) === "percent"
                    ? Math.floor(
                          (subtotalCents * Number(coupon.value_basis_points)) /
                              10_000,
                      )
                    : Number(coupon.value_cents);
            discountCents = Math.max(0, Math.min(subtotalCents, discountCents));
            couponId = Number(coupon.id);
            couponCode = String(coupon.code);
            const updated = await transaction.execute({
                sql: `UPDATE coupons SET used_count = used_count + 1, updated_at = ?
                      WHERE id = ? AND (usage_limit IS NULL OR used_count < usage_limit)`,
                args: [now, couponId],
            });
            if (updated.rowsAffected !== 1)
                throw new ApiError(
                    409,
                    "Kupon kullanım hakkı az önce tükendi.",
                    "coupon_exhausted",
                );
        }

        if (input.loyaltyCampaignId) {
            if (!customer || !bool(current.loyalty_enabled))
                throw new ApiError(
                    422,
                    "Sadakat ödülü kullanmak için müşteri hesabıyla giriş yapın.",
                    "loyalty_unavailable",
                );
            const campaignResult = await transaction.execute({
                sql: `SELECT id, name, type, threshold, points_required, reward_type, reward_cents,
                             reward_basis_points, minimum_order_cents, starts_at, ends_at FROM loyalty_campaigns
                      WHERE id = ? AND cafe_id = ? AND is_active = 1`,
                args: [input.loyaltyCampaignId, Number(current.cafe_id)],
            });
            const campaign = campaignResult.rows[0];
            if (
                !campaign ||
                (campaign.starts_at && String(campaign.starts_at) > now) ||
                (campaign.ends_at && String(campaign.ends_at) < now)
            ) {
                throw new ApiError(
                    422,
                    "Bu sadakat ödülü şu anda kullanılamıyor.",
                    "loyalty_campaign_unavailable",
                );
            }
            if (subtotalCents < Number(campaign.minimum_order_cents))
                throw new ApiError(
                    422,
                    "Sipariş tutarı ödül için gereken minimum tutarın altında.",
                    "loyalty_minimum",
                );
            const accountResult = await transaction.execute({
                sql: "SELECT points, completed_order_count FROM loyalty_accounts WHERE cafe_id = ? AND user_id = ?",
                args: [Number(current.cafe_id), customer.id],
            });
            const account = accountResult.rows[0] ?? {
                points: 0,
                completed_order_count: 0,
            };
            if (String(campaign.type) === "points") {
                const requiredPoints = Number(campaign.points_required);
                if (Number(account.points) < requiredPoints)
                    throw new ApiError(
                        422,
                        "Bu ödül için yeterli puanınız yok.",
                        "not_enough_points",
                    );
                await transaction.execute({
                    sql: "INSERT INTO loyalty_accounts (cafe_id, user_id, points, completed_order_count) VALUES (?, ?, ?, ?) ON CONFLICT(cafe_id,user_id) DO UPDATE SET points = points - excluded.points",
                    args: [
                        Number(current.cafe_id),
                        customer.id,
                        Number(account.points) - requiredPoints,
                        Number(account.completed_order_count),
                    ],
                });
                campaignRedemption = {
                    pointsDelta: -requiredPoints,
                    ordersDelta: 0,
                    description: `Sipariş ödülü kullanıldı: ${String(campaign.name)}`,
                };
            } else {
                const spent = await transaction.execute({
                    sql: `SELECT COALESCE(SUM(CASE WHEN type='reward_redeemed' THEN -orders_delta ELSE orders_delta END),0) AS used
                          FROM loyalty_transactions WHERE cafe_id = ? AND user_id = ? AND campaign_id = ? AND type IN ('reward_redeemed','reward_refunded')`,
                    args: [
                        Number(current.cafe_id),
                        customer.id,
                        Number(campaign.id),
                    ],
                });
                const used = Number(spent.rows[0]?.used ?? 0);
                const threshold = Number(campaign.threshold);
                if (Number(account.completed_order_count) - used < threshold)
                    throw new ApiError(
                        422,
                        "Bu ödül için yeterli tamamlanmış siparişiniz yok.",
                        "not_enough_orders",
                    );
                campaignRedemption = {
                    pointsDelta: 0,
                    ordersDelta: -threshold,
                    description: `Sipariş ödülü kullanıldı: ${String(campaign.name)}`,
                };
            }
            discountCents =
                String(campaign.reward_type) === "percent"
                    ? Math.floor(
                          (subtotalCents *
                              Number(campaign.reward_basis_points)) /
                              10_000,
                      )
                    : Number(campaign.reward_cents);
            discountCents = Math.max(0, Math.min(subtotalCents, discountCents));
            campaignId = Number(campaign.id);
        }

        const orderNumber = randomBytes(5).toString("hex").toUpperCase();
        const inserted = await transaction.execute({
            sql: `INSERT INTO orders (cafe_id, branch_id, table_session_id, customer_id, loyalty_campaign_id,
                    coupon_id, coupon_code, order_number, source, status, subtotal_cents, discount_total_cents,
                    total_cents, customer_note, created_at, updated_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'qr', 'pending', ?, ?, ?, ?, ?, ?) RETURNING id`,
            args: [
                Number(current.cafe_id),
                Number(current.branch_id),
                Number(session.id),
                customer?.id ?? null,
                campaignId,
                couponId,
                couponCode,
                orderNumber,
                subtotalCents,
                discountCents,
                Math.max(0, subtotalCents - discountCents),
                input.customerNote || null,
                now,
                now,
            ],
        });
        const orderId = Number(inserted.rows[0]?.id);
        for (const line of lineSnapshots) {
            const itemResult = await transaction.execute({
                sql: `INSERT INTO order_items (order_id, menu_product_id, name_snapshot, unit_price_cents, quantity,
                        line_total_cents, customer_note, created_at, updated_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id`,
                args: [
                    orderId,
                    line.productId,
                    line.name,
                    line.unitPrice,
                    line.quantity,
                    line.lineTotal,
                    line.note,
                    now,
                    now,
                ],
            });
            const itemId = Number(itemResult.rows[0]?.id);
            for (const option of line.options) {
                await transaction.execute({
                    sql: `INSERT INTO order_item_options (order_item_id, menu_option_id, group_name_snapshot,
                            name_snapshot, price_delta_cents, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)`,
                    args: [
                        itemId,
                        option.optionId,
                        option.groupName,
                        option.name,
                        option.priceDeltaCents,
                        now,
                        now,
                    ],
                });
            }
        }
        if (campaignRedemption && customer && campaignId) {
            await transaction.execute({
                sql: `INSERT INTO loyalty_transactions (cafe_id, user_id, campaign_id, order_id, type,
                        points_delta, orders_delta, discount_amount_cents, description, created_at)
                      VALUES (?, ?, ?, ?, 'reward_redeemed', ?, ?, ?, ?, ?)`,
                args: [
                    Number(current.cafe_id),
                    customer.id,
                    campaignId,
                    orderId,
                    campaignRedemption.pointsDelta,
                    campaignRedemption.ordersDelta,
                    discountCents,
                    campaignRedemption.description,
                    now,
                ],
            });
        }
        await transaction.execute({
            sql: `INSERT INTO activity_logs (cafe_id, branch_id, user_id, action, subject_type, subject_id, ip_address, created_at)
                  VALUES (?, ?, ?, 'order.created', 'Order', ?, ?, ?)`,
            args: [
                Number(current.cafe_id),
                Number(current.branch_id),
                customer?.id ?? null,
                orderId,
                environment.context?.ip ?? null,
                now,
            ],
        });
        return orderId;
    });

    const orderPayload = await getOrder(database, order);
    return new Response(
        JSON.stringify({
            order: orderPayload,
            message: "Siparişin kafeye iletildi.",
        }),
        {
            status: 201,
            headers: {
                "content-type": "application/json; charset=utf-8",
                "cache-control": "no-store",
            },
        },
    );
}

async function validateCoupon(
    request: Request,
    token: string,
    environment: ApiEnvironment,
): Promise<Response> {
    const schema = z.object({
        code: z.string().trim().min(1).max(40),
        subtotalCents: z.number().int().nonnegative(),
    });
    const input = parseInput(schema, await readJson(request));
    const database = await getDatabase(environment);
    const table = await getTableByToken(database, token);
    const result = await database.execute({
        sql: `SELECT type, value_cents, value_basis_points, min_subtotal_cents, starts_at, ends_at, usage_limit, used_count
              FROM coupons WHERE cafe_id = ? AND code = ? COLLATE NOCASE AND is_active = 1`,
        args: [Number(table.cafe_id), input.code.trim().toUpperCase()],
    });
    const coupon = result.rows[0];
    const now = nowIso(environment);
    if (
        !coupon ||
        (coupon.starts_at && String(coupon.starts_at) > now) ||
        (coupon.ends_at && String(coupon.ends_at) < now) ||
        (coupon.usage_limit !== null &&
            Number(coupon.used_count) >= Number(coupon.usage_limit))
    ) {
        throw new ApiError(
            422,
            "Bu kupon kodu kullanılamıyor.",
            "coupon_unavailable",
        );
    }
    if (input.subtotalCents < Number(coupon.min_subtotal_cents))
        throw new ApiError(
            422,
            `Minimum sepet tutarı ${formatCurrency(Number(coupon.min_subtotal_cents))}.`,
            "coupon_minimum",
        );
    const discountCents =
        String(coupon.type) === "percent"
            ? Math.floor(
                  (input.subtotalCents * Number(coupon.value_basis_points)) /
                      10_000,
              )
            : Number(coupon.value_cents);
    return new Response(
        JSON.stringify({
            valid: true,
            discountCents: Math.min(input.subtotalCents, discountCents),
            message: "Kupon uygulandı.",
        }),
        {
            headers: {
                "content-type": "application/json; charset=utf-8",
                "cache-control": "no-store",
            },
        },
    );
}

async function customerStatus(
    database: Client,
    token: string,
): Promise<Response> {
    const table = await getTableByToken(database, token);
    const session = await findOpenSession(database, Number(table.table_id));
    return new Response(
        JSON.stringify({
            table: String(table.table_name),
            session: session
                ? await sessionSnapshot(
                      database,
                      session as Record<string, unknown>,
                  )
                : null,
        }),
        {
            headers: {
                "content-type": "application/json; charset=utf-8",
                "cache-control": "no-store",
            },
        },
    );
}

async function customerBill(
    database: Client,
    token: string,
): Promise<Response> {
    const table = await getTableByToken(database, token);
    const session = await findOpenSession(database, Number(table.table_id));
    return new Response(
        JSON.stringify({
            table: String(table.table_name),
            session: session
                ? await sessionSnapshot(
                      database,
                      session as Record<string, unknown>,
                  )
                : null,
        }),
        {
            headers: {
                "content-type": "application/json; charset=utf-8",
                "cache-control": "no-store",
            },
        },
    );
}

async function customerAccount(
    request: Request,
    token: string,
    environment: ApiEnvironment,
): Promise<Response> {
    const database = await getDatabase(environment);
    const table = await getTableByToken(database, token);
    const customer = await currentUser(request, environment);
    if (
        !customer ||
        customer.role !== "customer" ||
        customer.cafeId !== Number(table.cafe_id)
    ) {
        throw new ApiError(
            401,
            "Hesabını görmek için müşteri hesabınızla giriş yapın.",
            "customer_login_required",
        );
    }
    const [accountResult, ordersResult, transactionResult] = await Promise.all([
        database.execute({
            sql: "SELECT points, completed_order_count FROM loyalty_accounts WHERE cafe_id = ? AND user_id = ?",
            args: [Number(table.cafe_id), customer.id],
        }),
        database.execute({
            sql: `SELECT id FROM orders WHERE cafe_id = ? AND customer_id = ? ORDER BY created_at DESC LIMIT 50`,
            args: [Number(table.cafe_id), customer.id],
        }),
        database.execute({
            sql: `SELECT type, points_delta, discount_amount_cents, description, created_at FROM loyalty_transactions WHERE cafe_id = ? AND user_id = ? ORDER BY created_at DESC LIMIT 100`,
            args: [Number(table.cafe_id), customer.id],
        }),
    ]);
    const account = accountResult.rows[0];
    const orders = [];
    for (const row of ordersResult.rows) {
        const order = await getOrder(database, Number(row.id));
        if (order) orders.push(order);
    }
    const savedCents = transactionResult.rows.reduce(
        (sum, transaction) => sum + Number(transaction.discount_amount_cents),
        0,
    );
    return new Response(
        JSON.stringify({
            customer: {
                id: customer.id,
                name: customer.name,
                email: customer.email,
            },
            loyalty: {
                points: Number(account?.points ?? 0),
                completedOrders: Number(account?.completed_order_count ?? 0),
                savedCents,
            },
            orders,
            rewards: transactionResult.rows.map((transaction) => ({
                type: String(transaction.type),
                pointsDelta: Number(transaction.points_delta),
                discountCents: Number(transaction.discount_amount_cents),
                description: String(transaction.description),
                createdAt: String(transaction.created_at),
            })),
        }),
        {
            headers: {
                "content-type": "application/json; charset=utf-8",
                "cache-control": "no-store",
            },
        },
    );
}

async function createServiceRequest(
    request: Request,
    token: string,
    environment: ApiEnvironment,
): Promise<Response> {
    const input = parseInput(
        z.object({
            type: z.enum(["waiter", "bill"]),
            note: z.string().trim().max(160).optional().default(""),
        }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const table = await getTableByToken(database, token);
    const session = await findOpenSession(database, Number(table.table_id));
    if (!session)
        throw new ApiError(
            409,
            "Açık masa oturumu bulunamadı.",
            "session_not_open",
        );
    if (input.type === "bill") {
        const snapshot = await sessionSnapshot(
            database,
            session as Record<string, unknown>,
        );
        if (!snapshot.canRequestBill)
            throw new ApiError(
                409,
                "Hesap isteği için teslim edilmiş bir sipariş bulunmalı.",
                "bill_not_ready",
            );
    }
    const now = nowIso(environment);
    const active = await database.execute({
        sql: `SELECT id FROM service_requests WHERE table_session_id = ? AND type = ? AND status IN ('open','claimed') LIMIT 1`,
        args: [Number(session.id), input.type],
    });
    if (active.rows.length > 0)
        throw new ApiError(
            409,
            "Bu istek garson ekranında zaten görünüyor.",
            "request_already_open",
        );
    const inserted = await database.execute({
        sql: `INSERT INTO service_requests (branch_id, dining_table_id, table_session_id, type, status, note, created_at, updated_at)
              VALUES (?, ?, ?, ?, 'open', ?, ?, ?) RETURNING id`,
        args: [
            Number(table.branch_id),
            Number(table.table_id),
            Number(session.id),
            input.type,
            input.note || null,
            now,
            now,
        ],
    });
    return new Response(
        JSON.stringify({
            ok: true,
            id: Number(inserted.rows[0]?.id),
            message:
                input.type === "bill"
                    ? "Hesap isteğin garsona iletildi."
                    : "Garson çağrın iletildi.",
        }),
        {
            status: 201,
            headers: {
                "content-type": "application/json; charset=utf-8",
                "cache-control": "no-store",
            },
        },
    );
}

async function trackOrder(
    database: Client,
    token: string,
    orderNumber: string,
): Promise<Response> {
    const table = await getTableByToken(database, token);
    const result = await database.execute({
        sql: `SELECT o.id FROM orders o JOIN table_sessions s ON s.id = o.table_session_id
              WHERE s.dining_table_id = ? AND o.order_number = ? LIMIT 1`,
        args: [Number(table.table_id), orderNumber.toUpperCase()],
    });
    const row = result.rows[0];
    if (!row) throw new ApiError(404, "Sipariş bulunamadı.", "order_not_found");
    return new Response(
        JSON.stringify({ order: await getOrder(database, Number(row.id)) }),
        {
            headers: {
                "content-type": "application/json; charset=utf-8",
                "cache-control": "no-store",
            },
        },
    );
}

export async function handleCustomerRequest(
    request: Request,
    path: string,
    environment: ApiEnvironment,
): Promise<Response | null> {
    const match = /^\/api\/tables\/([^/]+)(?:\/(.*))?$/.exec(path);
    if (!match) return null;
    const token = decodeURIComponent(match[1] ?? "");
    const action = match[2] ?? "";
    const method = request.method.toUpperCase();
    const database = await getDatabase(environment);

    if (method === "GET" && action === "menu")
        return menuResponse(request, token, environment);
    if (method === "POST") {
        checkSameOrigin(request);
        const user = await currentUser(request, environment);
        if (user) requireCsrf(request, user);
    }
    if (method === "POST" && action === "orders")
        return createOrder(request, token, environment);
    if (method === "POST" && action === "coupons/check")
        return validateCoupon(request, token, environment);
    if (method === "GET" && action === "status")
        return customerStatus(database, token);
    if (method === "GET" && action === "bill")
        return customerBill(database, token);
    if (method === "GET" && action === "account")
        return customerAccount(request, token, environment);
    if (method === "POST" && action === "service-requests")
        return createServiceRequest(request, token, environment);
    const trackingMatch = /^orders\/([A-Za-z0-9]+)$/.exec(action);
    if (method === "GET" && trackingMatch)
        return trackOrder(database, token, trackingMatch[1] ?? "");
    return null;
}
