import type { Client } from "@libsql/client";
import { z } from "zod";
import { requireRole } from "./auth.js";
import {
    ApiError,
    getDatabase,
    jsonResponse,
    nowIso,
    readJson,
    type ApiEnvironment,
    type AuthenticatedUser,
} from "./http.js";
import { requireCafeFeature } from "./subscriptions.js";

type OwnerFeature = "announcements" | "coupons" | "reports";

function ownerCafeId(user: AuthenticatedUser): number {
    requireRole(user, ["cafe_owner"]);
    if (!user.cafeId)
        throw new ApiError(
            403,
            "Bu hesap bir kafeye bağlı değil.",
            "forbidden",
        );
    return user.cafeId;
}

async function authorizeFeature(
    user: AuthenticatedUser,
    environment: ApiEnvironment,
    feature: OwnerFeature,
): Promise<{ database: Client; cafeId: number }> {
    const cafeId = ownerCafeId(user);
    const database = await getDatabase(environment);
    await requireCafeFeature(database, cafeId, feature, nowIso(environment));
    return { database, cafeId };
}

const isoDateTime = z.string().datetime({ offset: true }).nullable();

function canonicalDateTime(value: string | null): string | null {
    return value ? new Date(value).toISOString() : null;
}

function isDateRangeOrdered(start: string | null, end: string | null): boolean {
    return !start || !end || Date.parse(end) >= Date.parse(start);
}

const announcementInput = z
    .object({
        type: z.enum(["announcement", "promotion"]).default("announcement"),
        title: z.string().trim().min(2).max(100),
        body: z.string().trim().max(1000).nullable().default(null),
        branchId: z.number().int().positive().nullable().default(null),
        startsAt: isoDateTime.default(null),
        endsAt: isoDateTime.default(null),
        isActive: z.boolean().default(true),
    })
    .strict()
    .refine((input) => isDateRangeOrdered(input.startsAt, input.endsAt), {
        message: "Bitiş zamanı başlangıç zamanından önce olamaz.",
    });

async function validateBranch(
    database: Client,
    cafeId: number,
    branchId: number | null,
) {
    if (branchId === null) return;
    const result = await database.execute({
        sql: "SELECT id FROM branches WHERE id=? AND cafe_id=? AND deleted_at IS NULL",
        args: [branchId, cafeId],
    });
    if (!result.rows[0])
        throw new ApiError(422, "Şube bu kafeye ait değil.", "invalid_branch");
}

function validated<T>(schema: z.ZodType<T>, input: unknown): T {
    const result = schema.safeParse(input);
    if (!result.success)
        throw new ApiError(
            422,
            result.error.issues[0]?.message ?? "Bilgileri kontrol edin.",
            "validation_failed",
            result.error.flatten(),
        );
    return result.data;
}

async function listAnnouncements(database: Client, cafeId: number) {
    const result = await database.execute({
        sql: `SELECT a.id,a.branch_id,a.type,a.title,a.body,a.starts_at,a.ends_at,a.is_active,a.created_at,
                     b.name AS branch_name
              FROM announcements a LEFT JOIN branches b ON b.id=a.branch_id
              WHERE a.cafe_id=? ORDER BY a.created_at DESC,a.id DESC LIMIT 100`,
        args: [cafeId],
    });
    return {
        announcements: result.rows.map((row) => ({
            id: Number(row.id),
            branchId: row.branch_id === null ? null : Number(row.branch_id),
            branchName: row.branch_name ? String(row.branch_name) : null,
            type: String(row.type),
            title: String(row.title),
            body: row.body === null ? null : String(row.body),
            startsAt: row.starts_at === null ? null : String(row.starts_at),
            endsAt: row.ends_at === null ? null : String(row.ends_at),
            isActive: Number(row.is_active) === 1,
            createdAt: String(row.created_at),
        })),
    };
}

