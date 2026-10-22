import { randomBytes } from "node:crypto";
import type { Client, Transaction } from "@libsql/client";
import { z } from "zod";
import { formatCurrency } from "../domain/money.js";
import {
    currentUser,
    requireBranchAccess,
    requireCsrf,
    requireRole,
} from "./auth.js";
import { getOrder, sessionSnapshot } from "./customer.js";
import {
    ApiError,
    checkSameOrigin,
    getDatabase,
    nowIso,
    readJson,
    type ApiEnvironment,
    type AuthenticatedUser,
    withTransaction,
} from "./http.js";

const idSchema = z.coerce.number().int().positive();

function parseInput<T>(schema: z.ZodType<T>, value: unknown): T {
    const parsed = schema.safeParse(value);
    if (!parsed.success)
        throw new ApiError(
            422,
            parsed.error.issues[0]?.message ?? "Bilgileri kontrol edin.",
            "validation_failed",
            parsed.error.flatten(),
        );
    return parsed.data;
}

async function findSession(database: Client, sessionId: number) {
    const result = await database.execute({
        sql: `SELECT s.id, s.branch_id, s.dining_table_id, s.status, s.qr_ordering_enabled, s.opened_at,
                     s.closed_at, s.opened_by, s.closed_by, s.closing_note, b.cafe_id, b.name AS branch_name,
                     t.name AS table_name, c.name AS cafe_name
              FROM table_sessions s JOIN branches b ON b.id = s.branch_id
              JOIN dining_tables t ON t.id = s.dining_table_id JOIN cafes c ON c.id = b.cafe_id
              WHERE s.id = ?`,
        args: [sessionId],
    });
    const session = result.rows[0];
    if (!session)
        throw new ApiError(404, "Adisyon bulunamadı.", "session_not_found");
    return session;
}

async function getScopedSession(
    database: Client,
    user: AuthenticatedUser,
    sessionId: number,
    environment: ApiEnvironment,
) {
    const session = await findSession(database, sessionId);
    await requireBranchAccess(user, Number(session.branch_id), environment);
    return session;
}

function serializeRequest(row: Record<string, unknown>, userId: number) {
    return {
        id: Number(row.id),
        table: String(row.table_name),
        branchId: Number(row.branch_id),
        type: String(row.type),
        typeLabel:
            String(row.type) === "bill" ? "Hesap istiyor" : "Garson çağırıyor",
        note: row.note ? String(row.note) : null,
        createdAt: String(row.created_at),
        claimedBy: row.claimed_by === null ? null : Number(row.claimed_by),
        claimedByName: row.claimed_by_name ? String(row.claimed_by_name) : null,
        isClaimedByMe: Number(row.claimed_by) === userId,
    };
}

async function board(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
): Promise<Response> {
    requireRole(user, [
        "platform_admin",
        "cafe_owner",
        "cafe_manager",
        "waiter",
        "kitchen",
    ]);
    const database = await getDatabase(environment);
    const requested = new URL(request.url).searchParams.get("branchId");
    let branchId: number | null = requested ? idSchema.parse(requested) : null;
    if (branchId !== null) {
        await requireBranchAccess(user, branchId, environment);
    } else {
        const branchResult =
            user.role === "platform_admin"
                ? await database.execute({
                      sql: "SELECT id FROM branches WHERE is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1",
                  })
                : user.role === "cafe_owner" || user.role === "cafe_manager"
                  ? await database.execute({
                        sql: "SELECT id FROM branches WHERE cafe_id = ? AND is_active = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1",
                        args: [user.cafeId],
                    })
                  : await database.execute({
                        sql: "SELECT b.id FROM branches b JOIN branch_user bu ON bu.branch_id = b.id WHERE bu.user_id = ? AND b.is_active = 1 AND b.deleted_at IS NULL ORDER BY b.id LIMIT 1",
                        args: [user.id],
                    });
        if (!branchResult.rows[0])
            return new Response(
                JSON.stringify({
                    branch: null,
                    orders: [],
                    tables: [],
                    requests: [],
                    message: "Bu hesap için aktif şube atanmamış.",
                }),
                {
                    headers: {
                        "content-type": "application/json; charset=utf-8",
                    },
                },
            );
        branchId = Number(branchResult.rows[0].id);
    }

    const branchResult = await database.execute({
        sql: "SELECT id, cafe_id, name, address FROM branches WHERE id = ?",
        args: [branchId],
    });
    const branch = branchResult.rows[0];
    if (!branch)
        throw new ApiError(404, "Şube bulunamadı.", "branch_not_found");
    const orderRows = await database.execute({
        sql: `SELECT id, table_session_id FROM orders WHERE branch_id = ? AND status IN ('pending','accepted','ready') ORDER BY created_at, id LIMIT 200`,
        args: [branchId],
    });
    const orders = [];
    for (const row of orderRows.rows) {
        const order = await getOrder(database, Number(row.id));
        const session = await findSession(
            database,
            Number(row.table_session_id),
        );
        if (order)
            orders.push({
                ...order,
                tableName: String(session.table_name),
                sessionId: Number(session.id),
            });
    }

    const tableRows = await database.execute({
        sql: `SELECT t.id, t.name, s.id AS session_id, s.status AS session_status, s.qr_ordering_enabled, s.opened_at
              FROM dining_tables t LEFT JOIN table_sessions s ON s.id = (
                SELECT MAX(s2.id) FROM table_sessions s2 WHERE s2.dining_table_id = t.id
              ) WHERE t.branch_id = ? AND t.is_active = 1 AND t.deleted_at IS NULL ORDER BY t.name`,
        args: [branchId],
    });
    const tables = [];
    for (const row of tableRows.rows) {
        const sessionId =
            row.session_id === null ? null : Number(row.session_id);
        const isOpen = String(row.session_status ?? "") === "open";
        let sessionInfo = null;
        if (isOpen && sessionId) {
            const session = await findSession(database, sessionId);
            sessionInfo = await sessionSnapshot(
                database,
                session as unknown as Record<string, unknown>,
            );
        }
        const lastOrders = sessionId
            ? await database.execute({
                  sql: "SELECT COUNT(*) AS count FROM orders WHERE table_session_id = ? AND status <> 'cancelled'",
                  args: [sessionId],
              })
            : { rows: [] };
        tables.push({
            id: Number(row.id),
            name: String(row.name),
            isOpen,
            sessionId,
            sessionStatus: row.session_status
                ? String(row.session_status)
                : null,
            qrOrderingEnabled: isOpen && Number(row.qr_ordering_enabled) === 1,
            openedAt: row.opened_at ? String(row.opened_at) : null,
            dueCents: sessionInfo?.dueCents ?? 0,
            dueLabel: sessionInfo?.dueLabel ?? formatCurrency(0),
            activeOrders: Number(lastOrders.rows[0]?.count ?? 0),
        });
    }

    const requestRows = await database.execute({
        sql: `SELECT r.id, r.branch_id, r.type, r.note, r.created_at, r.claimed_by,
                     u.name AS claimed_by_name, t.name AS table_name FROM service_requests r
              JOIN dining_tables t ON t.id = r.dining_table_id LEFT JOIN users u ON u.id = r.claimed_by
              WHERE r.branch_id = ? AND r.status = 'open' ORDER BY r.created_at LIMIT 100`,
        args: [branchId],
    });
    const requests = requestRows.rows.map((row) =>
        serializeRequest(row as Record<string, unknown>, user.id),
    );
    return new Response(
        JSON.stringify({
            generatedAt: nowIso(environment),
            branch: {
                id: Number(branch.id),
                name: String(branch.name),
                address: branch.address ? String(branch.address) : null,
            },
            summary: {
                pending: orders.filter((order) => order.status === "pending")
                    .length,
                preparing: orders.filter((order) => order.status === "accepted")
                    .length,
                ready: orders.filter((order) => order.status === "ready")
                    .length,
                openTables: tables.filter((table) => table.isOpen).length,
                requests: requests.length,
            },
            orders,
            tables,
            requests,
            canOpenTables: [
                "platform_admin",
                "cafe_owner",
                "cafe_manager",
                "waiter",
            ].includes(user.role),
            canCollectPayments: [
                "platform_admin",
                "cafe_owner",
                "cafe_manager",
                "waiter",
            ].includes(user.role),
        }),
        {
            headers: {
                "content-type": "application/json; charset=utf-8",
                "cache-control": "no-store",
            },
        },
    );
}

