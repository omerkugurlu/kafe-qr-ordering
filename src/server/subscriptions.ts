import type { Client, Transaction } from "@libsql/client";
import { z } from "zod";
import {
    parseSubscriptionFeatures,
    subscriptionFeatureKeys,
    type SubscriptionFeature,
} from "../domain/subscriptions.js";
import {
    ApiError,
    getDatabase,
    nowIso,
    readJson,
    type ApiEnvironment,
    type AuthenticatedUser,
    withTransaction,
} from "./http.js";

type Row = Record<string, unknown>;
type DatabaseExecutor = Pick<Client, "execute">;
type PeriodKind = "current" | "scheduled" | "expired";

function parseInput<T>(schema: z.ZodType<T>, input: unknown): T {
    const parsed = schema.safeParse(input);
    if (!parsed.success)
        throw new ApiError(
            422,
            parsed.error.issues[0]?.message ?? "Bilgileri kontrol edin.",
            "validation_failed",
            parsed.error.flatten(),
        );
    return parsed.data;
}

function assertCafeOwner(user: AuthenticatedUser): number {
    if (user.role !== "cafe_owner" || !user.cafeId)
        throw new ApiError(
            403,
            "Paket işlemleri yalnızca kafe sahibi tarafından yapılabilir.",
            "forbidden",
        );
    return user.cafeId;
}

function assertPlatformAdmin(user: AuthenticatedUser): void {
    if (user.role !== "platform_admin")
        throw new ApiError(
            403,
            "Bu işlem için süper yönetici yetkisi gerekir.",
            "forbidden",
        );
}

function rowString(row: Row, key: string): string {
    return String(row[key] ?? "");
}

function asPlan(row: Row) {
    return {
        id: Number(row.id),
        code: rowString(row, "code"),
        name: rowString(row, "name"),
        annualPriceCents: Number(row.annual_price_cents),
        maxTables: Number(row.max_tables),
        maxBranches:
            row.max_branches === null ? null : Number(row.max_branches),
        features: parseSubscriptionFeatures(row.features_json),
        isActive: Number(row.is_active) === 1,
        sortOrder: Number(row.sort_order),
    };
}

function asSubscription(row: Row) {
    return {
        id: Number(row.id),
        cafeId: Number(row.cafe_id),
        planId: Number(row.subscription_plan_id),
        planName: rowString(row, "plan_name_snapshot"),
        status: rowString(row, "status"),
        startsAt: rowString(row, "starts_at"),
        endsAt: rowString(row, "ends_at"),
        annualPriceCents: Number(row.annual_price_snapshot_cents),
        maxTables: Number(row.max_tables_snapshot),
        maxBranches:
            row.max_branches_snapshot === null
                ? null
                : Number(row.max_branches_snapshot),
        features: parseSubscriptionFeatures(row.features_snapshot_json),
    };
}

function toMillis(value: unknown): number {
    return Date.parse(String(value));
}

function periodKind(row: Row, now: string): PeriodKind | null {
    if (row.status !== "active") return null;
    const startsAt = toMillis(row.starts_at);
    const endsAt = toMillis(row.ends_at);
    const nowAt = toMillis(now);
    if (startsAt <= nowAt && endsAt > nowAt) return "current";
    if (startsAt > nowAt && endsAt > nowAt) return "scheduled";
    if (startsAt <= nowAt && endsAt <= nowAt) return "expired";
    return null;
}

function daysRemaining(endsAt: string, now: string): number {
    return Math.max(
        0,
        Math.ceil((toMillis(endsAt) - toMillis(now)) / 86_400_000),
    );
}

async function subscriptionRows(
    database: DatabaseExecutor,
    cafeId: number,
): Promise<Row[]> {
    const result = await database.execute({
        sql: "SELECT * FROM cafe_subscriptions WHERE cafe_id=? ORDER BY starts_at,id",
        args: [cafeId],
    });
    return result.rows as Row[];
}

async function currentRow(
    database: DatabaseExecutor,
    cafeId: number,
    now: string,
): Promise<Row | null> {
    const rows = await subscriptionRows(database, cafeId);
    return rows.find((row) => periodKind(row, now) === "current") ?? null;
}

export async function cafeHasSubscriptionHistory(
    database: DatabaseExecutor,
    cafeId: number,
): Promise<boolean> {
    const result = await database.execute({
        sql: "SELECT 1 FROM cafe_subscriptions WHERE cafe_id=? LIMIT 1",
        args: [cafeId],
    });
    return result.rows.length > 0;
}