async function saveAnnouncement(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
    announcementId?: number,
) {
    const { database, cafeId } = await authorizeFeature(
        user,
        environment,
        "announcements",
    );
    const input = validated(announcementInput, await readJson(request));
    await validateBranch(database, cafeId, input.branchId);
    const now = nowIso(environment);
    if (announcementId === undefined) {
        const inserted = await database.execute({
            sql: `INSERT INTO announcements (cafe_id,branch_id,type,title,body,starts_at,ends_at,is_active,created_by,created_at,updated_at)
                  VALUES (?,?,?,?,?,?,?,?,?,?,?) RETURNING id`,
            args: [
                cafeId,
                input.branchId,
                input.type,
                input.title,
                input.body || null,
                canonicalDateTime(input.startsAt),
                canonicalDateTime(input.endsAt),
                input.isActive ? 1 : 0,
                user.id,
                now,
                now,
            ],
        });
        const id = Number(inserted.rows[0]?.id);
        await database.execute({
            sql: "INSERT INTO activity_logs (cafe_id,user_id,action,subject_type,subject_id,metadata_json,created_at) VALUES (?,?,'announcement.created','Announcement',?,?,?)",
            args: [
                cafeId,
                user.id,
                id,
                JSON.stringify({ title: input.title }),
                now,
            ],
        });
        return jsonResponse({ ok: true, id }, 201);
    }

    const updated = await database.execute({
        sql: `UPDATE announcements SET branch_id=?,type=?,title=?,body=?,starts_at=?,ends_at=?,is_active=?,updated_at=?
              WHERE id=? AND cafe_id=?`,
        args: [
            input.branchId,
            input.type,
            input.title,
            input.body || null,
            canonicalDateTime(input.startsAt),
            canonicalDateTime(input.endsAt),
            input.isActive ? 1 : 0,
            now,
            announcementId,
            cafeId,
        ],
    });
    if (updated.rowsAffected !== 1)
        throw new ApiError(404, "Duyuru bulunamadı.", "announcement_not_found");
    await database.execute({
        sql: "INSERT INTO activity_logs (cafe_id,user_id,action,subject_type,subject_id,metadata_json,created_at) VALUES (?,?,'announcement.updated','Announcement',?,?,?)",
        args: [
            cafeId,
            user.id,
            announcementId,
            JSON.stringify({ title: input.title }),
            now,
        ],
    });
    return jsonResponse({ ok: true, id: announcementId });
}

const couponInput = z
    .object({
        code: z
            .string()
            .trim()
            .toUpperCase()
            .min(2)
            .max(40)
            .regex(/^[A-Z0-9_-]+$/),
        type: z.enum(["percent", "fixed"]),
        value: z.number().int().min(1).max(100_000_000),
        minSubtotalCents: z.number().int().min(0).max(100_000_000),
        usageLimit: z.number().int().positive().max(1_000_000).nullable(),
        startsAt: isoDateTime,
        endsAt: isoDateTime,
        isActive: z.boolean(),
    })
    .strict()
    .refine((input) => input.type !== "percent" || input.value <= 100, {
        message: "Yüzde indirimi 1 ile 100 arasında olmalıdır.",
    })
    .refine((input) => isDateRangeOrdered(input.startsAt, input.endsAt), {
        message: "Bitiş zamanı başlangıç zamanından önce olamaz.",
    });

type CouponInput = z.infer<typeof couponInput>;

async function listCoupons(database: Client, cafeId: number) {
    const result = await database.execute({
        sql: `SELECT id,code,type,value_cents,value_basis_points,min_subtotal_cents,
                     starts_at,ends_at,usage_limit,used_count,is_active,created_at
              FROM coupons WHERE cafe_id=? ORDER BY created_at DESC,id DESC LIMIT 200`,
        args: [cafeId],
    });
    return {
        coupons: result.rows.map((row) => ({
            id: Number(row.id),
            code: String(row.code),
            type: String(row.type),
            value:
                String(row.type) === "percent"
                    ? Number(row.value_basis_points) / 100
                    : Number(row.value_cents),
            minSubtotalCents: Number(row.min_subtotal_cents),
            startsAt: row.starts_at === null ? null : String(row.starts_at),
            endsAt: row.ends_at === null ? null : String(row.ends_at),
            usageLimit:
                row.usage_limit === null ? null : Number(row.usage_limit),
            usedCount: Number(row.used_count),
            isActive: Number(row.is_active) === 1,
            createdAt: String(row.created_at),
        })),
    };
}