async function updateOrderStatus(
    request: Request,
    user: AuthenticatedUser,
    orderId: number,
    environment: ApiEnvironment,
): Promise<Response> {
    requireRole(user, [
        "platform_admin",
        "cafe_owner",
        "cafe_manager",
        "waiter",
        "kitchen",
    ]);
    const input = parseInput(
        z.object({
            status: z.enum(["accepted", "ready", "served", "cancelled"]),
            reason: z.string().trim().max(160).optional().default(""),
        }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const orderResult = await database.execute({
        sql: "SELECT id, cafe_id, branch_id, table_session_id, customer_id, loyalty_campaign_id, status FROM orders WHERE id = ?",
        args: [orderId],
    });
    const order = orderResult.rows[0];
    if (!order)
        throw new ApiError(404, "Sipariş bulunamadı.", "order_not_found");
    await requireBranchAccess(user, Number(order.branch_id), environment);
    const now = nowIso(environment);
    const result = await withTransaction(environment, async (transaction) => {
        const locked = await transaction.execute({
            sql: "SELECT status FROM orders WHERE id = ? AND table_session_id = ?",
            args: [orderId, Number(order.table_session_id)],
        });
        const status = String(locked.rows[0]?.status ?? "");
        if (status === input.status) return { idempotent: true };
        const allowed: Record<string, string[]> = {
            accepted: ["pending"],
            ready: ["pending", "accepted"],
            served: ["accepted", "ready"],
            cancelled: ["pending", "accepted", "ready"],
        };
        if (!allowed[input.status]?.includes(status))
            throw new ApiError(
                409,
                "Sipariş durumu değişti. Panoyu yenileyip tekrar deneyin.",
                "invalid_status_transition",
            );
        if (input.status === "cancelled" && input.reason.length < 3)
            throw new ApiError(
                422,
                "İptal gerekçesi en az 3 karakter olmalıdır.",
                "cancel_reason_required",
            );

        if (input.status === "cancelled") {
            const payment = await transaction.execute({
                sql: `SELECT 1 FROM payments p WHERE p.table_session_id = ? AND p.voided_at IS NULL
                      AND (p.order_item_id IN (SELECT id FROM order_items WHERE order_id = ?) OR p.order_item_id IS NULL) LIMIT 1`,
                args: [Number(order.table_session_id), orderId],
            });
            if (payment.rows.length)
                throw new ApiError(
                    409,
                    "Bu sipariş için ödeme kaydı var. Önce ilgili ödemeyi iptal edin.",
                    "order_has_payment",
                );
            if (order.customer_id && order.loyalty_campaign_id)
                await refundReward(
                    transaction,
                    Number(order.cafe_id),
                    Number(order.customer_id),
                    Number(order.loyalty_campaign_id),
                    orderId,
                    user.id,
                    now,
                );
            await transaction.execute({
                sql: `UPDATE orders SET status='cancelled', cancel_reason=?, cancelled_at=?, updated_at=? WHERE id=? AND status=?`,
                args: [input.reason, now, now, orderId, status],
            });
        } else {
            const timestampColumn =
                input.status === "accepted"
                    ? "accepted_at"
                    : input.status === "ready"
                      ? "ready_at"
                      : "served_at";
            const changed = await transaction.execute({
                sql: `UPDATE orders SET status=?, ${timestampColumn}=?, updated_at=? WHERE id=? AND status=?`,
                args: [input.status, now, now, orderId, status],
            });
            if (changed.rowsAffected !== 1)
                throw new ApiError(
                    409,
                    "Sipariş başka bir çalışan tarafından güncellendi.",
                    "order_changed",
                );
            if (input.status === "served")
                await settleSession(
                    transaction,
                    Number(order.table_session_id),
                    user.id,
                    now,
                );
        }
        await transaction.execute({
            sql: `INSERT INTO activity_logs (cafe_id, branch_id, user_id, action, subject_type, subject_id, metadata_json, ip_address, created_at)
                  VALUES (?, ?, ?, ?, 'Order', ?, ?, ?, ?)`,
            args: [
                Number(order.cafe_id),
                Number(order.branch_id),
                user.id,
                `order.${input.status}`,
                orderId,
                input.status === "cancelled"
                    ? JSON.stringify({ reason: input.reason })
                    : null,
                environment.context?.ip ?? null,
                now,
            ],
        });
        return { idempotent: false };
    });
    return new Response(
        JSON.stringify({
            ok: true,
            ...result,
            message: result.idempotent
                ? "Sipariş durumu zaten güncel."
                : "Sipariş durumu güncellendi.",
        }),
        { headers: { "content-type": "application/json; charset=utf-8" } },
    );
}

async function refundReward(
    transaction: Transaction,
    cafeId: number,
    customerId: number,
    campaignId: number,
    orderId: number,
    actorId: number,
    now: string,
) {
    const redemptionResult = await transaction.execute({
        sql: `SELECT points_delta, orders_delta, discount_amount_cents FROM loyalty_transactions WHERE order_id = ? AND type = 'reward_redeemed' LIMIT 1`,
        args: [orderId],
    });
    const redemption = redemptionResult.rows[0];
    if (!redemption) return;
    if (Number(redemption.points_delta) < 0) {
        await transaction.execute({
            sql: "UPDATE loyalty_accounts SET points = points + ?, updated_at = ? WHERE cafe_id = ? AND user_id = ?",
            args: [
                Math.abs(Number(redemption.points_delta)),
                now,
                cafeId,
                customerId,
            ],
        });
    }
    await transaction.execute({
        sql: `INSERT INTO loyalty_transactions (cafe_id, user_id, campaign_id, order_id, actor_id, type, points_delta, orders_delta, discount_amount_cents, description, created_at)
              VALUES (?, ?, ?, ?, ?, 'reward_refunded', ?, ?, ?, 'Sipariş ödülü iade edildi', ?)`,
        args: [
            cafeId,
            customerId,
            campaignId,
            orderId,
            actorId,
            Math.abs(Number(redemption.points_delta)),
            Math.abs(Number(redemption.orders_delta)),
            Number(redemption.discount_amount_cents),
            now,
        ],
    });
}

async function payLoyaltyForOrder(
    transaction: Transaction,
    order: Record<string, unknown>,
    now: string,
) {
    if (!order.customer_id) return;
    const cafeResult = await transaction.execute({
        sql: "SELECT loyalty_enabled, points_per_100 FROM cafes WHERE id = ?",
        args: [Number(order.cafe_id)],
    });
    const cafe = cafeResult.rows[0];
    if (!cafe || Number(cafe.loyalty_enabled) !== 1) return;
    const points =
        Math.floor(Number(order.total_cents) / 10_000) *
        Number(cafe.points_per_100);
    await transaction.execute({
        sql: `INSERT INTO loyalty_accounts (cafe_id, user_id, points, completed_order_count, created_at, updated_at)
              VALUES (?, ?, ?, 1, ?, ?)
              ON CONFLICT(cafe_id,user_id) DO UPDATE SET points = points + excluded.points, completed_order_count = completed_order_count + 1, updated_at = excluded.updated_at`,
        args: [
            Number(order.cafe_id),
            Number(order.customer_id),
            points,
            now,
            now,
        ],
    });
    await transaction.execute({
        sql: `INSERT INTO loyalty_transactions (cafe_id,user_id,order_id,type,points_delta,orders_delta,discount_amount_cents,description,created_at)
              VALUES (?, ?, ?, 'earned', ?, 1, 0, 'Ödenmiş siparişten puan kazanıldı', ?)`,
        args: [
            Number(order.cafe_id),
            Number(order.customer_id),
            Number(order.id),
            points,
            now,
        ],
    });
}

async function settleSession(
    transaction: Transaction,
    sessionId: number,
    actorId: number,
    now: string,
): Promise<boolean> {
    const totals = await transaction.execute({
        sql: `SELECT COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_cents ELSE 0 END),0) AS total
              FROM orders WHERE table_session_id = ?`,
        args: [sessionId],
    });
    const paid = await transaction.execute({
        sql: "SELECT COALESCE(SUM(amount_cents),0) AS paid FROM payments WHERE table_session_id = ? AND voided_at IS NULL",
        args: [sessionId],
    });
    if (Number(paid.rows[0]?.paid ?? 0) < Number(totals.rows[0]?.total ?? 0))
        return false;

    const pendingOrders = await transaction.execute({
        sql: `SELECT id, cafe_id, customer_id, total_cents, status FROM orders WHERE table_session_id = ? AND status NOT IN ('paid','cancelled')`,
        args: [sessionId],
    });
    for (const order of pendingOrders.rows) {
        await transaction.execute({
            sql: `UPDATE orders SET status='paid', accepted_at=COALESCE(accepted_at,?), served_at=COALESCE(served_at,?), paid_at=?, updated_at=? WHERE id=?`,
            args: [now, now, now, now, Number(order.id)],
        });
        await payLoyaltyForOrder(
            transaction,
            order as Record<string, unknown>,
            now,
        );
    }
    const session = await transaction.execute({
        sql: "SELECT status FROM table_sessions WHERE id = ?",
        args: [sessionId],
    });
    if (String(session.rows[0]?.status) === "open") {
        await transaction.execute({
            sql: `UPDATE table_sessions SET status='closed', qr_ordering_enabled=0, closed_by=?, closed_at=?, closing_note='Tam ödeme ile otomatik kapatıldı.', updated_at=? WHERE id=? AND status='open'`,
            args: [actorId, now, now, sessionId],
        });
        await transaction.execute({
            sql: `UPDATE service_requests SET status='resolved', resolved_by=?, resolved_at=?, claimed_by=NULL, claimed_at=NULL, updated_at=? WHERE table_session_id=? AND status='open'`,
            args: [actorId, now, now, sessionId],
        });
    }
    return true;
}

async function claimRequest(
    user: AuthenticatedUser,
    requestId: number,
    environment: ApiEnvironment,
): Promise<Response> {
    requireRole(user, ["waiter"]);
    const database = await getDatabase(environment);
    const requestResult = await database.execute({
        sql: "SELECT branch_id, status, claimed_by FROM service_requests WHERE id = ?",
        args: [requestId],
    });
    const serviceRequest = requestResult.rows[0];
    if (!serviceRequest)
        throw new ApiError(
            404,
            "Garson çağrısı bulunamadı.",
            "request_not_found",
        );
    await requireBranchAccess(
        user,
        Number(serviceRequest.branch_id),
        environment,
    );
    const now = nowIso(environment);
    const claimed = await database.execute({
        sql: `UPDATE service_requests SET claimed_by=?, claimed_at=?, updated_at=?
              WHERE id=? AND status='open' AND (claimed_by IS NULL OR claimed_by=?)`,
        args: [user.id, now, now, requestId, user.id],
    });
    if (claimed.rowsAffected !== 1)
        throw new ApiError(
            409,
            "Bu çağrıyla başka bir garson ilgileniyor veya çağrı tamamlandı.",
            "request_already_claimed",
        );
    return new Response(
        JSON.stringify({ ok: true, message: "Çağrı senin üzerine alındı." }),
        { headers: { "content-type": "application/json; charset=utf-8" } },
    );
}

async function resolveRequest(
    user: AuthenticatedUser,
    requestId: number,
    environment: ApiEnvironment,
): Promise<Response> {
    requireRole(user, [
        "platform_admin",
        "cafe_owner",
        "cafe_manager",
        "waiter",
    ]);
    const database = await getDatabase(environment);
    const row = await database.execute({
        sql: "SELECT branch_id, status, claimed_by FROM service_requests WHERE id = ?",
        args: [requestId],
    });
    const serviceRequest = row.rows[0];
    if (!serviceRequest)
        throw new ApiError(
            404,
            "Garson çağrısı bulunamadı.",
            "request_not_found",
        );
    await requireBranchAccess(
        user,
        Number(serviceRequest.branch_id),
        environment,
    );
    if (user.role === "waiter" && Number(serviceRequest.claimed_by) !== user.id)
        throw new ApiError(
            403,
            "Çağrıyı önce kendi üzerinize alın.",
            "request_not_claimed_by_you",
        );
    const now = nowIso(environment);
    const result = await database.execute({
        sql: `UPDATE service_requests SET status='resolved', resolved_by=?, resolved_at=?, claimed_by=NULL, claimed_at=NULL, updated_at=? WHERE id=? AND status='open' AND (claimed_by=? OR ? IN ('cafe_owner','cafe_manager','platform_admin'))`,
        args: [user.id, now, now, requestId, user.id, user.role],
    });
    if (result.rowsAffected !== 1)
        throw new ApiError(
            409,
            "Çağrı başka biri tarafından tamamlandı.",
            "request_already_resolved",
        );
    return new Response(
        JSON.stringify({ ok: true, message: "Çağrı tamamlandı." }),
        { headers: { "content-type": "application/json; charset=utf-8" } },
    );
}

async function openTable(
    user: AuthenticatedUser,
    branchId: number,
    tableId: number,
    environment: ApiEnvironment,
): Promise<Response> {
    requireRole(user, [
        "platform_admin",
        "cafe_owner",
        "cafe_manager",
        "waiter",
    ]);
    await requireBranchAccess(user, branchId, environment);
    const now = nowIso(environment);
    const outcome = await withTransaction(environment, async (transaction) => {
        const tableResult = await transaction.execute({
            sql: `SELECT id FROM dining_tables WHERE id=? AND branch_id=? AND is_active=1 AND deleted_at IS NULL`,
            args: [tableId, branchId],
        });
        if (!tableResult.rows.length)
            throw new ApiError(404, "Masa bulunamadı.", "table_not_found");
        const current = await transaction.execute({
            sql: `SELECT id FROM table_sessions WHERE dining_table_id=? AND status='open' LIMIT 1`,
            args: [tableId],
        });
        if (current.rows[0])
            return { id: Number(current.rows[0].id), created: false };
        const inserted = await transaction.execute({
            sql: `INSERT INTO table_sessions (branch_id,dining_table_id,status,qr_ordering_enabled,opened_by,opened_at,created_at,updated_at)
                  VALUES (?,?,'open',1,?,?,?,?) RETURNING id`,
            args: [branchId, tableId, user.id, now, now, now],
        });
        const sessionId = Number(inserted.rows[0]?.id);
        await transaction.execute({
            sql: `INSERT INTO activity_logs (branch_id,user_id,action,subject_type,subject_id,created_at) VALUES (?,?,'table_session.opened','TableSession',?,?)`,
            args: [branchId, user.id, sessionId, now],
        });
        return { id: sessionId, created: true };
    });
    return new Response(
        JSON.stringify({
            ok: true,
            ...outcome,
            message: outcome.created
                ? "Masa açıldı."
                : "Masanın adisyonu zaten açık.",
        }),
        {
            status: outcome.created ? 201 : 200,
            headers: { "content-type": "application/json; charset=utf-8" },
        },
    );
}

async function setQrOrdering(
    request: Request,
    user: AuthenticatedUser,
    sessionId: number,
    environment: ApiEnvironment,
): Promise<Response> {
    requireRole(user, [
        "platform_admin",
        "cafe_owner",
        "cafe_manager",
        "waiter",
    ]);
    const input = parseInput(
        z.object({ enabled: z.boolean() }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const session = await getScopedSession(
        database,
        user,
        sessionId,
        environment,
    );
    if (String(session.status) !== "open")
        throw new ApiError(
            409,
            "Kapalı adisyonda QR siparişi değiştirilemez.",
            "session_closed",
        );
    const now = nowIso(environment);
    await database.execute({
        sql: `UPDATE table_sessions SET qr_ordering_enabled=?, updated_at=? WHERE id=? AND status='open'`,
        args: [input.enabled ? 1 : 0, now, sessionId],
    });
    return new Response(
        JSON.stringify({
            ok: true,
            message: input.enabled
                ? "QR siparişi açıldı."
                : "QR siparişi kapatıldı.",
        }),
        { headers: { "content-type": "application/json; charset=utf-8" } },
    );
}

async function recordPayment(
    request: Request,
    user: AuthenticatedUser,
    sessionId: number,
    environment: ApiEnvironment,
): Promise<Response> {
    requireRole(user, [
        "platform_admin",
        "cafe_owner",
        "cafe_manager",
        "waiter",
    ]);
    const input = parseInput(
        z.object({
            method: z.enum(["cash", "card"]),
            amountCents: z.number().int().positive(),
            personLabel: z.string().trim().max(80).optional().default(""),
            orderItemId: z.number().int().positive().optional(),
        }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const session = await getScopedSession(
        database,
        user,
        sessionId,
        environment,
    );
    const now = nowIso(environment);
    const result = await withTransaction(environment, async (transaction) => {
        const lockedSession = await transaction.execute({
            sql: "SELECT status FROM table_sessions WHERE id = ?",
            args: [sessionId],
        });
        if (String(lockedSession.rows[0]?.status) !== "open")
            throw new ApiError(
                409,
                "Adisyon kapalı. Önce geçmiş ödemeyi düzeltin.",
                "session_closed",
            );
        const total = await transaction.execute({
            sql: `SELECT COALESCE(SUM(CASE WHEN status='cancelled' THEN 0 ELSE total_cents END),0) AS total FROM orders WHERE table_session_id=?`,
            args: [sessionId],
        });
        const paid = await transaction.execute({
            sql: `SELECT COALESCE(SUM(amount_cents),0) AS paid FROM payments WHERE table_session_id=? AND voided_at IS NULL`,
            args: [sessionId],
        });
        const due = Math.max(
            0,
            Number(total.rows[0]?.total ?? 0) - Number(paid.rows[0]?.paid ?? 0),
        );
        if (due === 0 || input.amountCents > due)
            throw new ApiError(
                422,
                "Tahsilat tutarı kalan adisyon tutarını aşamaz.",
                "payment_exceeds_due",
            );
        if (input.orderItemId) {
            const itemResult = await transaction.execute({
                sql: `SELECT i.line_total_cents FROM order_items i JOIN orders o ON o.id=i.order_id
                      WHERE i.id=? AND o.table_session_id=? AND o.status IN ('accepted','ready','served')`,
                args: [input.orderItemId, sessionId],
            });
            const item = itemResult.rows[0];
            if (!item)
                throw new ApiError(
                    404,
                    "Ödenecek ürün bulunamadı.",
                    "order_item_not_found",
                );
            const itemPayments = await transaction.execute({
                sql: "SELECT COALESCE(SUM(amount_cents),0) AS paid FROM payments WHERE order_item_id=? AND voided_at IS NULL",
                args: [input.orderItemId],
            });
            if (
                input.amountCents >
                Number(item.line_total_cents) -
                    Number(itemPayments.rows[0]?.paid ?? 0)
            )
                throw new ApiError(
                    422,
                    "Bu ürün için kalan tutardan fazla ödeme kaydedilemez.",
                    "payment_exceeds_item_due",
                );
        }
        const inserted = await transaction.execute({
            sql: `INSERT INTO payments (table_session_id,recorded_by,order_item_id,method,amount_cents,person_label,recorded_at,created_at,updated_at)
                  VALUES (?,?,?,?,?,?,?,?,?) RETURNING id`,
            args: [
                sessionId,
                user.id,
                input.orderItemId ?? null,
                input.method,
                input.amountCents,
                input.personLabel || null,
                now,
                now,
                now,
            ],
        });
        const paymentId = Number(inserted.rows[0]?.id);
        const closed = await settleSession(
            transaction,
            sessionId,
            user.id,
            now,
        );
        await transaction.execute({
            sql: `INSERT INTO activity_logs (cafe_id,branch_id,user_id,action,subject_type,subject_id,metadata_json,created_at)
                  VALUES (?,?,?,'payment.recorded','Payment',?,?,?)`,
            args: [
                Number(session.cafe_id),
                Number(session.branch_id),
                user.id,
                paymentId,
                JSON.stringify({
                    method: input.method,
                    amount_cents: input.amountCents,
                    person_label: input.personLabel || null,
                }),
                now,
            ],
        });
        return { paymentId, closed };
    });
    return new Response(
        JSON.stringify({
            ok: true,
            ...result,
            message: result.closed
                ? "Ödeme alındı, adisyon kapatıldı."
                : "Ödeme kaydedildi. Kart ödemesi fiziksel POS üzerinden alınmış olmalıdır.",
        }),
        {
            status: 201,
            headers: { "content-type": "application/json; charset=utf-8" },
        },
    );
}

async function closeSession(
    user: AuthenticatedUser,
    sessionId: number,
    environment: ApiEnvironment,
): Promise<Response> {
    requireRole(user, [
        "platform_admin",
        "cafe_owner",
        "cafe_manager",
        "waiter",
    ]);
    const database = await getDatabase(environment);
    const session = await getScopedSession(
        database,
        user,
        sessionId,
        environment,
    );
    if (String(session.status) !== "open")
        throw new ApiError(409, "Adisyon zaten kapalı.", "session_closed");
    const snapshot = await sessionSnapshot(
        database,
        session as unknown as Record<string, unknown>,
    );
    if (snapshot.dueCents > 0)
        throw new ApiError(
            422,
            `Adisyonda ${formatCurrency(snapshot.dueCents)} tahsil edilmemiş tutar var.`,
            "session_balance_due",
        );
    const now = nowIso(environment);
    await database.execute({
        sql: `UPDATE table_sessions SET status='closed', qr_ordering_enabled=0, closed_by=?, closed_at=?, closing_note='Garson tarafından kapatıldı.', updated_at=? WHERE id=? AND status='open'`,
        args: [user.id, now, now, sessionId],
    });
    return new Response(
        JSON.stringify({ ok: true, message: "Adisyon kapatıldı." }),
        { headers: { "content-type": "application/json; charset=utf-8" } },
    );
}

async function voidPayment(
    request: Request,
    user: AuthenticatedUser,
    sessionId: number,
    paymentId: number,
    environment: ApiEnvironment,
): Promise<Response> {
    requireRole(user, [
        "platform_admin",
        "cafe_owner",
        "cafe_manager",
        "waiter",
    ]);
    const input = parseInput(
        z.object({ reason: z.string().trim().min(3).max(160) }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const session = await getScopedSession(
        database,
        user,
        sessionId,
        environment,
    );
    const now = nowIso(environment);
    await withTransaction(environment, async (transaction) => {
        const paymentResult = await transaction.execute({
            sql: "SELECT id, recorded_by, voided_at FROM payments WHERE id=? AND table_session_id=?",
            args: [paymentId, sessionId],
        });
        const payment = paymentResult.rows[0];
        if (!payment)
            throw new ApiError(
                404,
                "Ödeme kaydı bulunamadı.",
                "payment_not_found",
            );
        if (payment.voided_at)
            throw new ApiError(
                409,
                "Bu ödeme kaydı daha önce iptal edilmiş.",
                "payment_already_voided",
            );
        if (
            String(session.status) === "closed" &&
            !["platform_admin", "cafe_owner", "cafe_manager"].includes(
                user.role,
            ) &&
            Number(payment.recorded_by) !== user.id
        ) {
            throw new ApiError(
                403,
                "Kapalı adisyonda yalnızca ödemeyi kaydeden çalışan veya yönetici düzeltme yapabilir.",
                "forbidden",
            );
        }
        await transaction.execute({
            sql: "UPDATE payments SET voided_at=?, void_reason=?, updated_at=? WHERE id=? AND voided_at IS NULL",
            args: [now, input.reason, now, paymentId],
        });
        const total = await transaction.execute({
            sql: `SELECT COALESCE(SUM(CASE WHEN status='cancelled' THEN 0 ELSE total_cents END),0) AS total FROM orders WHERE table_session_id=?`,
            args: [sessionId],
        });
        const paid = await transaction.execute({
            sql: "SELECT COALESCE(SUM(amount_cents),0) AS paid FROM payments WHERE table_session_id=? AND voided_at IS NULL",
            args: [sessionId],
        });
        if (
            String(session.status) === "closed" &&
            Number(paid.rows[0]?.paid ?? 0) < Number(total.rows[0]?.total ?? 0)
        ) {
            const paidOrders = await transaction.execute({
                sql: `SELECT id, cafe_id, customer_id FROM orders WHERE table_session_id=? AND status='paid'`,
                args: [sessionId],
            });
            for (const order of paidOrders.rows)
                await reverseEarnedPoints(
                    transaction,
                    order as Record<string, unknown>,
                    user.id,
                    now,
                );
            await transaction.execute({
                sql: `UPDATE orders SET status='served', paid_at=NULL, updated_at=? WHERE table_session_id=? AND status='paid'`,
                args: [now, sessionId],
            });
            await transaction.execute({
                sql: `UPDATE table_sessions SET status='open', qr_ordering_enabled=0, closed_by=NULL, closed_at=NULL, closing_note=NULL, updated_at=? WHERE id=?`,
                args: [now, sessionId],
            });
        }
        await transaction.execute({
            sql: `INSERT INTO activity_logs (cafe_id,branch_id,user_id,action,subject_type,subject_id,metadata_json,created_at)
                  VALUES (?,?,?,'payment.voided','Payment',?,?,?)`,
            args: [
                Number(session.cafe_id),
                Number(session.branch_id),
                user.id,
                paymentId,
                JSON.stringify({ reason: input.reason }),
                now,
            ],
        });
    });
    return new Response(
        JSON.stringify({
            ok: true,
            message: "Ödeme kaydı iptal edildi ve geçmişte tutuldu.",
        }),
        { headers: { "content-type": "application/json; charset=utf-8" } },
    );
}

async function reverseEarnedPoints(
    transaction: Transaction,
    order: Record<string, unknown>,
    actorId: number,
    now: string,
) {
    if (!order.customer_id) return;
    const earnings = await transaction.execute({
        sql: `SELECT COALESCE(SUM(points_delta),0) AS points, COALESCE(SUM(orders_delta),0) AS orders FROM loyalty_transactions WHERE order_id=? AND type IN ('earned','earned_reversed')`,
        args: [Number(order.id)],
    });
    const points = Number(earnings.rows[0]?.points ?? 0);
    const orders = Number(earnings.rows[0]?.orders ?? 0);
    if (points <= 0 && orders <= 0) return;
    await transaction.execute({
        sql: "UPDATE loyalty_accounts SET points=MAX(0,points-?), completed_order_count=MAX(0,completed_order_count-?), updated_at=? WHERE cafe_id=? AND user_id=?",
        args: [
            points,
            orders,
            now,
            Number(order.cafe_id),
            Number(order.customer_id),
        ],
    });
    await transaction.execute({
        sql: `INSERT INTO loyalty_transactions (cafe_id,user_id,order_id,actor_id,type,points_delta,orders_delta,discount_amount_cents,description,created_at)
              VALUES (?,?,?,?,'earned_reversed',?,?,0,'Tahsilat kaydı düzeltildi; sipariş puanı geri alındı',?)`,
        args: [
            Number(order.cafe_id),
            Number(order.customer_id),
            Number(order.id),
            actorId,
            -points,
            -orders,
            now,
        ],
    });
}

async function createManualOrder(
    request: Request,
    user: AuthenticatedUser,
    sessionId: number,
    environment: ApiEnvironment,
): Promise<Response> {
    requireRole(user, [
        "platform_admin",
        "cafe_owner",
        "cafe_manager",
        "waiter",
    ]);
    const input = parseInput(
        z.object({
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
            note: z.string().trim().max(500).optional().default(""),
            mode: z.enum(["send", "served"]).optional().default("send"),
        }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const session = await getScopedSession(
        database,
        user,
        sessionId,
        environment,
    );
    if (String(session.status) !== "open")
        throw new ApiError(
            409,
            "Kapalı adisyona ürün eklenemez.",
            "session_closed",
        );
    const branchResult = await database.execute({
        sql: "SELECT uses_shared_menu FROM branches WHERE id=?",
        args: [Number(session.branch_id)],
    });
    const menuBranchId =
        Number(branchResult.rows[0]?.uses_shared_menu) === 1
            ? null
            : Number(session.branch_id);
    const productIds = [...new Set(input.lines.map((line) => line.productId))];
    if (productIds.length > 30)
        throw new ApiError(
            422,
            "Bir seferde en fazla 30 farklı ürün ekleyebilirsiniz.",
            "too_many_products",
        );
    const placeholders = productIds.map(() => "?").join(",");
    const now = nowIso(environment);
    const created = await withTransaction(environment, async (transaction) => {
        const active = await transaction.execute({
            sql: `SELECT id FROM table_sessions WHERE id=? AND status='open'`,
            args: [sessionId],
        });
        if (!active.rows.length)
            throw new ApiError(
                409,
                "Adisyon az önce kapatıldı.",
                "session_closed",
            );
        const productsResult = await transaction.execute({
            sql: `SELECT p.id,p.name,p.price_cents,p.category_id,COALESCE(bp.price_cents,p.price_cents) AS effective_price,
                         COALESCE(bp.is_available,p.is_available) AS effective_available,c.is_active AS category_active
                  FROM menu_products p JOIN menu_categories c ON c.id=p.category_id LEFT JOIN branch_product bp ON bp.menu_product_id=p.id AND bp.branch_id=?
                  WHERE p.cafe_id=? AND ((p.branch_id IS NULL AND ? IS NULL) OR p.branch_id=?) AND p.id IN (${placeholders})`,
            args: [
                Number(session.branch_id),
                Number(session.cafe_id),
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
                    Number(product.effective_available) !== 1 ||
                    Number(product.category_active) !== 1,
            )
        ) {
            throw new ApiError(
                409,
                "Seçilen ürünlerden biri bu şubede satışa açık değil.",
                "product_unavailable",
            );
        }
        const lines = [];
        let subtotal = 0;
        for (const line of input.lines) {
            const product = products.get(line.productId);
            if (!product)
                throw new ApiError(
                    422,
                    "Ürün menüde bulunamadı.",
                    "product_not_found",
                );
            const selectedOptions = await resolveOptions(
                transaction,
                line.productId,
                line.optionIds,
            );
            const unitPrice =
                Number(product.effective_price) + selectedOptions.delta;
            const lineTotal = unitPrice * line.quantity;
            subtotal += lineTotal;
            lines.push({
                productId: line.productId,
                name: String(product.name),
                unitPrice,
                quantity: line.quantity,
                lineTotal,
                note: line.note || null,
                options: selectedOptions.snapshots,
            });
        }
        const orderNumber = randomBytes(5).toString("hex").toUpperCase();
        const status = input.mode === "served" ? "served" : "pending";
        const result = await transaction.execute({
            sql: `INSERT INTO orders (cafe_id,branch_id,table_session_id,created_by,order_number,source,status,subtotal_cents,discount_total_cents,total_cents,customer_note,accepted_at,served_at,created_at,updated_at)
                  VALUES (?,?,?, ?,?,'manual',?, ?,0,?,?,?, ?,?,?) RETURNING id`,
            args: [
                Number(session.cafe_id),
                Number(session.branch_id),
                sessionId,
                user.id,
                orderNumber,
                status,
                subtotal,
                subtotal,
                input.note || null,
                input.mode === "served" ? now : null,
                input.mode === "served" ? now : null,
                now,
                now,
            ],
        });
        const orderId = Number(result.rows[0]?.id);
        for (const line of lines) {
            const item = await transaction.execute({
                sql: "INSERT INTO order_items (order_id,menu_product_id,name_snapshot,unit_price_cents,quantity,line_total_cents,customer_note,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?) RETURNING id",
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
            const itemId = Number(item.rows[0]?.id);
            for (const option of line.options)
                await transaction.execute({
                    sql: "INSERT INTO order_item_options (order_item_id,menu_option_id,group_name_snapshot,name_snapshot,price_delta_cents,created_at,updated_at) VALUES (?,?,?,?,?,?,?)",
                    args: [
                        itemId,
                        option.id,
                        option.group,
                        option.name,
                        option.delta,
                        now,
                        now,
                    ],
                });
        }
        await transaction.execute({
            sql: `INSERT INTO activity_logs (cafe_id,branch_id,user_id,action,subject_type,subject_id,created_at) VALUES (?,?,?,'order.added_by_waiter','Order',?,?)`,
            args: [
                Number(session.cafe_id),
                Number(session.branch_id),
                user.id,
                orderId,
                now,
            ],
        });
        return orderId;
    });
    return new Response(
        JSON.stringify({ ok: true, order: await getOrder(database, created) }),
        {
            status: 201,
            headers: { "content-type": "application/json; charset=utf-8" },
        },
    );
}

async function branchMenu(
    user: AuthenticatedUser,
    branchId: number,
    environment: ApiEnvironment,
): Promise<Response> {
    requireRole(user, [
        "platform_admin",
        "cafe_owner",
        "cafe_manager",
        "waiter",
        "kitchen",
    ]);
    await requireBranchAccess(user, branchId, environment);
    const database = await getDatabase(environment);
    const branchResult = await database.execute({
        sql: "SELECT cafe_id, uses_shared_menu FROM branches WHERE id=? AND is_active=1 AND deleted_at IS NULL",
        args: [branchId],
    });
    const branch = branchResult.rows[0];
    if (!branch)
        throw new ApiError(404, "Şube bulunamadı.", "branch_not_found");
    const cafeId = Number(branch.cafe_id);
    const menuBranchId =
        Number(branch.uses_shared_menu) === 1 ? null : branchId;
    const categories = await database.execute({
        sql: `SELECT id,name FROM menu_categories WHERE cafe_id=?
              AND ((branch_id IS NULL AND ? IS NULL) OR branch_id=?) AND is_active=1
              ORDER BY sort_order,id`,
        args: [cafeId, menuBranchId, menuBranchId],
    });
    const products = await database.execute({
        sql: `SELECT p.id,p.category_id,p.name,p.description,p.price_cents,p.image_path,p.is_available,
                     COALESCE(bp.price_cents,p.price_cents) AS effective_price,
                     COALESCE(bp.is_available,p.is_available) AS effective_available
              FROM menu_products p JOIN menu_categories c ON c.id=p.category_id
              LEFT JOIN branch_product bp ON bp.menu_product_id=p.id AND bp.branch_id=?
              WHERE p.cafe_id=? AND ((p.branch_id IS NULL AND ? IS NULL) OR p.branch_id=?)
                AND c.is_active=1 AND p.is_available=1
              ORDER BY c.sort_order,p.sort_order,p.id`,
        args: [branchId, cafeId, menuBranchId, menuBranchId],
    });
    const catalog = [];
    for (const product of products.rows) {
        if (Number(product.effective_available) !== 1) continue;
        const groups = await database.execute({
            sql: `SELECT id,name,selection_mode,min_selections,max_selections FROM menu_option_groups
                  WHERE menu_product_id=? AND is_active=1 ORDER BY sort_order,id`,
            args: [Number(product.id)],
        });
        const optionGroups = [];
        for (const group of groups.rows) {
            const options = await database.execute({
                sql: `SELECT id,name,price_delta_cents FROM menu_options WHERE menu_option_group_id=?
                      AND is_available=1 ORDER BY sort_order,id`,
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
                options: options.rows.map((option) => ({
                    id: Number(option.id),
                    name: String(option.name),
                    priceDeltaCents: Number(option.price_delta_cents),
                })),
            });
        }
        catalog.push({
            id: Number(product.id),
            categoryId: Number(product.category_id),
            name: String(product.name),
            description: product.description
                ? String(product.description)
                : null,
            imageUrl: product.image_path ? String(product.image_path) : null,
            priceCents: Number(product.effective_price),
            optionGroups,
        });
    }
    return new Response(
        JSON.stringify({
            categories: categories.rows.map((category) => ({
                id: Number(category.id),
                name: String(category.name),
            })),
            products: catalog,
        }),
        {
            headers: {
                "content-type": "application/json; charset=utf-8",
                "cache-control": "private, no-store",
            },
        },
    );
}

async function resolveOptions(
    transaction: Transaction,
    productId: number,
    optionIds: number[],
) {
    const groups = await transaction.execute({
        sql: "SELECT id,name,selection_mode,min_selections,max_selections FROM menu_option_groups WHERE menu_product_id=? AND is_active=1 ORDER BY sort_order,id",
        args: [productId],
    });
    const picked = new Set(optionIds);
    if (picked.size !== optionIds.length)
        throw new ApiError(
            422,
            "Aynı seçenek birden fazla seçilemez.",
            "invalid_options",
        );
    const allowed = new Set<number>();
    const snapshots: {
        id: number;
        group: string;
        name: string;
        delta: number;
    }[] = [];
    let delta = 0;
    for (const group of groups.rows) {
        const options = await transaction.execute({
            sql: "SELECT id,name,price_delta_cents FROM menu_options WHERE menu_option_group_id=? AND is_available=1",
            args: [Number(group.id)],
        });
        const selected = options.rows.filter((option) =>
            picked.has(Number(option.id)),
        );
        for (const option of options.rows) allowed.add(Number(option.id));
        const max =
            String(group.selection_mode) === "single"
                ? 1
                : Number(group.max_selections ?? options.rows.length);
        if (
            selected.length < Number(group.min_selections) ||
            selected.length > max
        )
            throw new ApiError(
                422,
                `${String(group.name)} seçeneklerini kontrol edin.`,
                "invalid_options",
            );
        for (const option of selected) {
            const price = Number(option.price_delta_cents);
            delta += price;
            snapshots.push({
                id: Number(option.id),
                group: String(group.name),
                name: String(option.name),
                delta: price,
            });
        }
    }
    if ([...picked].some((id) => !allowed.has(id)))
        throw new ApiError(
            422,
            "Ürün seçeneği menüde bulunamadı.",
            "invalid_options",
        );
    return { snapshots, delta };
}

export async function handleStaffRequest(
    request: Request,
    path: string,
    environment: ApiEnvironment,
): Promise<Response | null> {
    if (!path.startsWith("/api/staff/")) return null;
    const user = await currentUser(request, environment);
    if (!user)
        throw new ApiError(
            401,
            "Bu işlem için giriş yapın.",
            "authentication_required",
        );
    const method = request.method.toUpperCase();
    if (method === "GET" && path === "/api/staff/board")
        return board(request, user, environment);
    let catalogMatch = /^\/api\/staff\/branches\/(\d+)\/menu$/.exec(path);
    if (method === "GET" && catalogMatch)
        return branchMenu(user, Number(catalogMatch[1]), environment);
    if (method === "POST") {
        checkSameOrigin(request);
        requireCsrf(request, user);
    }

    let match = /^\/api\/staff\/orders\/(\d+)\/status$/.exec(path);
    if (method === "POST" && match)
        return updateOrderStatus(request, user, Number(match[1]), environment);
    match = /^\/api\/staff\/service-requests\/(\d+)\/claim$/.exec(path);
    if (method === "POST" && match)
        return claimRequest(user, Number(match[1]), environment);
    match = /^\/api\/staff\/service-requests\/(\d+)\/resolve$/.exec(path);
    if (method === "POST" && match)
        return resolveRequest(user, Number(match[1]), environment);
    match = /^\/api\/staff\/branches\/(\d+)\/tables\/(\d+)\/open$/.exec(path);
    if (method === "POST" && match)
        return openTable(user, Number(match[1]), Number(match[2]), environment);
    match = /^\/api\/staff\/sessions\/(\d+)\/qr-ordering$/.exec(path);
    if (method === "POST" && match)
        return setQrOrdering(request, user, Number(match[1]), environment);
    match = /^\/api\/staff\/sessions\/(\d+)\/payments$/.exec(path);
    if (method === "POST" && match)
        return recordPayment(request, user, Number(match[1]), environment);
    match = /^\/api\/staff\/sessions\/(\d+)\/payments\/(\d+)\/void$/.exec(path);
    if (method === "POST" && match)
        return voidPayment(
            request,
            user,
            Number(match[1]),
            Number(match[2]),
            environment,
        );
    match = /^\/api\/staff\/sessions\/(\d+)\/close$/.exec(path);
    if (method === "POST" && match)
        return closeSession(user, Number(match[1]), environment);
    match = /^\/api\/staff\/sessions\/(\d+)\/orders$/.exec(path);
    if (method === "POST" && match)
        return createManualOrder(request, user, Number(match[1]), environment);
    match = /^\/api\/staff\/sessions\/(\d+)$/.exec(path);
    if (method === "GET" && match) {
        const database = await getDatabase(environment);
        const session = await getScopedSession(
            database,
            user,
            Number(match[1]),
            environment,
        );
        return new Response(
            JSON.stringify({
                session: await sessionSnapshot(
                    database,
                    session as unknown as Record<string, unknown>,
                ),
                tableName: String(session.table_name),
                branchName: String(session.branch_name),
            }),
            {
                headers: {
                    "content-type": "application/json; charset=utf-8",
                    "cache-control": "no-store",
                },
            },
        );
    }
    return null;
}