export async function cafeFeatureEnabled(
    database: DatabaseExecutor,
    cafeId: number,
    feature: SubscriptionFeature,
    now = new Date().toISOString(),
): Promise<boolean> {
    if (!subscriptionFeatureKeys.includes(feature)) return false;
    if (!(await cafeHasSubscriptionHistory(database, cafeId))) return false;
    const current = await currentRow(database, cafeId, now);
    return (
        current !== null &&
        parseSubscriptionFeatures(current.features_snapshot_json)[feature]
    );
}

export async function requireCafeFeature(
    database: DatabaseExecutor,
    cafeId: number,
    feature: SubscriptionFeature,
    now?: string,
): Promise<void> {
    if (!(await cafeFeatureEnabled(database, cafeId, feature, now)))
        throw new ApiError(
            403,
            feature === "customer_accounts"
                ? "Bu paket müşteri hesaplarını içermiyor."
                : "Bu özellik mevcut paketinizde bulunmuyor veya paket süreniz dolmuş.",
            "subscription_feature_unavailable",
        );
}

export async function requireCafeServiceAccess(
    database: DatabaseExecutor,
    cafeId: number,
    now = new Date().toISOString(),
): Promise<void> {
    if (!(await currentRow(database, cafeId, now)))
        throw new ApiError(
            403,
            "Paket süreniz dolmuş. Kafe sahibinden paket yenilemesini isteyin.",
            "subscription_expired",
        );
}

export async function cafeServiceAccessEnabled(
    database: DatabaseExecutor,
    cafeId: number,
    now = new Date().toISOString(),
): Promise<boolean> {
    return (await currentRow(database, cafeId, now)) !== null;
}

export async function requireCafeLimit(
    database: DatabaseExecutor,
    cafeId: number,
    kind: "tables" | "branches",
    now = new Date().toISOString(),
): Promise<void> {
    const current = await currentRow(database, cafeId, now);
    if (!current)
        throw new ApiError(
            403,
            "Paket süreniz dolmuş. Yeni kayıt oluşturmak için önce paketi yenileyin.",
            "subscription_expired",
        );
    const maximum =
        kind === "tables"
            ? Number(current.max_tables_snapshot)
            : current.max_branches_snapshot === null
              ? null
              : Number(current.max_branches_snapshot);
    if (maximum === null) return;
    const result =
        kind === "tables"
            ? await database.execute({
                  sql: `SELECT COUNT(*) AS count FROM dining_tables t JOIN branches b ON b.id=t.branch_id
                        WHERE b.cafe_id=? AND b.deleted_at IS NULL AND t.deleted_at IS NULL`,
                  args: [cafeId],
              })
            : await database.execute({
                  sql: "SELECT COUNT(*) AS count FROM branches WHERE cafe_id=? AND deleted_at IS NULL",
                  args: [cafeId],
              });
    const count = Number(result.rows[0]?.count ?? 0);
    if (count >= maximum)
        throw new ApiError(
            403,
            kind === "tables"
                ? `Paketiniz en fazla ${maximum} masa içeriyor.`
                : `Paketiniz en fazla ${maximum} şube içeriyor.`,
            kind === "tables" ? "table_limit_reached" : "branch_limit_reached",
        );
}

async function ownerSubscription(
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    const cafeId = assertCafeOwner(user);
    const database = await getDatabase(environment);
    const now = nowIso(environment);
    const rows = await subscriptionRows(database, cafeId);
    const current =
        rows.find((row) => periodKind(row, now) === "current") ?? null;
    const scheduled =
        rows.find((row) => periodKind(row, now) === "scheduled") ?? null;
    const latestExpired =
        [...rows]
            .filter((row) => periodKind(row, now) === "expired")
            .sort(
                (left, right) =>
                    toMillis(right.ends_at) - toMillis(left.ends_at),
            )[0] ?? null;
    const usage = await database.execute({
        sql: `SELECT
            (SELECT COUNT(*) FROM dining_tables t JOIN branches b ON b.id=t.branch_id WHERE b.cafe_id=? AND b.deleted_at IS NULL AND t.deleted_at IS NULL) AS tables,
            (SELECT COUNT(*) FROM branches WHERE cafe_id=? AND deleted_at IS NULL) AS branches`,
        args: [cafeId, cafeId],
    });
    const pending = await database.execute({
        sql: "SELECT id,plan_name_snapshot,annual_price_snapshot_cents,status,requested_at FROM cafe_subscription_requests WHERE cafe_id=? AND status='pending' LIMIT 1",
        args: [cafeId],
    });
    const history = await database.execute({
        sql: "SELECT id,plan_name_snapshot,annual_price_snapshot_cents,status,requested_at,reviewed_at,review_note FROM cafe_subscription_requests WHERE cafe_id=? ORDER BY requested_at DESC,id DESC LIMIT 8",
        args: [cafeId],
    });
    const plans = await database.execute({
        sql: "SELECT * FROM subscription_plans WHERE is_active=1 AND annual_price_cents>0 ORDER BY sort_order,id",
    });
    const mapRequest = (row: Row) => ({
        id: Number(row.id),
        planName: rowString(row, "plan_name_snapshot"),
        annualPriceCents: Number(row.annual_price_snapshot_cents),
        status: rowString(row, "status"),
        requestedAt: rowString(row, "requested_at"),
        reviewedAt: row.reviewed_at ? String(row.reviewed_at) : null,
        reviewNote: row.review_note ? String(row.review_note) : null,
    });

    return {
        cafeId,
        subscription: current ? asSubscription(current) : null,
        scheduledSubscription: scheduled ? asSubscription(scheduled) : null,
        latestExpiredSubscription: latestExpired
            ? asSubscription(latestExpired)
            : null,
        daysRemaining: current
            ? daysRemaining(rowString(current, "ends_at"), now)
            : 0,
        usage: {
            tables: Number(usage.rows[0]?.tables ?? 0),
            branches: Number(usage.rows[0]?.branches ?? 0),
        },
        activePlanIds: rows
            .filter(
                (row) =>
                    periodKind(row, now) === "current" ||
                    periodKind(row, now) === "scheduled",
            )
            .map((row) => Number(row.subscription_plan_id)),
        renewablePlanId:
            current && !scheduled && !pending.rows.length
                ? Number(current.subscription_plan_id)
                : null,
        pendingRequest: pending.rows[0]
            ? mapRequest(pending.rows[0] as Row)
            : null,
        requests: history.rows.map((row) => mapRequest(row as Row)),
        plans: plans.rows.map((row) => asPlan(row as Row)),
    };
}