async function saveCoupon(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
    couponId?: number,
) {
    const { database, cafeId } = await authorizeFeature(
        user,
        environment,
        "coupons",
    );
    const input: CouponInput = validated(couponInput, await readJson(request));
    const now = nowIso(environment);
    const valueCents = input.type === "fixed" ? input.value : 0;
    const valueBasisPoints = input.type === "percent" ? input.value * 100 : 0;
    try {
        if (couponId === undefined) {
            const inserted = await database.execute({
                sql: `INSERT INTO coupons (cafe_id,code,type,value_cents,value_basis_points,min_subtotal_cents,
                        starts_at,ends_at,usage_limit,is_active,created_at,updated_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id`,
                args: [
                    cafeId,
                    input.code,
                    input.type,
                    valueCents,
                    valueBasisPoints,
                    input.minSubtotalCents,
                    canonicalDateTime(input.startsAt),
                    canonicalDateTime(input.endsAt),
                    input.usageLimit,
                    input.isActive ? 1 : 0,
                    now,
                    now,
                ],
            });
            const id = Number(inserted.rows[0]?.id);
            await database.execute({
                sql: "INSERT INTO activity_logs (cafe_id,user_id,action,subject_type,subject_id,metadata_json,created_at) VALUES (?,?,'coupon.created','Coupon',?,?,?)",
                args: [
                    cafeId,
                    user.id,
                    id,
                    JSON.stringify({ code: input.code }),
                    now,
                ],
            });
            return jsonResponse({ ok: true, id }, 201);
        }

        const updated = await database.execute({
            sql: `UPDATE coupons SET code=?,type=?,value_cents=?,value_basis_points=?,min_subtotal_cents=?,
                        starts_at=?,ends_at=?,usage_limit=?,is_active=?,updated_at=?
                  WHERE id=? AND cafe_id=?`,
            args: [
                input.code,
                input.type,
                valueCents,
                valueBasisPoints,
                input.minSubtotalCents,
                canonicalDateTime(input.startsAt),
                canonicalDateTime(input.endsAt),
                input.usageLimit,
                input.isActive ? 1 : 0,
                now,
                couponId,
                cafeId,
            ],
        });
        if (updated.rowsAffected !== 1)
            throw new ApiError(404, "Kupon bulunamadı.", "coupon_not_found");
        await database.execute({
            sql: "INSERT INTO activity_logs (cafe_id,user_id,action,subject_type,subject_id,metadata_json,created_at) VALUES (?,?,'coupon.updated','Coupon',?,?,?)",
            args: [
                cafeId,
                user.id,
                couponId,
                JSON.stringify({ code: input.code }),
                now,
            ],
        });
        return jsonResponse({ ok: true, id: couponId });
    } catch (error) {
        if (
            String(error).includes(
                "UNIQUE constraint failed: coupons.cafe_id, coupons.code",
            )
        )
            throw new ApiError(
                409,
                "Bu kupon kodu zaten kullanılıyor.",
                "coupon_code_exists",
            );
        throw error;
    }
}

function dateValue(value: string | null, fallback: string): string {
    if (!value) return fallback;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value))
        throw new ApiError(
            422,
            "Tarih biçimi YYYY-AA-GG olmalıdır.",
            "invalid_report_date",
        );
    const parsed = new Date(`${value}T00:00:00.000Z`);
    if (
        Number.isNaN(parsed.valueOf()) ||
        parsed.toISOString().slice(0, 10) !== value
    )
        throw new ApiError(
            422,
            "Geçerli bir tarih girin.",
            "invalid_report_date",
        );
    return value;
}