async function requestSubscription(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    const cafeId = assertCafeOwner(user);
    const input = parseInput(
        z.object({ planId: z.number().int().positive() }),
        await readJson(request),
    );
    const now = nowIso(environment);
    try {
        return await withTransaction(environment, async (transaction) => {
            const cafe = await transaction.execute({
                sql: "SELECT id FROM cafes WHERE id=? AND deleted_at IS NULL",
                args: [cafeId],
            });
            if (!cafe.rows[0])
                throw new ApiError(404, "Kafe bulunamadı.", "cafe_not_found");
            const planResult = await transaction.execute({
                sql: "SELECT * FROM subscription_plans WHERE id=? AND is_active=1 AND annual_price_cents>0",
                args: [input.planId],
            });
            const plan = planResult.rows[0] as Row | undefined;
            if (!plan)
                throw new ApiError(
                    422,
                    "Bu paket şu anda satışa açık değil.",
                    "plan_unavailable",
                );

            const pending = await transaction.execute({
                sql: "SELECT id FROM cafe_subscription_requests WHERE pending_cafe_id=? LIMIT 1",
                args: [cafeId],
            });
            if (pending.rows.length)
                throw new ApiError(
                    409,
                    "İncelemede olan bir paket talebiniz var. Önce bu talebin sonuçlanmasını bekleyin.",
                    "subscription_request_pending",
                );
            const scheduled = await transaction.execute({
                sql: "SELECT id FROM cafe_subscriptions WHERE cafe_id=? AND status='active' AND starts_at>? AND ends_at>? LIMIT 1",
                args: [cafeId, now, now],
            });
            if (scheduled.rows.length)
                throw new ApiError(
                    409,
                    "Sırada başlayan bir paketiniz var. Yeni talep için bu dönemin başlamasını bekleyin.",
                    "subscription_scheduled",
                );

            const requestResult = await transaction.execute({
                sql: `INSERT INTO cafe_subscription_requests (cafe_id,pending_cafe_id,subscription_plan_id,requested_by,
                        plan_name_snapshot,annual_price_snapshot_cents,max_tables_snapshot,max_branches_snapshot,
                        features_snapshot_json,status,requested_at,created_at,updated_at)
                      VALUES (?,?,?,?,?,?,?,?,?,'pending',?,?,?) RETURNING id`,
                args: [
                    cafeId,
                    cafeId,
                    input.planId,
                    user.id,
                    String(plan.name),
                    Number(plan.annual_price_cents),
                    Number(plan.max_tables),
                    plan.max_branches === null
                        ? null
                        : Number(plan.max_branches),
                    String(plan.features_json),
                    now,
                    now,
                    now,
                ],
            });
            const requestId = Number(requestResult.rows[0]?.id);
            await transaction.execute({
                sql: "INSERT INTO activity_logs (cafe_id,user_id,action,subject_type,subject_id,metadata_json,ip_address,created_at) VALUES (?,?, 'subscription.requested','CafeSubscriptionRequest',?,?,?,?)",
                args: [
                    cafeId,
                    user.id,
                    requestId,
                    JSON.stringify({
                        planId: input.planId,
                        planName: String(plan.name),
                    }),
                    environment.context?.ip ?? null,
                    now,
                ],
            });
            return {
                request: {
                    id: requestId,
                    cafeId,
                    planId: input.planId,
                    planName: String(plan.name),
                    annualPriceCents: Number(plan.annual_price_cents),
                    status: "pending",
                    requestedAt: now,
                },
            };
        });
    } catch (error) {
        if (String(error).includes("cafe_subscription_one_pending_request"))
            throw new ApiError(
                409,
                "İncelemede olan bir paket talebiniz var. Önce bu talebin sonuçlanmasını bekleyin.",
                "subscription_request_pending",
            );
        throw error;
    }
}

async function listPlans(user: AuthenticatedUser, environment: ApiEnvironment) {
    assertPlatformAdmin(user);
    const database = await getDatabase(environment);
    const result = await database.execute({
        sql: "SELECT * FROM subscription_plans ORDER BY sort_order,id",
    });
    return { plans: result.rows.map((row) => asPlan(row as Row)) };
}

const planInputSchema = z
    .object({
        name: z.string().trim().min(2).max(80),
        annualPriceCents: z.number().int().min(0).max(100_000_000),
        maxTables: z.number().int().min(1).max(5000),
        maxBranches: z.number().int().min(1).max(500).nullable(),
        features: z
            .object({
                customer_accounts: z.boolean(),
                coupons: z.boolean(),
                loyalty: z.boolean(),
                reports: z.boolean(),
                announcements: z.boolean(),
            })
            .strict(),
        isActive: z.boolean(),
    })
    .strict();

async function updatePlan(
    request: Request,
    user: AuthenticatedUser,
    planId: number,
    environment: ApiEnvironment,
) {
    assertPlatformAdmin(user);
    const input = parseInput(planInputSchema, await readJson(request));
    if (input.isActive && input.annualPriceCents < 1)
        throw new ApiError(
            422,
            "Satışa açık bir paketin yıllık fiyatı sıfırdan büyük olmalıdır.",
            "plan_price_required",
        );
    const now = nowIso(environment);
    return withTransaction(environment, async (transaction) => {
        const exists = await transaction.execute({
            sql: "SELECT id,code FROM subscription_plans WHERE id=?",
            args: [planId],
        });
        if (!exists.rows[0])
            throw new ApiError(404, "Paket bulunamadı.", "plan_not_found");
        await transaction.execute({
            sql: `UPDATE subscription_plans SET name=?,annual_price_cents=?,max_tables=?,max_branches=?,features_json=?,is_active=?,updated_at=? WHERE id=?`,
            args: [
                input.name,
                input.annualPriceCents,
                input.maxTables,
                input.maxBranches,
                JSON.stringify(input.features),
                input.isActive ? 1 : 0,
                now,
                planId,
            ],
        });
        await transaction.execute({
            sql: "INSERT INTO activity_logs (user_id,action,subject_type,subject_id,metadata_json,created_at) VALUES (?,'subscription_plan.updated','SubscriptionPlan',?,?,?)",
            args: [user.id, planId, JSON.stringify(input), now],
        });
        return { ok: true, plan: { id: planId, ...input } };
    });
}

async function listRequests(
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    assertPlatformAdmin(user);
    const database = await getDatabase(environment);
    const result = await database.execute({
        sql: `SELECT r.id,r.cafe_id,r.subscription_plan_id,r.plan_name_snapshot,r.annual_price_snapshot_cents,
                    r.max_tables_snapshot,r.max_branches_snapshot,r.features_snapshot_json,r.status,r.requested_at,
                    r.reviewed_at,r.review_note,c.name AS cafe_name,u.name AS requester_name,u.email AS requester_email
              FROM cafe_subscription_requests r JOIN cafes c ON c.id=r.cafe_id
              LEFT JOIN users u ON u.id=r.requested_by WHERE r.status='pending' ORDER BY r.requested_at,r.id`,
    });
    return {
        requests: result.rows.map((row) => ({
            id: Number(row.id),
            cafeId: Number(row.cafe_id),
            cafeName: rowString(row as Row, "cafe_name"),
            planId: Number(row.subscription_plan_id),
            planName: rowString(row as Row, "plan_name_snapshot"),
            annualPriceCents: Number(row.annual_price_snapshot_cents),
            maxTables: Number(row.max_tables_snapshot),
            maxBranches:
                row.max_branches_snapshot === null
                    ? null
                    : Number(row.max_branches_snapshot),
            features: parseSubscriptionFeatures(row.features_snapshot_json),
            status: rowString(row as Row, "status"),
            requestedAt: rowString(row as Row, "requested_at"),
            requester: row.requester_name
                ? {
                      name: String(row.requester_name),
                      email: String(row.requester_email),
                  }
                : null,
        })),
    };
}