function istanbulDate(value: Date): string {
    return new Intl.DateTimeFormat("sv-SE", {
        timeZone: "Europe/Istanbul",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(value);
}

function shiftCalendarDate(value: string, days: number): string {
    const date = new Date(`${value}T00:00:00.000Z`);
    date.setUTCDate(date.getUTCDate() + days);
    return date.toISOString().slice(0, 10);
}

function istanbulMidnightUtc(value: string): string {
    const [year, month, day] = value.split("-").map(Number);
    const wallClock = Date.UTC(year ?? 0, (month ?? 1) - 1, day ?? 1);
    const formatter = new Intl.DateTimeFormat("en-CA", {
        timeZone: "Europe/Istanbul",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hourCycle: "h23",
    });
    let instant = wallClock;
    for (let attempt = 0; attempt < 3; attempt += 1) {
        const parts = Object.fromEntries(
            formatter
                .formatToParts(new Date(instant))
                .map((part) => [part.type, part.value]),
        );
        const localAsUtc = Date.UTC(
            Number(parts.year),
            Number(parts.month) - 1,
            Number(parts.day),
            Number(parts.hour),
            Number(parts.minute),
            Number(parts.second),
        );
        instant += wallClock - localAsUtc;
    }
    return new Date(instant).toISOString();
}

async function getReports(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    const { database, cafeId } = await authorizeFeature(
        user,
        environment,
        "reports",
    );
    const now = new Date(nowIso(environment));
    const today = istanbulDate(now);
    const defaultFrom = shiftCalendarDate(today, -29);
    const params = new URL(request.url).searchParams;
    const from = dateValue(params.get("from"), defaultFrom);
    const to = dateValue(params.get("to"), today);
    const days =
        (Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) /
            86_400_000 +
        1;
    if (days < 1 || days > 366)
        throw new ApiError(
            422,
            "Rapor aralığı 1 ile 366 gün arasında olmalıdır.",
            "invalid_report_range",
        );
    const fromUtc = istanbulMidnightUtc(from);
    const toUtcExclusive = istanbulMidnightUtc(shiftCalendarDate(to, 1));

    const [summary, daily, products, cancelled] = await Promise.all([
        database.execute({
            sql: `SELECT COUNT(*) AS order_count,COALESCE(SUM(total_cents),0) AS revenue_cents
                  FROM orders WHERE cafe_id=? AND status='paid' AND paid_at IS NOT NULL
                  AND paid_at>=? AND paid_at<?`,
            args: [cafeId, fromUtc, toUtcExclusive],
        }),
        database.execute({
            sql: `SELECT date(paid_at,'+3 hours') AS day,COUNT(*) AS order_count,COALESCE(SUM(total_cents),0) AS revenue_cents
                  FROM orders WHERE cafe_id=? AND status='paid' AND paid_at IS NOT NULL
                  AND paid_at>=? AND paid_at<? GROUP BY date(paid_at,'+3 hours') ORDER BY day`,
            args: [cafeId, fromUtc, toUtcExclusive],
        }),
        database.execute({
            sql: `SELECT oi.name_snapshot AS name,SUM(oi.quantity) AS quantity,SUM(oi.line_total_cents) AS revenue_cents
                  FROM order_items oi JOIN orders o ON o.id=oi.order_id
                  WHERE o.cafe_id=? AND o.status='paid' AND o.paid_at IS NOT NULL
                  AND o.paid_at>=? AND o.paid_at<?
                  GROUP BY oi.name_snapshot ORDER BY quantity DESC,revenue_cents DESC LIMIT 10`,
            args: [cafeId, fromUtc, toUtcExclusive],
        }),
        database.execute({
            sql: "SELECT COUNT(*) AS count FROM orders WHERE cafe_id=? AND status='cancelled' AND cancelled_at IS NOT NULL AND cancelled_at>=? AND cancelled_at<?",
            args: [cafeId, fromUtc, toUtcExclusive],
        }),
    ]);
    const orderCount = Number(summary.rows[0]?.order_count ?? 0);
    const revenueCents = Number(summary.rows[0]?.revenue_cents ?? 0);
    return jsonResponse({
        from,
        to,
        summary: {
            paidOrders: orderCount,
            revenueCents,
            averageOrderCents: orderCount
                ? Math.round(revenueCents / orderCount)
                : 0,
            cancelledOrders: Number(cancelled.rows[0]?.count ?? 0),
        },
        daily: daily.rows.map((row) => ({
            day: String(row.day),
            paidOrders: Number(row.order_count),
            revenueCents: Number(row.revenue_cents),
        })),
        topProducts: products.rows.map((row) => ({
            name: String(row.name),
            quantity: Number(row.quantity),
            revenueCents: Number(row.revenue_cents),
        })),
    });
}

export async function handleOwnerFeatureRequest(
    request: Request,
    path: string,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
): Promise<Response | null> {
    if (
        !path.startsWith("/api/manage/reports") &&
        !path.startsWith("/api/manage/announcements") &&
        !path.startsWith("/api/manage/coupons")
    )
        return null;
    const method = request.method.toUpperCase();
    if (method === "GET" && path === "/api/manage/reports")
        return getReports(request, user, environment);
    if (method === "GET" && path === "/api/manage/announcements") {
        const { database, cafeId } = await authorizeFeature(
            user,
            environment,
            "announcements",
        );
        return jsonResponse(await listAnnouncements(database, cafeId));
    }
    if (method === "POST" && path === "/api/manage/announcements")
        return saveAnnouncement(request, user, environment);
    let match = /^\/api\/manage\/announcements\/(\d+)$/.exec(path);
    if (method === "PATCH" && match)
        return saveAnnouncement(request, user, environment, Number(match[1]));
    if (method === "GET" && path === "/api/manage/coupons") {
        const { database, cafeId } = await authorizeFeature(
            user,
            environment,
            "coupons",
        );
        return jsonResponse(await listCoupons(database, cafeId));
    }
    if (method === "POST" && path === "/api/manage/coupons")
        return saveCoupon(request, user, environment);
    match = /^\/api\/manage\/coupons\/(\d+)$/.exec(path);
    if (method === "PATCH" && match)
        return saveCoupon(request, user, environment, Number(match[1]));
    return null;
}