async function listCafeSubscriptions(
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    assertPlatformAdmin(user);
    const database = await getDatabase(environment);
    const now = nowIso(environment);
    const [cafes, subscriptions, pendingRequests] = await Promise.all([
        database.execute({
            sql: "SELECT id,name,status FROM cafes WHERE deleted_at IS NULL ORDER BY name,id",
        }),
        database.execute({
            sql: "SELECT * FROM cafe_subscriptions WHERE status='active' ORDER BY cafe_id,starts_at,id",
        }),
        database.execute({
            sql: "SELECT cafe_id,id,plan_name_snapshot,requested_at FROM cafe_subscription_requests WHERE status='pending' ORDER BY requested_at,id",
        }),
    ]);
    const periodsByCafe = new Map<number, Row[]>();
    for (const subscription of subscriptions.rows) {
        const row = subscription as Row;
        const cafeId = Number(row.cafe_id);
        const rows = periodsByCafe.get(cafeId) ?? [];
        rows.push(row);
        periodsByCafe.set(cafeId, rows);
    }
    const requestsByCafe = new Map<number, Row>();
    for (const request of pendingRequests.rows)
        requestsByCafe.set(Number(request.cafe_id), request as Row);

    return {
        cafes: cafes.rows.map((cafe) => {
            const cafeId = Number(cafe.id);
            const periods = periodsByCafe.get(cafeId) ?? [];
            const current = periods.find(
                (row) => periodKind(row, now) === "current",
            );
            const scheduled = periods.find(
                (row) => periodKind(row, now) === "scheduled",
            );
            const latestExpired = [...periods]
                .filter((row) => periodKind(row, now) === "expired")
                .sort(
                    (left, right) =>
                        toMillis(right.ends_at) - toMillis(left.ends_at),
                )[0];
            const chosen = current ?? scheduled ?? latestExpired ?? null;
            const kind = chosen ? periodKind(chosen, now) : null;
            const pending = requestsByCafe.get(cafeId);
            return {
                id: cafeId,
                name: String(cafe.name),
                status: String(cafe.status),
                subscription: chosen ? asSubscription(chosen) : null,
                subscriptionState: kind,
                daysRemaining:
                    chosen && kind !== "expired"
                        ? daysRemaining(String(chosen.ends_at), now)
                        : 0,
                pendingRequest: pending
                    ? {
                          id: Number(pending.id),
                          planName: String(pending.plan_name_snapshot),
                          requestedAt: String(pending.requested_at),
                      }
                    : null,
            };
        }),
    };
}

function addAnnualPeriod(startValue: string): string {
    const start = new Date(startValue);
    const nextYear = start.getUTCFullYear() + 1;
    const month = start.getUTCMonth();
    const lastDay = new Date(Date.UTC(nextYear, month + 1, 0)).getUTCDate();
    return new Date(
        Date.UTC(
            nextYear,
            month,
            Math.min(start.getUTCDate(), lastDay),
            start.getUTCHours(),
            start.getUTCMinutes(),
            start.getUTCSeconds(),
            start.getUTCMilliseconds(),
        ),
    ).toISOString();
}

async function cafeUsage(transaction: Transaction, cafeId: number) {
    const result = await transaction.execute({
        sql: `SELECT
            (SELECT COUNT(*) FROM dining_tables t JOIN branches b ON b.id=t.branch_id WHERE b.cafe_id=? AND b.deleted_at IS NULL AND t.deleted_at IS NULL) AS tables,
            (SELECT COUNT(*) FROM branches WHERE cafe_id=? AND deleted_at IS NULL) AS branches`,
        args: [cafeId, cafeId],
    });
    return {
        tables: Number(result.rows[0]?.tables ?? 0),
        branches: Number(result.rows[0]?.branches ?? 0),
    };
}

function assertWithinPlanLimits(
    usage: { tables: number; branches: number },
    plan: { maxTables: number; maxBranches: number | null },
): void {
    if (usage.tables > plan.maxTables)
        throw new ApiError(
            409,
            "Kafenin masa sayısı seçilen paketin sınırını aşıyor.",
            "plan_table_limit_exceeded",
        );
    if (plan.maxBranches !== null && usage.branches > plan.maxBranches)
        throw new ApiError(
            409,
            "Kafenin şube sayısı seçilen paketin sınırını aşıyor.",
            "plan_branch_limit_exceeded",
        );
}

async function approveRequest(
    requestId: number,
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    assertPlatformAdmin(user);
    parseInput(
        z.object({ paymentReceived: z.literal(true) }),
        await readJson(request),
    );
    const now = nowIso(environment);
    return withTransaction(environment, async (transaction) => {
        const requestResult = await transaction.execute({
            sql: "SELECT * FROM cafe_subscription_requests WHERE id=?",
            args: [requestId],
        });
        const row = requestResult.rows[0] as Row | undefined;
        if (!row)
            throw new ApiError(
                404,
                "Paket talebi bulunamadı.",
                "request_not_found",
            );
        if (row.status !== "pending" || Number(row.pending_cafe_id) < 1)
            throw new ApiError(
                409,
                "Bu paket talebi daha önce sonuçlandırılmış.",
                "request_already_reviewed",
            );
        const cafeId = Number(row.cafe_id);
        const usage = await cafeUsage(transaction, cafeId);
        assertWithinPlanLimits(usage, {
            maxTables: Number(row.max_tables_snapshot),
            maxBranches:
                row.max_branches_snapshot === null
                    ? null
                    : Number(row.max_branches_snapshot),
        });
        const latest = await transaction.execute({
            sql: "SELECT ends_at FROM cafe_subscriptions WHERE cafe_id=? AND status='active' AND ends_at>? ORDER BY ends_at DESC,id DESC LIMIT 1",
            args: [cafeId, now],
        });
        const startsAt = latest.rows[0] ? String(latest.rows[0].ends_at) : now;
        const endsAt = addAnnualPeriod(startsAt);
        await transaction.execute({
            sql: "UPDATE cafe_subscriptions SET status='replaced',updated_at=? WHERE cafe_id=? AND status='active' AND ends_at<=?",
            args: [now, cafeId, now],
        });
        const inserted = await transaction.execute({
            sql: `INSERT INTO cafe_subscriptions (cafe_id,subscription_plan_id,activated_by,plan_name_snapshot,status,starts_at,ends_at,
                    annual_price_snapshot_cents,max_tables_snapshot,max_branches_snapshot,features_snapshot_json,created_at,updated_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id`,
            args: [
                cafeId,
                Number(row.subscription_plan_id),
                user.id,
                String(row.plan_name_snapshot),
                "active",
                startsAt,
                endsAt,
                Number(row.annual_price_snapshot_cents),
                Number(row.max_tables_snapshot),
                row.max_branches_snapshot === null
                    ? null
                    : Number(row.max_branches_snapshot),
                String(row.features_snapshot_json),
                now,
                now,
            ],
        });
        const subscriptionId = Number(inserted.rows[0]?.id);
        await transaction.execute({
            sql: "UPDATE cafe_subscription_requests SET status='approved',pending_cafe_id=NULL,reviewed_by=?,reviewed_at=?,cafe_subscription_id=?,updated_at=? WHERE id=? AND status='pending'",
            args: [user.id, now, subscriptionId, now, requestId],
        });
        await transaction.execute({
            sql: "INSERT INTO activity_logs (cafe_id,user_id,action,subject_type,subject_id,metadata_json,ip_address,created_at) VALUES (?,?, 'subscription_request.approved','CafeSubscriptionRequest',?,?,?,?)",
            args: [
                cafeId,
                user.id,
                requestId,
                JSON.stringify({
                    subscriptionId,
                    startsAt,
                    endsAt,
                    paidManually: true,
                }),
                environment.context?.ip ?? null,
                now,
            ],
        });
        return {
            ok: true,
            subscription: {
                id: subscriptionId,
                cafeId,
                planId: Number(row.subscription_plan_id),
                planName: String(row.plan_name_snapshot),
                startsAt,
                endsAt,
                status: "active",
            },
        };
    });
}

async function rejectRequest(
    requestId: number,
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    assertPlatformAdmin(user);
    const input = parseInput(
        z.object({ note: z.string().trim().min(3).max(250) }),
        await readJson(request),
    );
    const now = nowIso(environment);
    return withTransaction(environment, async (transaction) => {
        const result = await transaction.execute({
            sql: "SELECT cafe_id,status FROM cafe_subscription_requests WHERE id=?",
            args: [requestId],
        });
        const row = result.rows[0];
        if (!row)
            throw new ApiError(
                404,
                "Paket talebi bulunamadı.",
                "request_not_found",
            );
        if (row.status !== "pending")
            throw new ApiError(
                409,
                "Bu paket talebi daha önce sonuçlandırılmış.",
                "request_already_reviewed",
            );
        await transaction.execute({
            sql: "UPDATE cafe_subscription_requests SET status='rejected',pending_cafe_id=NULL,reviewed_by=?,reviewed_at=?,review_note=?,updated_at=? WHERE id=? AND status='pending'",
            args: [user.id, now, input.note, now, requestId],
        });
        await transaction.execute({
            sql: "INSERT INTO activity_logs (cafe_id,user_id,action,subject_type,subject_id,metadata_json,ip_address,created_at) VALUES (?,?, 'subscription_request.rejected','CafeSubscriptionRequest',?,?,?,?)",
            args: [
                Number(row.cafe_id),
                user.id,
                requestId,
                JSON.stringify({ note: input.note }),
                environment.context?.ip ?? null,
                now,
            ],
        });
        return { ok: true };
    });
}

async function overrideSubscription(
    cafeId: number,
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    assertPlatformAdmin(user);
    const input = parseInput(
        z
            .object({
                subscriptionId: z
                    .number()
                    .int()
                    .positive()
                    .nullable()
                    .optional(),
                planId: z.number().int().positive(),
                endsOn: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
                reason: z.string().trim().min(3).max(250),
            })
            .strict(),
        await readJson(request),
    );
    const endDate = new Date(`${input.endsOn}T20:59:59.999Z`);
    if (
        !Number.isFinite(endDate.getTime()) ||
        endDate.toISOString().slice(0, 10) !== input.endsOn
    )
        throw new ApiError(
            422,
            "Bitiş tarihi geçerli değil.",
            "invalid_end_date",
        );
    const endsAt = endDate.toISOString();
    const now = nowIso(environment);
    return withTransaction(environment, async (transaction) => {
        const cafeResult = await transaction.execute({
            sql: "SELECT id FROM cafes WHERE id=? AND deleted_at IS NULL",
            args: [cafeId],
        });
        if (!cafeResult.rows[0])
            throw new ApiError(404, "Kafe bulunamadı.", "cafe_not_found");
        const planResult = await transaction.execute({
            sql: "SELECT * FROM subscription_plans WHERE id=?",
            args: [input.planId],
        });
        const plan = planResult.rows[0] as Row | undefined;
        if (!plan)
            throw new ApiError(404, "Paket bulunamadı.", "plan_not_found");
        let period: Row | undefined;
        if (input.subscriptionId) {
            const periodResult = await transaction.execute({
                sql: "SELECT * FROM cafe_subscriptions WHERE id=? AND cafe_id=?",
                args: [input.subscriptionId, cafeId],
            });
            period = periodResult.rows[0] as Row | undefined;
            if (!period)
                throw new ApiError(
                    404,
                    "Bu kafeye ait abonelik bulunamadı.",
                    "subscription_not_found",
                );
        }
        const kind = period ? periodKind(period, now) : null;
        if (period && !kind)
            throw new ApiError(
                409,
                "Yalnızca güncel, sıradaki veya en son süresi dolan paket düzenlenebilir.",
                "subscription_not_editable",
            );
        const pending = await transaction.execute({
            sql: "SELECT id FROM cafe_subscription_requests WHERE pending_cafe_id=? LIMIT 1",
            args: [cafeId],
        });
        if (pending.rows.length)
            throw new ApiError(
                409,
                "Önce bekleyen paket talebini sonuçlandırın.",
                "subscription_request_pending",
            );
        if (
            Number(plan.is_active) !== 1 &&
            Number(plan.id) !== Number(period?.subscription_plan_id)
        )
            throw new ApiError(
                422,
                "Yeni paket satışa açık olmalıdır.",
                "plan_unavailable",
            );
        if (Number(plan.annual_price_cents) < 1)
            throw new ApiError(
                422,
                "Paket fiyatı sıfırdan büyük olmalıdır.",
                "plan_price_required",
            );

        if (!period) {
            const existing = await transaction.execute({
                sql: "SELECT id FROM cafe_subscriptions WHERE cafe_id=? AND status='active' AND ends_at>? LIMIT 1",
                args: [cafeId, now],
            });
            if (existing.rows.length)
                throw new ApiError(
                    409,
                    "Mevcut paketi seçerek düzenleyin.",
                    "subscription_id_required",
                );
        }
        const startsAt =
            kind === "scheduled" && period ? String(period.starts_at) : now;
        if (toMillis(endsAt) <= toMillis(startsAt))
            throw new ApiError(
                422,
                "Bitiş tarihi başlangıç tarihinden sonra olmalıdır.",
                "invalid_subscription_period",
            );
        const usage = await cafeUsage(transaction, cafeId);
        assertWithinPlanLimits(usage, {
            maxTables: Number(plan.max_tables),
            maxBranches:
                plan.max_branches === null ? null : Number(plan.max_branches),
        });

        const oldSnapshot = period
            ? {
                  id: Number(period.id),
                  planName: String(period.plan_name_snapshot),
                  startsAt: String(period.starts_at),
                  endsAt: String(period.ends_at),
              }
            : null;
        if (period) {
            await transaction.execute({
                sql: "UPDATE cafe_subscriptions SET status='replaced',ends_at=CASE WHEN ?='current' THEN ? ELSE ends_at END,updated_at=? WHERE id=?",
                args: [kind, now, now, Number(period.id)],
            });
        }
        const features = parseSubscriptionFeatures(plan.features_json);
        const created = await transaction.execute({
            sql: `INSERT INTO cafe_subscriptions (cafe_id,subscription_plan_id,activated_by,plan_name_snapshot,status,starts_at,ends_at,
                    annual_price_snapshot_cents,max_tables_snapshot,max_branches_snapshot,features_snapshot_json,created_at,updated_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id`,
            args: [
                cafeId,
                input.planId,
                user.id,
                String(plan.name),
                "active",
                startsAt,
                endsAt,
                Number(plan.annual_price_cents),
                Number(plan.max_tables),
                plan.max_branches === null ? null : Number(plan.max_branches),
                JSON.stringify(features),
                now,
                now,
            ],
        });
        const replacementId = Number(created.rows[0]?.id);
        let previousEnd = endsAt;
        const later = period
            ? await transaction.execute({
                  sql: `SELECT id,starts_at,ends_at FROM cafe_subscriptions WHERE cafe_id=? AND status='active'
                        AND id<>? AND starts_at>? AND ends_at>? ORDER BY starts_at,id`,
                  args: [cafeId, replacementId, String(period.starts_at), now],
              })
            : { rows: [] };
        const shifted = [];
        for (const laterPeriod of later.rows) {
            const duration = Math.max(
                1,
                toMillis(laterPeriod.ends_at) - toMillis(laterPeriod.starts_at),
            );
            const nextStart = previousEnd;
            const nextEnd = new Date(
                toMillis(nextStart) + duration,
            ).toISOString();
            await transaction.execute({
                sql: "UPDATE cafe_subscriptions SET starts_at=?,ends_at=?,updated_at=? WHERE id=? AND cafe_id=?",
                args: [nextStart, nextEnd, now, Number(laterPeriod.id), cafeId],
            });
            shifted.push({
                id: Number(laterPeriod.id),
                startsAt: nextStart,
                endsAt: nextEnd,
            });
            previousEnd = nextEnd;
        }
        await transaction.execute({
            sql: "INSERT INTO activity_logs (cafe_id,user_id,action,subject_type,subject_id,metadata_json,ip_address,created_at) VALUES (?,?, 'subscription.admin_overridden','CafeSubscription',?,?,?,?)",
            args: [
                cafeId,
                user.id,
                replacementId,
                JSON.stringify({
                    reason: input.reason,
                    old: oldSnapshot,
                    new: { planName: String(plan.name), startsAt, endsAt },
                    shifted,
                }),
                environment.context?.ip ?? null,
                now,
            ],
        });
        return {
            ok: true,
            subscription: {
                id: replacementId,
                cafeId,
                planName: String(plan.name),
                startsAt,
                endsAt,
                status: "active",
            },
        };
    });
}

export async function handleSubscriptionRequest(
    request: Request,
    path: string,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
): Promise<Response | null> {
    const method = request.method.toUpperCase();
    if (method === "GET" && path === "/api/manage/subscription")
        return json(await ownerSubscription(user, environment));
    if (method === "POST" && path === "/api/manage/subscription-requests")
        return json(await requestSubscription(request, user, environment), 201);
    if (method === "GET" && path === "/api/platform/subscription-plans")
        return json(await listPlans(user, environment));

    let match = /^\/api\/platform\/subscription-plans\/(\d+)$/.exec(path);
    if (method === "PATCH" && match)
        return json(
            await updatePlan(request, user, Number(match[1]), environment),
        );
    if (method === "GET" && path === "/api/platform/subscription-requests")
        return json(await listRequests(user, environment));
    if (method === "GET" && path === "/api/platform/subscription-cafes")
        return json(await listCafeSubscriptions(user, environment));
    match = /^\/api\/platform\/subscription-requests\/(\d+)\/approve$/.exec(
        path,
    );
    if (method === "POST" && match)
        return json(
            await approveRequest(Number(match[1]), request, user, environment),
        );
    match = /^\/api\/platform\/subscription-requests\/(\d+)\/reject$/.exec(
        path,
    );
    if (method === "POST" && match)
        return json(
            await rejectRequest(Number(match[1]), request, user, environment),
        );
    match = /^\/api\/platform\/cafes\/(\d+)\/subscription$/.exec(path);
    if (method === "PATCH" && match)
        return json(
            await overrideSubscription(
                Number(match[1]),
                request,
                user,
                environment,
            ),
        );
    return null;
}

function json(value: unknown, status = 200): Response {
    return new Response(JSON.stringify(value), {
        status,
        headers: {
            "content-type": "application/json; charset=utf-8",
            "cache-control": "private, no-store",
        },
    });
}
