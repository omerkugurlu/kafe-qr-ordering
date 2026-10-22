import { randomBytes } from "node:crypto";
import type { Client, Transaction } from "@libsql/client";
import { hash as hashPassword } from "bcryptjs";
import { z } from "zod";
import {
    currentUser,
    requireBranchAccess,
    requireCsrf,
    requireRole,
} from "./auth.js";
import { decryptQrToken, encryptQrToken } from "./qrTokens.js";
import {
    ApiError,
    checkSameOrigin,
    getDatabase,
    hash,
    newToken,
    nowIso,
    readJson,
    type ApiEnvironment,
    type AuthenticatedUser,
    withTransaction,
} from "./http.js";

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

function slugify(value: string): string {
    return (
        value
            .toLocaleLowerCase("tr-TR")
            .normalize("NFKD")
            .replace(/[\u0300-\u036f]/g, "")
            .replace(/ı/g, "i")
            .replace(/[^a-z0-9]+/g, "-")
            .replace(/^-|-$/g, "")
            .slice(0, 70) || "kafe"
    );
}

function assertCafeOwner(user: AuthenticatedUser) {
    requireRole(user, ["cafe_owner", "cafe_manager"]);
    if (!user.cafeId)
        throw new ApiError(
            403,
            "Bu hesap bir kafeye bağlı değil.",
            "forbidden",
        );
    return user.cafeId;
}

async function cafeForBranch(
    database: Client,
    user: AuthenticatedUser,
    branchId: number,
    environment: ApiEnvironment,
) {
    await requireBranchAccess(user, branchId, environment);
    const result = await database.execute({
        sql: "SELECT cafe_id,uses_shared_menu FROM branches WHERE id=? AND deleted_at IS NULL",
        args: [branchId],
    });
    if (!result.rows[0])
        throw new ApiError(404, "Şube bulunamadı.", "branch_not_found");
    const cafeId = Number(result.rows[0].cafe_id);
    if (user.role !== "platform_admin" && user.cafeId !== cafeId)
        throw new ApiError(
            403,
            "Bu şubeyi düzenleme yetkiniz yok.",
            "forbidden",
        );
    return { cafeId, shared: Number(result.rows[0].uses_shared_menu) === 1 };
}

async function ownerOverview(
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    const cafeId = assertCafeOwner(user);
    const database = await getDatabase(environment);
    const cafeResult = await database.execute({
        sql: "SELECT id,name,slug,status,registration_policy,loyalty_enabled,points_per_100 FROM cafes WHERE id=? AND deleted_at IS NULL",
        args: [cafeId],
    });
    const cafe = cafeResult.rows[0];
    if (!cafe) throw new ApiError(404, "Kafe bulunamadı.", "cafe_not_found");
    const branches = await database.execute({
        sql: `SELECT b.id,b.name,b.address,b.phone,b.uses_shared_menu,b.is_active,
                     (SELECT COUNT(*) FROM dining_tables t WHERE t.branch_id=b.id AND t.is_active=1 AND t.deleted_at IS NULL) AS active_tables,
                     (SELECT COUNT(*) FROM table_sessions s WHERE s.branch_id=b.id AND s.status='open') AS open_tables
              FROM branches b WHERE b.cafe_id=? AND b.deleted_at IS NULL ORDER BY b.id`,
        args: [cafeId],
    });
    const customerCount = await database.execute({
        sql: "SELECT COUNT(*) AS count FROM users WHERE cafe_id=? AND role='customer' AND is_active=1",
        args: [cafeId],
    });
    const today = new Intl.DateTimeFormat("sv-SE", {
        timeZone: "Europe/Istanbul",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    }).format(new Date(nowIso(environment)));
    const paymentResult = await database.execute({
        sql: `SELECT COALESCE(SUM(p.amount_cents),0) AS total FROM payments p JOIN table_sessions s ON s.id=p.table_session_id
              WHERE s.branch_id IN (SELECT id FROM branches WHERE cafe_id=?) AND p.voided_at IS NULL AND substr(p.recorded_at,1,10)=?`,
        args: [cafeId, today],
    });
    return {
        cafe: {
            id: Number(cafe.id),
            name: String(cafe.name),
            slug: String(cafe.slug),
            status: String(cafe.status),
            registrationPolicy: String(cafe.registration_policy),
            loyaltyEnabled: Number(cafe.loyalty_enabled) === 1,
            pointsPer100: Number(cafe.points_per_100),
        },
        branches: branches.rows.map((branch) => ({
            id: Number(branch.id),
            name: String(branch.name),
            address: branch.address ? String(branch.address) : null,
            phone: branch.phone ? String(branch.phone) : null,
            usesSharedMenu: Number(branch.uses_shared_menu) === 1,
            active: Number(branch.is_active) === 1,
            activeTables: Number(branch.active_tables),
            openTables: Number(branch.open_tables),
        })),
        metrics: {
            activeBranches: branches.rows.filter(
                (branch) => Number(branch.is_active) === 1,
            ).length,
            activeTables: branches.rows.reduce(
                (sum, branch) => sum + Number(branch.active_tables),
                0,
            ),
            openTables: branches.rows.reduce(
                (sum, branch) => sum + Number(branch.open_tables),
                0,
            ),
            activeCustomers: Number(customerCount.rows[0]?.count ?? 0),
            todayCollectedCents:
                user.role === "cafe_owner"
                    ? Number(paymentResult.rows[0]?.total ?? 0)
                    : 0,
        },
    };
}

const staffRoles = ["cafe_manager", "waiter", "kitchen"] as const;

async function assertStaffBranches(
    database: Client,
    cafeId: number,
    role: (typeof staffRoles)[number],
    branchIds: number[],
) {
    if (new Set(branchIds).size !== branchIds.length || branchIds.length > 50)
        throw new ApiError(
            422,
            "Şube seçimlerini kontrol edin.",
            "invalid_branches",
        );
    if (branchIds.length === 0 && role === "cafe_manager") return;
    if (branchIds.length === 0)
        throw new ApiError(
            422,
            "Çalışana en az bir şube atayın.",
            "branch_required",
        );
    const result = await database.execute({
        sql: `SELECT id FROM branches WHERE cafe_id=? AND is_active=1 AND deleted_at IS NULL AND id IN (${branchIds.map(() => "?").join(",")})`,
        args: [cafeId, ...branchIds],
    });
    if (result.rows.length !== branchIds.length)
        throw new ApiError(
            422,
            "Seçilen şubeler bu kafeye ait değil veya kapalı.",
            "invalid_branches",
        );
}

async function getStaff(user: AuthenticatedUser, environment: ApiEnvironment) {
    const cafeId = assertCafeOwner(user);
    const database = await getDatabase(environment);
    const staff = await database.execute({
        sql: "SELECT id,name,email,role,is_active,created_at FROM users WHERE cafe_id=? AND role IN ('cafe_manager','waiter','kitchen') ORDER BY role,name",
        args: [cafeId],
    });
    const output = [];
    for (const person of staff.rows) {
        const branches = await database.execute({
            sql: "SELECT b.id,b.name FROM branch_user bu JOIN branches b ON b.id=bu.branch_id WHERE bu.user_id=? AND b.cafe_id=? ORDER BY b.name",
            args: [Number(person.id), cafeId],
        });
        output.push({
            id: Number(person.id),
            name: String(person.name),
            email: String(person.email),
            role: String(person.role),
            active: Number(person.is_active) === 1,
            branches: branches.rows.map((branch) => ({
                id: Number(branch.id),
                name: String(branch.name),
            })),
            createdAt: String(person.created_at),
        });
    }
    return { staff: output };
}

function temporaryPassword() {
    return `Sk${randomBytes(9).toString("base64url")}9!`;
}

async function createStaff(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    const cafeId = assertCafeOwner(user);
    const input = parseInput(
        z.object({
            name: z.string().trim().min(2).max(120),
            email: z.string().trim().email().max(254),
            role: z.enum(staffRoles),
            branchIds: z.array(z.number().int().positive()).default([]),
        }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    await assertStaffBranches(database, cafeId, input.role, input.branchIds);
    const email = input.email.toLowerCase();
    const existing = await database.execute({
        sql: "SELECT id FROM users WHERE email=? COLLATE NOCASE",
        args: [email],
    });
    if (existing.rows.length)
        throw new ApiError(
            409,
            "Bu e-posta adresi zaten kullanılıyor.",
            "email_in_use",
        );
    const password = temporaryPassword();
    const now = nowIso(environment);
    const id = await withTransaction(environment, async (transaction) => {
        const inserted = await transaction.execute({
            sql: "INSERT INTO users (cafe_id,name,email,password_hash,role,is_active,created_at,updated_at) VALUES (?,?,?,?,?,1,?,?) RETURNING id",
            args: [
                cafeId,
                input.name,
                email,
                await hashPassword(password, 12),
                input.role,
                now,
                now,
            ],
        });
        const userId = Number(inserted.rows[0]?.id);
        for (const branchId of input.branchIds)
            await transaction.execute({
                sql: "INSERT INTO branch_user (branch_id,user_id,created_at) VALUES (?,?,?)",
                args: [branchId, userId, now],
            });
        await transaction.execute({
            sql: "INSERT INTO activity_logs (cafe_id,user_id,action,subject_type,subject_id,created_at) VALUES (?,?,'staff.created','User',?,?)",
            args: [cafeId, user.id, userId, now],
        });
        return userId;
    });
    return { id, email, temporaryPassword: password };
}

async function updateStaff(
    request: Request,
    user: AuthenticatedUser,
    staffId: number,
    environment: ApiEnvironment,
) {
    const cafeId = assertCafeOwner(user);
    const input = parseInput(
        z
            .object({
                name: z.string().trim().min(2).max(120).optional(),
                role: z.enum(staffRoles).optional(),
                active: z.boolean().optional(),
                branchIds: z.array(z.number().int().positive()).optional(),
            })
            .refine((value) => Object.keys(value).length > 0),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const current = await database.execute({
        sql: "SELECT id,role FROM users WHERE id=? AND cafe_id=? AND role IN ('cafe_manager','waiter','kitchen')",
        args: [staffId, cafeId],
    });
    if (!current.rows[0])
        throw new ApiError(404, "Çalışan bulunamadı.", "staff_not_found");
    const role =
        input.role ??
        (String(current.rows[0].role) as (typeof staffRoles)[number]);
    const branchIds =
        role === "cafe_manager"
            ? []
            : (input.branchIds ??
              (
                  await database.execute({
                      sql: "SELECT branch_id FROM branch_user WHERE user_id=?",
                      args: [staffId],
                  })
              ).rows.map((row) => Number(row.branch_id)));
    await assertStaffBranches(database, cafeId, role, branchIds);
    const now = nowIso(environment);
    await withTransaction(environment, async (transaction) => {
        await transaction.execute({
            sql: "UPDATE users SET name=COALESCE(?,name),role=?,is_active=COALESCE(?,is_active),updated_at=? WHERE id=?",
            args: [
                input.name ?? null,
                role,
                input.active === undefined ? null : input.active ? 1 : 0,
                now,
                staffId,
            ],
        });
        if (input.branchIds !== undefined || input.role !== undefined) {
            await transaction.execute({
                sql: "DELETE FROM branch_user WHERE user_id=?",
                args: [staffId],
            });
            for (const branchId of branchIds)
                await transaction.execute({
                    sql: "INSERT INTO branch_user (branch_id,user_id,created_at) VALUES (?,?,?)",
                    args: [branchId, staffId, now],
                });
        }
        if (input.active === false)
            await transaction.execute({
                sql: "DELETE FROM auth_sessions WHERE user_id=?",
                args: [staffId],
            });
        await transaction.execute({
            sql: "INSERT INTO activity_logs (cafe_id,user_id,action,subject_type,subject_id,metadata_json,created_at) VALUES (?,?,'staff.updated','User',?,?,?)",
            args: [
                cafeId,
                user.id,
                staffId,
                JSON.stringify({ role, active: input.active ?? null }),
                now,
            ],
        });
    });
    return { ok: true };
}

async function resetStaffPassword(
    user: AuthenticatedUser,
    staffId: number,
    environment: ApiEnvironment,
) {
    const cafeId = assertCafeOwner(user);
    const database = await getDatabase(environment);
    const staff = await database.execute({
        sql: "SELECT email FROM users WHERE id=? AND cafe_id=? AND role IN ('cafe_manager','waiter','kitchen')",
        args: [staffId, cafeId],
    });
    if (!staff.rows[0])
        throw new ApiError(404, "Çalışan bulunamadı.", "staff_not_found");
    const password = temporaryPassword();
    const now = nowIso(environment);
    await database.execute({
        sql: "UPDATE users SET password_hash=?,updated_at=? WHERE id=?",
        args: [await hashPassword(password, 12), now, staffId],
    });
    await database.execute({
        sql: "DELETE FROM auth_sessions WHERE user_id=?",
        args: [staffId],
    });
    await database.execute({
        sql: "INSERT INTO activity_logs (cafe_id,user_id,action,subject_type,subject_id,created_at) VALUES (?,?,'staff.password_reset','User',?,?)",
        args: [cafeId, user.id, staffId, now],
    });
    return { email: String(staff.rows[0].email), temporaryPassword: password };
}

async function updateSettings(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    const cafeId = assertCafeOwner(user);
    const input = parseInput(
        z
            .object({
                registrationPolicy: z
                    .enum(["guest", "optional", "required"])
                    .optional(),
                loyaltyEnabled: z.boolean().optional(),
                pointsPer100: z.number().int().min(0).max(100000).optional(),
            })
            .refine((value) => Object.keys(value).length > 0),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const now = nowIso(environment);
    await database.execute({
        sql: "UPDATE cafes SET registration_policy=COALESCE(?,registration_policy),loyalty_enabled=COALESCE(?,loyalty_enabled),points_per_100=COALESCE(?,points_per_100),updated_at=? WHERE id=?",
        args: [
            input.registrationPolicy ?? null,
            input.loyaltyEnabled === undefined
                ? null
                : input.loyaltyEnabled
                  ? 1
                  : 0,
            input.pointsPer100 ?? null,
            now,
            cafeId,
        ],
    });
    return { ok: true };
}

async function getLoyaltyCampaigns(
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    const cafeId = assertCafeOwner(user);
    const database = await getDatabase(environment);
    const result = await database.execute({
        sql: "SELECT id,name,type,threshold,points_required,reward_type,reward_cents,reward_basis_points,minimum_order_cents,is_active,starts_at,ends_at FROM loyalty_campaigns WHERE cafe_id=? ORDER BY is_active DESC,id DESC",
        args: [cafeId],
    });
    return {
        campaigns: result.rows.map((row) => ({
            id: Number(row.id),
            name: String(row.name),
            type: String(row.type),
            threshold: Number(row.threshold),
            pointsRequired: Number(row.points_required),
            rewardType: String(row.reward_type),
            rewardCents: Number(row.reward_cents),
            rewardBasisPoints: Number(row.reward_basis_points),
            minimumOrderCents: Number(row.minimum_order_cents),
            active: Number(row.is_active) === 1,
            startsAt: row.starts_at === null ? null : String(row.starts_at),
            endsAt: row.ends_at === null ? null : String(row.ends_at),
        })),
    };
}

async function createLoyaltyCampaign(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    const cafeId = assertCafeOwner(user);
    const input = parseInput(
        z
            .object({
                name: z.string().trim().min(2).max(120),
                type: z.enum(["order_count", "points"]),
                threshold: z.number().int().min(0).max(10000),
                pointsRequired: z.number().int().min(0).max(1000000),
                rewardType: z.enum(["fixed", "percent"]),
                rewardCents: z.number().int().min(0).max(100000000),
                rewardBasisPoints: z.number().int().min(0).max(10000),
                minimumOrderCents: z.number().int().min(0).max(100000000),
            })
            .superRefine((value, context) => {
                if (value.type === "order_count" && value.threshold < 1)
                    context.addIssue({
                        code: "custom",
                        path: ["threshold"],
                        message: "Tamamlanan sipariş sayısı en az 1 olmalı.",
                    });
                if (value.type === "points" && value.pointsRequired < 1)
                    context.addIssue({
                        code: "custom",
                        path: ["pointsRequired"],
                        message: "Ödül için gereken puan en az 1 olmalı.",
                    });
                if (value.rewardType === "fixed" && value.rewardCents < 1)
                    context.addIssue({
                        code: "custom",
                        path: ["rewardCents"],
                        message: "İndirim tutarı sıfırdan büyük olmalı.",
                    });
                if (
                    value.rewardType === "percent" &&
                    value.rewardBasisPoints < 1
                )
                    context.addIssue({
                        code: "custom",
                        path: ["rewardBasisPoints"],
                        message: "İndirim oranı sıfırdan büyük olmalı.",
                    });
            }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const now = nowIso(environment);
    const result = await database.execute({
        sql: `INSERT INTO loyalty_campaigns (cafe_id,name,type,threshold,points_required,reward_type,reward_cents,reward_basis_points,minimum_order_cents,is_active,created_at,updated_at)
              VALUES (?,?,?,?,?,?,?,?,?,1,?,?) RETURNING id`,
        args: [
            cafeId,
            input.name,
            input.type,
            input.threshold,
            input.pointsRequired,
            input.rewardType,
            input.rewardCents,
            input.rewardBasisPoints,
            input.minimumOrderCents,
            now,
            now,
        ],
    });
    return { id: Number(result.rows[0]?.id) };
}

async function updateLoyaltyCampaign(
    request: Request,
    user: AuthenticatedUser,
    campaignId: number,
    environment: ApiEnvironment,
) {
    const cafeId = assertCafeOwner(user);
    const input = parseInput(
        z.object({ active: z.boolean() }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const result = await database.execute({
        sql: "UPDATE loyalty_campaigns SET is_active=?,updated_at=? WHERE id=? AND cafe_id=?",
        args: [input.active ? 1 : 0, nowIso(environment), campaignId, cafeId],
    });
    if (Number(result.rowsAffected) === 0)
        throw new ApiError(404, "Kampanya bulunamadı.", "campaign_not_found");
    return { ok: true };
}

async function getMenu(
    user: AuthenticatedUser,
    branchId: number,
    environment: ApiEnvironment,
) {
    const database = await getDatabase(environment);
    const { cafeId, shared } = await cafeForBranch(
        database,
        user,
        branchId,
        environment,
    );
    const menuBranchId = shared ? null : branchId;
    const categories = await database.execute({
        sql: "SELECT id,name,description,sort_order,is_active FROM menu_categories WHERE cafe_id=? AND ((branch_id IS NULL AND ? IS NULL) OR branch_id=?) ORDER BY sort_order,id",
        args: [cafeId, menuBranchId, menuBranchId],
    });
    const products = await database.execute({
        sql: `SELECT p.id,p.category_id,p.name,p.description,p.image_path,p.price_cents,p.prep_minutes,p.is_available,p.is_featured,p.sort_order,
                     COALESCE(bp.price_cents,p.price_cents) AS effective_price,COALESCE(bp.is_available,p.is_available) AS effective_available
              FROM menu_products p LEFT JOIN branch_product bp ON bp.menu_product_id=p.id AND bp.branch_id=?
              WHERE p.cafe_id=? AND ((p.branch_id IS NULL AND ? IS NULL) OR p.branch_id=?) ORDER BY p.sort_order,p.id`,
        args: [branchId, cafeId, menuBranchId, menuBranchId],
    });
    const output = [];
    for (const product of products.rows) {
        const groups = await database.execute({
            sql: "SELECT id,name,selection_mode,min_selections,max_selections FROM menu_option_groups WHERE menu_product_id=? ORDER BY sort_order,id",
            args: [Number(product.id)],
        });
        const optionGroups = [];
        for (const group of groups.rows) {
            const options = await database.execute({
                sql: "SELECT id,name,price_delta_cents,is_available FROM menu_options WHERE menu_option_group_id=? ORDER BY sort_order,id",
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
                    isAvailable: Number(option.is_available) === 1,
                })),
            });
        }
        output.push({
            id: Number(product.id),
            categoryId: Number(product.category_id),
            name: String(product.name),
            description: product.description ? String(product.description) : "",
            imageUrl: product.image_path ? String(product.image_path) : "",
            basePriceCents: Number(product.price_cents),
            priceCents: Number(product.effective_price),
            prepMinutes: Number(product.prep_minutes),
            isAvailable: Number(product.effective_available) === 1,
            isFeatured: Number(product.is_featured) === 1,
            optionGroups,
        });
    }
    return {
        branchId,
        menuMode: shared ? "shared" : "independent",
        categories: categories.rows.map((category) => ({
            id: Number(category.id),
            name: String(category.name),
            description: category.description
                ? String(category.description)
                : "",
            sortOrder: Number(category.sort_order),
            isActive: Number(category.is_active) === 1,
        })),
        products: output,
    };
}

async function createBranch(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    const cafeId = assertCafeOwner(user);
    const input = parseInput(
        z.object({
            name: z.string().trim().min(2).max(120),
            address: z.string().trim().max(250).optional().default(""),
            phone: z.string().trim().max(40).optional().default(""),
        }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const base = slugify(input.name);
    const count = await database.execute({
        sql: "SELECT COUNT(*) AS count FROM branches WHERE cafe_id=? AND slug LIKE ?",
        args: [cafeId, `${base}%`],
    });
    const slug = Number(count.rows[0]?.count)
        ? `${base}-${Number(count.rows[0]?.count) + 1}`
        : base;
    const now = nowIso(environment);
    const result = await database.execute({
        sql: "INSERT INTO branches (cafe_id,name,slug,address,phone,uses_shared_menu,is_active,created_at,updated_at) VALUES (?,?,?,?,?,1,1,?,?) RETURNING id",
        args: [
            cafeId,
            input.name,
            slug,
            input.address || null,
            input.phone || null,
            now,
            now,
        ],
    });
    return { id: Number(result.rows[0]?.id), name: input.name };
}

async function createCategory(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    const input = parseInput(
        z.object({
            branchId: z.number().int().positive(),
            name: z.string().trim().min(1).max(100),
            description: z.string().trim().max(300).optional().default(""),
        }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const branch = await cafeForBranch(
        database,
        user,
        input.branchId,
        environment,
    );
    const branchId = branch.shared ? null : input.branchId;
    const now = nowIso(environment);
    const result = await database.execute({
        sql: "INSERT INTO menu_categories (cafe_id,branch_id,name,description,sort_order,is_active,created_at,updated_at) VALUES (?,?,?,?,(SELECT COALESCE(MAX(sort_order),-1)+1 FROM menu_categories WHERE cafe_id=? AND branch_id IS ?),1,?,?) RETURNING id",
        args: [
            branch.cafeId,
            branchId,
            input.name,
            input.description || null,
            branch.cafeId,
            branchId,
            now,
            now,
        ],
    });
    return { id: Number(result.rows[0]?.id) };
}

const optionGroupSchema = z.object({
    name: z.string().trim().min(1).max(100),
    selectionMode: z.enum(["single", "multiple"]).default("single"),
    minSelections: z.number().int().min(0).max(20).default(0),
    maxSelections: z.number().int().min(1).max(20).nullable().optional(),
    options: z
        .array(
            z.object({
                name: z.string().trim().min(1).max(100),
                priceDeltaCents: z.number().int().min(-1000000).max(1000000),
            }),
        )
        .min(1)
        .max(30),
});

async function insertOptionGroups(
    transaction: Transaction,
    productId: number,
    groups: z.infer<typeof optionGroupSchema>[],
    now: string,
) {
    for (const [groupIndex, group] of groups.entries()) {
        if (
            group.maxSelections !== null &&
            group.maxSelections !== undefined &&
            group.maxSelections < group.minSelections
        )
            throw new ApiError(
                422,
                "Seçenek sınırı, zorunlu seçim sayısından küçük olamaz.",
                "invalid_option_group",
            );
        if (group.selectionMode === "single" && group.minSelections > 1)
            throw new ApiError(
                422,
                "Tekli seçenek grubunda en fazla bir seçim zorunlu olabilir.",
                "invalid_option_group",
            );
        const inserted = await transaction.execute({
            sql: "INSERT INTO menu_option_groups (menu_product_id,name,selection_mode,min_selections,max_selections,sort_order,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,1,?,?) RETURNING id",
            args: [
                productId,
                group.name,
                group.selectionMode,
                group.minSelections,
                group.maxSelections ?? null,
                groupIndex,
                now,
                now,
            ],
        });
        const groupId = Number(inserted.rows[0]?.id);
        for (const [optionIndex, option] of group.options.entries())
            await transaction.execute({
                sql: "INSERT INTO menu_options (menu_option_group_id,name,price_delta_cents,is_available,sort_order,created_at,updated_at) VALUES (?,?,?,1,?,?,?)",
                args: [
                    groupId,
                    option.name,
                    option.priceDeltaCents,
                    optionIndex,
                    now,
                    now,
                ],
            });
    }
}

async function createProduct(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    const input = parseInput(
        z.object({
            branchId: z.number().int().positive(),
            categoryId: z.number().int().positive(),
            name: z.string().trim().min(1).max(140),
            description: z.string().trim().max(1000).optional().default(""),
            imageUrl: z
                .string()
                .trim()
                .max(1000)
                .optional()
                .default("")
                .refine(
                    (value) =>
                        !value ||
                        value.startsWith("/api/media/") ||
                        value.startsWith("/storage/") ||
                        value.startsWith("/uploads/") ||
                        /^https:\/\//i.test(value),
                    "Görsel URL HTTPS olmalı.",
                ),
            priceCents: z.number().int().min(0).max(100000000),
            prepMinutes: z.number().int().min(0).max(600).default(0),
            isAvailable: z.boolean().default(true),
            isFeatured: z.boolean().default(false),
            optionGroups: z.array(optionGroupSchema).max(20).default([]),
        }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const branch = await cafeForBranch(
        database,
        user,
        input.branchId,
        environment,
    );
    const category = await database.execute({
        sql: "SELECT id,branch_id FROM menu_categories WHERE id=? AND cafe_id=? AND is_active=1",
        args: [input.categoryId, branch.cafeId],
    });
    const categoryBranchId =
        category.rows[0]?.branch_id === null
            ? null
            : Number(category.rows[0]?.branch_id);
    if (
        !category.rows[0] ||
        categoryBranchId !== (branch.shared ? null : input.branchId)
    )
        throw new ApiError(
            404,
            "Kategori bu menüde bulunamadı.",
            "category_not_found",
        );
    const now = nowIso(environment);
    const result = await withTransaction(environment, async (transaction) => {
        const inserted = await transaction.execute({
            sql: "INSERT INTO menu_products (cafe_id,branch_id,category_id,name,description,image_path,price_cents,prep_minutes,is_available,is_featured,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,(SELECT COALESCE(MAX(sort_order),-1)+1 FROM menu_products WHERE category_id=?),?,?) RETURNING id",
            args: [
                branch.cafeId,
                branch.shared ? null : input.branchId,
                input.categoryId,
                input.name,
                input.description || null,
                input.imageUrl || null,
                input.priceCents,
                input.prepMinutes,
                input.isAvailable ? 1 : 0,
                input.isFeatured ? 1 : 0,
                input.categoryId,
                now,
                now,
            ],
        });
        const productId = Number(inserted.rows[0]?.id);
        await insertOptionGroups(
            transaction,
            productId,
            input.optionGroups,
            now,
        );
        return productId;
    });
    return { id: result };
}

async function updateCategory(
    request: Request,
    user: AuthenticatedUser,
    categoryId: number,
    environment: ApiEnvironment,
) {
    const input = parseInput(
        z
            .object({
                branchId: z.number().int().positive(),
                name: z.string().trim().min(1).max(100).optional(),
                description: z.string().trim().max(300).optional(),
                isActive: z.boolean().optional(),
            })
            .refine(
                (value) =>
                    value.name !== undefined ||
                    value.description !== undefined ||
                    value.isActive !== undefined,
            ),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const branch = await cafeForBranch(
        database,
        user,
        input.branchId,
        environment,
    );
    const result = await database.execute({
        sql: "SELECT id,branch_id FROM menu_categories WHERE id=? AND cafe_id=?",
        args: [categoryId, branch.cafeId],
    });
    const row = result.rows[0];
    const categoryBranchId =
        row?.branch_id === null ? null : Number(row?.branch_id);
    if (!row || categoryBranchId !== (branch.shared ? null : input.branchId))
        throw new ApiError(404, "Kategori bulunamadı.", "category_not_found");
    const now = nowIso(environment);
    await database.execute({
        sql: "UPDATE menu_categories SET name=COALESCE(?,name),description=COALESCE(?,description),is_active=COALESCE(?,is_active),updated_at=? WHERE id=?",
        args: [
            input.name ?? null,
            input.description ?? null,
            input.isActive === undefined ? null : input.isActive ? 1 : 0,
            now,
            categoryId,
        ],
    });
    return { ok: true };
}

async function updateProduct(
    request: Request,
    user: AuthenticatedUser,
    productId: number,
    environment: ApiEnvironment,
) {
    const input = parseInput(
        z
            .object({
                branchId: z.number().int().positive(),
                categoryId: z.number().int().positive().optional(),
                name: z.string().trim().min(1).max(140).optional(),
                description: z.string().trim().max(1000).optional(),
                imageUrl: z
                    .string()
                    .trim()
                    .max(1000)
                    .optional()
                    .refine(
                        (value) =>
                            value === undefined ||
                            !value ||
                            value.startsWith("/api/media/") ||
                            value.startsWith("/storage/") ||
                            value.startsWith("/uploads/") ||
                            /^https:\/\//i.test(value),
                        "Görsel URL HTTPS olmalı.",
                    ),
                priceCents: z.number().int().min(0).max(100000000).optional(),
                prepMinutes: z.number().int().min(0).max(600).optional(),
                isAvailable: z.boolean().optional(),
                isFeatured: z.boolean().optional(),
                optionGroups: z.array(optionGroupSchema).max(20).optional(),
            })
            .refine((value) =>
                Object.keys(value).some((key) => key !== "branchId"),
            ),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const branch = await cafeForBranch(
        database,
        user,
        input.branchId,
        environment,
    );
    const result = await database.execute({
        sql: "SELECT id,branch_id FROM menu_products WHERE id=? AND cafe_id=?",
        args: [productId, branch.cafeId],
    });
    const row = result.rows[0];
    const productBranchId =
        row?.branch_id === null ? null : Number(row?.branch_id);
    if (!row || productBranchId !== (branch.shared ? null : input.branchId))
        throw new ApiError(
            404,
            "Ürün bu menüde bulunamadı.",
            "product_not_found",
        );
    const now = nowIso(environment);
    let categoryId: number | undefined;
    if (input.categoryId !== undefined) {
        const category = await database.execute({
            sql: "SELECT id,branch_id FROM menu_categories WHERE id=? AND cafe_id=? AND is_active=1",
            args: [input.categoryId, branch.cafeId],
        });
        const categoryBranchId =
            category.rows[0]?.branch_id === null
                ? null
                : Number(category.rows[0]?.branch_id);
        if (
            !category.rows[0] ||
            categoryBranchId !== (branch.shared ? null : input.branchId)
        )
            throw new ApiError(
                404,
                "Kategori bu menüde bulunamadı.",
                "category_not_found",
            );
        categoryId = input.categoryId;
    }
    await withTransaction(environment, async (transaction) => {
        if (
            branch.shared &&
            (input.priceCents !== undefined || input.isAvailable !== undefined)
        ) {
            await transaction.execute({
                sql: "INSERT INTO branch_product (branch_id,menu_product_id,price_cents,is_available,created_at,updated_at) VALUES (?,?,?,?,?,?) ON CONFLICT(branch_id,menu_product_id) DO UPDATE SET price_cents=COALESCE(excluded.price_cents,branch_product.price_cents),is_available=COALESCE(excluded.is_available,branch_product.is_available),updated_at=excluded.updated_at",
                args: [
                    input.branchId,
                    productId,
                    input.priceCents ?? null,
                    input.isAvailable === undefined
                        ? null
                        : input.isAvailable
                          ? 1
                          : 0,
                    now,
                    now,
                ],
            });
        }
        await transaction.execute({
            sql: `UPDATE menu_products SET name=COALESCE(?,name),description=COALESCE(?,description),image_path=COALESCE(?,image_path),
            category_id=COALESCE(?,category_id),price_cents=CASE WHEN branch_id IS NULL AND ?=1 THEN price_cents ELSE COALESCE(?,price_cents) END,
            prep_minutes=COALESCE(?,prep_minutes),is_available=CASE WHEN branch_id IS NULL AND ?=1 THEN is_available ELSE COALESCE(?,is_available) END,
            is_featured=COALESCE(?,is_featured),updated_at=? WHERE id=?`,
            args: [
                input.name ?? null,
                input.description ?? null,
                input.imageUrl ?? null,
                categoryId ?? null,
                branch.shared ? 1 : 0,
                input.priceCents ?? null,
                input.prepMinutes ?? null,
                branch.shared ? 1 : 0,
                input.isAvailable === undefined
                    ? null
                    : input.isAvailable
                      ? 1
                      : 0,
                input.isFeatured === undefined
                    ? null
                    : input.isFeatured
                      ? 1
                      : 0,
                now,
                productId,
            ],
        });
        if (input.optionGroups !== undefined) {
            await transaction.execute({
                sql: "DELETE FROM menu_option_groups WHERE menu_product_id=?",
                args: [productId],
            });
            await insertOptionGroups(
                transaction,
                productId,
                input.optionGroups,
                now,
            );
        }
    });
    return { ok: true };
}

async function copyMenu(
    transaction: Transaction,
    cafeId: number,
    destinationBranch: number,
    sourceBranch: number | null,
    now: string,
) {
    const categories = await transaction.execute({
        sql: "SELECT id,name,description,sort_order,is_active FROM menu_categories WHERE cafe_id=? AND ((branch_id IS NULL AND ? IS NULL) OR branch_id=?) ORDER BY sort_order,id",
        args: [cafeId, sourceBranch, sourceBranch],
    });
    for (const category of categories.rows) {
        const createdCategory = await transaction.execute({
            sql: "INSERT INTO menu_categories (cafe_id,branch_id,name,description,sort_order,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?) RETURNING id",
            args: [
                cafeId,
                destinationBranch,
                String(category.name),
                category.description,
                Number(category.sort_order),
                Number(category.is_active),
                now,
                now,
            ],
        });
        const categoryId = Number(createdCategory.rows[0]?.id);
        const products = await transaction.execute({
            sql: `SELECT p.*,COALESCE(bp.price_cents,p.price_cents) AS effective_price,COALESCE(bp.is_available,p.is_available) AS effective_available FROM menu_products p LEFT JOIN branch_product bp ON bp.menu_product_id=p.id AND bp.branch_id=? WHERE p.category_id=? ORDER BY p.sort_order,p.id`,
            args: [sourceBranch ?? destinationBranch, Number(category.id)],
        });
        for (const product of products.rows) {
            const createdProduct = await transaction.execute({
                sql: "INSERT INTO menu_products (cafe_id,branch_id,category_id,name,description,image_path,price_cents,prep_minutes,is_available,is_featured,suggestions_json,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id",
                args: [
                    cafeId,
                    destinationBranch,
                    categoryId,
                    String(product.name),
                    product.description,
                    product.image_path,
                    Number(product.effective_price),
                    Number(product.prep_minutes),
                    Number(product.effective_available),
                    Number(product.is_featured),
                    product.suggestions_json,
                    Number(product.sort_order),
                    now,
                    now,
                ],
            });
            const newProductId = Number(createdProduct.rows[0]?.id);
            const groups = await transaction.execute({
                sql: "SELECT id,name,selection_mode,min_selections,max_selections,sort_order,is_active FROM menu_option_groups WHERE menu_product_id=? ORDER BY sort_order,id",
                args: [Number(product.id)],
            });
            for (const group of groups.rows) {
                const createdGroup = await transaction.execute({
                    sql: "INSERT INTO menu_option_groups (menu_product_id,name,selection_mode,min_selections,max_selections,sort_order,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,?, ?,?) RETURNING id",
                    args: [
                        newProductId,
                        String(group.name),
                        String(group.selection_mode),
                        Number(group.min_selections),
                        group.max_selections === null
                            ? null
                            : Number(group.max_selections),
                        Number(group.sort_order),
                        Number(group.is_active),
                        now,
                        now,
                    ],
                });
                const newGroupId = Number(createdGroup.rows[0]?.id);
                const options = await transaction.execute({
                    sql: "SELECT name,price_delta_cents,is_available,sort_order FROM menu_options WHERE menu_option_group_id=? ORDER BY sort_order,id",
                    args: [Number(group.id)],
                });
                for (const option of options.rows)
                    await transaction.execute({
                        sql: "INSERT INTO menu_options (menu_option_group_id,name,price_delta_cents,is_available,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?)",
                        args: [
                            newGroupId,
                            String(option.name),
                            Number(option.price_delta_cents),
                            Number(option.is_available),
                            Number(option.sort_order),
                            now,
                            now,
                        ],
                    });
            }
        }
    }
}

async function switchMenu(
    request: Request,
    user: AuthenticatedUser,
    branchId: number,
    environment: ApiEnvironment,
) {
    const input = parseInput(
        z.object({
            mode: z.enum(["shared", "independent"]),
            sourceBranchId: z.number().int().positive().nullable().optional(),
        }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const { cafeId } = await cafeForBranch(
        database,
        user,
        branchId,
        environment,
    );
    const now = nowIso(environment);
    if (input.mode === "shared") {
        await database.execute({
            sql: "UPDATE branches SET uses_shared_menu=1,updated_at=? WHERE id=?",
            args: [now, branchId],
        });
        return { ok: true, message: "Şube ortak menüyü kullanıyor." };
    }
    const hasMenu = await database.execute({
        sql: "SELECT 1 FROM menu_categories WHERE cafe_id=? AND branch_id=? LIMIT 1",
        args: [cafeId, branchId],
    });
    if (hasMenu.rows.length) {
        await database.execute({
            sql: "UPDATE branches SET uses_shared_menu=0,updated_at=? WHERE id=?",
            args: [now, branchId],
        });
        return {
            ok: true,
            copied: false,
            message: "Şubenin mevcut özel menüsü açıldı.",
        };
    }
    let sourceBranchId: number | null = null;
    if (input.sourceBranchId) {
        if (input.sourceBranchId === branchId)
            throw new ApiError(
                422,
                "Kaynak şube hedef şubenin kendisi olamaz.",
                "invalid_source_branch",
            );
        const source = await database.execute({
            sql: "SELECT id,uses_shared_menu FROM branches WHERE id=? AND cafe_id=? AND is_active=1 AND deleted_at IS NULL",
            args: [input.sourceBranchId, cafeId],
        });
        if (!source.rows[0])
            throw new ApiError(
                404,
                "Kaynak şube bulunamadı.",
                "branch_not_found",
            );
        sourceBranchId =
            Number(source.rows[0].uses_shared_menu) === 1
                ? null
                : input.sourceBranchId;
    }
    await withTransaction(environment, async (transaction) => {
        await copyMenu(transaction, cafeId, branchId, sourceBranchId, now);
        await transaction.execute({
            sql: "UPDATE branches SET uses_shared_menu=0,updated_at=? WHERE id=?",
            args: [now, branchId],
        });
    });
    return {
        ok: true,
        copied: true,
        message: "Menü kopyalandı; bu şubedeki düzenlemeler artık bağımsız.",
    };
}

async function getTables(
    user: AuthenticatedUser,
    branchId: number,
    environment: ApiEnvironment,
) {
    const database = await getDatabase(environment);
    await cafeForBranch(database, user, branchId, environment);
    const result = await database.execute({
        sql: `SELECT t.id,t.name,t.is_active,t.public_token_encrypted,s.id AS session_id,s.status AS session_status FROM dining_tables t LEFT JOIN table_sessions s ON s.id=(SELECT MAX(s2.id) FROM table_sessions s2 WHERE s2.dining_table_id=t.id) WHERE t.branch_id=? AND t.deleted_at IS NULL ORDER BY t.id`,
        args: [branchId],
    });
    return {
        tables: result.rows.map((row) => ({
            id: Number(row.id),
            name: String(row.name),
            active: Number(row.is_active) === 1,
            sessionOpen: String(row.session_status ?? "") === "open",
            sessionId: row.session_id === null ? null : Number(row.session_id),
            qrUrl: `/masa/${decryptQrToken(String(row.public_token_encrypted))}`,
        })),
    };
}

async function createTable(
    request: Request,
    user: AuthenticatedUser,
    branchId: number,
    environment: ApiEnvironment,
) {
    const input = parseInput(
        z.object({ name: z.string().trim().min(1).max(80) }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    await cafeForBranch(database, user, branchId, environment);
    const token = newToken(32);
    const now = nowIso(environment);
    try {
        const result = await database.execute({
            sql: "INSERT INTO dining_tables (branch_id,name,public_token_hash,public_token_encrypted,is_active,created_at,updated_at) VALUES (?,?,?,?,1,?,?) RETURNING id",
            args: [
                branchId,
                input.name,
                hash(token),
                encryptQrToken(token),
                now,
                now,
            ],
        });
        await database.execute({
            sql: `INSERT INTO activity_logs (branch_id,user_id,action,subject_type,subject_id,created_at) VALUES (?,?,'table.created','DiningTable',?,?)`,
            args: [branchId, user.id, Number(result.rows[0]?.id), now],
        });
        return {
            id: Number(result.rows[0]?.id),
            name: input.name,
            qrUrl: `/masa/${token}`,
            token,
        };
    } catch (error) {
        if (String(error).includes("UNIQUE"))
            throw new ApiError(
                409,
                "Bu masa adı şubede kullanılıyor.",
                "table_name_in_use",
            );
        throw error;
    }
}

async function updateTable(
    request: Request,
    user: AuthenticatedUser,
    branchId: number,
    tableId: number,
    environment: ApiEnvironment,
) {
    const input = parseInput(
        z
            .object({
                name: z.string().trim().min(1).max(80).optional(),
                active: z.boolean().optional(),
                rotateQr: z.boolean().optional(),
            })
            .refine(
                (value) =>
                    value.name !== undefined ||
                    value.active !== undefined ||
                    value.rotateQr === true,
            ),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    await cafeForBranch(database, user, branchId, environment);
    const exists = await database.execute({
        sql: "SELECT id FROM dining_tables WHERE id=? AND branch_id=? AND deleted_at IS NULL",
        args: [tableId, branchId],
    });
    if (!exists.rows.length)
        throw new ApiError(404, "Masa bulunamadı.", "table_not_found");
    const now = nowIso(environment);
    const token = input.rotateQr ? newToken(32) : null;
    await database.execute({
        sql: "UPDATE dining_tables SET name=COALESCE(?,name),is_active=COALESCE(?,is_active),public_token_hash=COALESCE(?,public_token_hash),public_token_encrypted=COALESCE(?,public_token_encrypted),updated_at=? WHERE id=?",
        args: [
            input.name ?? null,
            input.active === undefined ? null : input.active ? 1 : 0,
            token ? hash(token) : null,
            token ? encryptQrToken(token) : null,
            now,
            tableId,
        ],
    });
    return { ok: true, ...(token ? { qrUrl: `/masa/${token}`, token } : {}) };
}

async function platformDashboard(
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    requireRole(user, ["platform_admin"]);
    const database = await getDatabase(environment);
    const cafes = await database.execute({
        sql: `SELECT c.id,c.name,c.slug,c.status,c.created_at,u.id AS owner_id,u.name AS owner_name,u.email AS owner_email,
        (SELECT COUNT(*) FROM branches b WHERE b.cafe_id=c.id AND b.is_active=1 AND b.deleted_at IS NULL) AS branch_count,
        (SELECT COUNT(*) FROM dining_tables t JOIN branches b ON b.id=t.branch_id WHERE b.cafe_id=c.id AND t.is_active=1 AND t.deleted_at IS NULL) AS table_count,
        (SELECT COUNT(*) FROM users cu WHERE cu.cafe_id=c.id AND cu.role='customer' AND cu.is_active=1) AS customer_count
        FROM cafes c LEFT JOIN users u ON u.cafe_id=c.id AND u.role='cafe_owner' AND u.is_active=1 WHERE c.deleted_at IS NULL ORDER BY c.created_at DESC,c.id DESC`,
    });
    const metrics = await database.execute({
        sql: `SELECT (SELECT COUNT(*) FROM cafes WHERE deleted_at IS NULL) AS cafes,
        (SELECT COUNT(*) FROM cafes WHERE deleted_at IS NULL AND status='active') AS active_cafes,
        (SELECT COUNT(*) FROM dining_tables t JOIN branches b ON b.id=t.branch_id WHERE t.is_active=1 AND t.deleted_at IS NULL AND b.is_active=1 AND b.deleted_at IS NULL) AS active_tables,
        (SELECT COUNT(*) FROM users WHERE role='customer' AND is_active=1) AS active_customers`,
    });
    const counts = metrics.rows[0];
    return {
        cafes: cafes.rows.map((row) => ({
            id: Number(row.id),
            name: String(row.name),
            slug: String(row.slug),
            status: String(row.status),
            createdAt: String(row.created_at),
            owner: row.owner_id
                ? {
                      id: Number(row.owner_id),
                      name: String(row.owner_name),
                      email: String(row.owner_email),
                  }
                : null,
            branches: Number(row.branch_count),
            activeTables: Number(row.table_count),
            activeCustomers: Number(row.customer_count),
        })),
        metrics: {
            cafes: Number(counts?.cafes ?? 0),
            activeCafes: Number(counts?.active_cafes ?? 0),
            activeTables: Number(counts?.active_tables ?? 0),
            activeCustomers: Number(counts?.active_customers ?? 0),
        },
    };
}

async function createCafe(
    request: Request,
    user: AuthenticatedUser,
    environment: ApiEnvironment,
) {
    requireRole(user, ["platform_admin"]);
    const input = parseInput(
        z.object({
            cafeName: z.string().trim().min(2).max(120),
            branchName: z.string().trim().min(2).max(120),
            ownerName: z.string().trim().min(2).max(120),
            ownerEmail: z.string().trim().email().max(254),
        }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const now = nowIso(environment);
    const slug = slugify(input.cafeName);
    const exists = await database.execute({
        sql: "SELECT 1 FROM cafes WHERE slug=? COLLATE NOCASE",
        args: [slug],
    });
    if (exists.rows.length)
        throw new ApiError(
            409,
            "Bu kafe adı için adres zaten kullanılıyor.",
            "cafe_slug_in_use",
        );
    const email = input.ownerEmail.toLowerCase();
    const emailExists = await database.execute({
        sql: "SELECT 1 FROM users WHERE email=? COLLATE NOCASE",
        args: [email],
    });
    if (emailExists.rows.length)
        throw new ApiError(
            409,
            "Bu e-posta adresi zaten kullanılıyor.",
            "email_in_use",
        );
    const password = `Sk${randomBytes(9).toString("base64url")}9!`;
    const passwordHash = await hashPassword(password, 12);
    const result = await withTransaction(environment, async (transaction) => {
        const cafe = await transaction.execute({
            sql: "INSERT INTO cafes (name,slug,status,registration_policy,loyalty_enabled,created_at,updated_at) VALUES (?,?,'active','optional',0,?,?) RETURNING id",
            args: [input.cafeName, slug, now, now],
        });
        const cafeId = Number(cafe.rows[0]?.id);
        const branch = await transaction.execute({
            sql: "INSERT INTO branches (cafe_id,name,slug,uses_shared_menu,is_active,created_at,updated_at) VALUES (?,?,'merkez',1,1,?,?) RETURNING id",
            args: [cafeId, input.branchName, now, now],
        });
        await transaction.execute({
            sql: "INSERT INTO users (cafe_id,name,email,password_hash,role,is_active,created_at,updated_at) VALUES (?,?,?,?,'cafe_owner',1,?,?)",
            args: [cafeId, input.ownerName, email, passwordHash, now, now],
        });
        await transaction.execute({
            sql: "INSERT INTO activity_logs (user_id,action,subject_type,subject_id,metadata_json,created_at) VALUES (?,'platform.cafe_created','Cafe',?,?,?)",
            args: [
                user.id,
                cafeId,
                JSON.stringify({ name: input.cafeName, ownerEmail: email }),
                now,
            ],
        });
        return { cafeId, branchId: Number(branch.rows[0]?.id) };
    });
    return { ...result, ownerEmail: email, temporaryPassword: password };
}

async function updateCafe(
    request: Request,
    user: AuthenticatedUser,
    cafeId: number,
    environment: ApiEnvironment,
) {
    requireRole(user, ["platform_admin"]);
    const input = parseInput(
        z
            .object({
                name: z.string().trim().min(2).max(120).optional(),
                status: z.enum(["active", "suspended"]).optional(),
            })
            .refine(
                (value) =>
                    value.name !== undefined || value.status !== undefined,
            ),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const now = nowIso(environment);
    const updated = await database.execute({
        sql: "UPDATE cafes SET name=COALESCE(?,name),status=COALESCE(?,status),updated_at=? WHERE id=? AND deleted_at IS NULL",
        args: [input.name ?? null, input.status ?? null, now, cafeId],
    });
    if (!updated.rowsAffected)
        throw new ApiError(404, "Kafe bulunamadı.", "cafe_not_found");
    await database.execute({
        sql: "INSERT INTO activity_logs (user_id,action,subject_type,subject_id,metadata_json,created_at) VALUES (?,'platform.cafe_updated','Cafe',?,?,?)",
        args: [user.id, cafeId, JSON.stringify(input), now],
    });
    return { ok: true };
}

async function resetOwner(
    request: Request,
    user: AuthenticatedUser,
    cafeId: number,
    environment: ApiEnvironment,
) {
    requireRole(user, ["platform_admin"]);
    const input = parseInput(
        z.object({ ownerId: z.number().int().positive() }),
        await readJson(request),
    );
    const database = await getDatabase(environment);
    const owner = await database.execute({
        sql: "SELECT id,email FROM users WHERE id=? AND cafe_id=? AND role='cafe_owner'",
        args: [input.ownerId, cafeId],
    });
    if (!owner.rows[0])
        throw new ApiError(
            404,
            "Bu kafeye ait kafe sahibi bulunamadı.",
            "owner_not_found",
        );
    const password = `Sk${randomBytes(9).toString("base64url")}9!`;
    const now = nowIso(environment);
    await database.execute({
        sql: "UPDATE users SET password_hash=?,updated_at=? WHERE id=?",
        args: [await hashPassword(password, 12), now, input.ownerId],
    });
    await database.execute({
        sql: "DELETE FROM auth_sessions WHERE user_id=?",
        args: [input.ownerId],
    });
    await database.execute({
        sql: "INSERT INTO activity_logs (user_id,action,subject_type,subject_id,metadata_json,created_at) VALUES (?,'platform.owner_password_reset','User',?,?,?)",
        args: [
            user.id,
            input.ownerId,
            JSON.stringify({ cafeId, email: String(owner.rows[0].email) }),
            now,
        ],
    });
    return {
        ownerEmail: String(owner.rows[0].email),
        temporaryPassword: password,
    };
}

export async function handleManagementRequest(
    request: Request,
    path: string,
    environment: ApiEnvironment,
): Promise<Response | null> {
    const isManage = path.startsWith("/api/manage/");
    const isPlatform = path.startsWith("/api/platform/");
    if (!isManage && !isPlatform) return null;
    const user = await currentUser(request, environment);
    if (!user)
        throw new ApiError(
            401,
            "Bu işlem için giriş yapın.",
            "authentication_required",
        );
    const method = request.method.toUpperCase();
    if (method !== "GET") {
        checkSameOrigin(request);
        requireCsrf(request, user);
    }
    let match: RegExpExecArray | null;
    if (method === "GET" && path === "/api/manage/overview")
        return new Response(
            JSON.stringify(await ownerOverview(user, environment)),
            {
                headers: {
                    "content-type": "application/json; charset=utf-8",
                    "cache-control": "no-store",
                },
            },
        );
    if (method === "GET" && path === "/api/manage/menu") {
        requireRole(user, ["cafe_owner", "cafe_manager"]);
        const branchId = Number(
            new URL(request.url).searchParams.get("branchId"),
        );
        if (!Number.isInteger(branchId) || branchId < 1)
            throw new ApiError(422, "Şube seçin.", "branch_required");
        return new Response(
            JSON.stringify(await getMenu(user, branchId, environment)),
            {
                headers: {
                    "content-type": "application/json; charset=utf-8",
                    "cache-control": "no-store",
                },
            },
        );
    }
    if (method === "GET" && path === "/api/manage/staff")
        return new Response(JSON.stringify(await getStaff(user, environment)), {
            headers: {
                "content-type": "application/json; charset=utf-8",
                "cache-control": "no-store",
            },
        });
    if (method === "POST" && path === "/api/manage/staff")
        return new Response(
            JSON.stringify(await createStaff(request, user, environment)),
            {
                status: 201,
                headers: { "content-type": "application/json; charset=utf-8" },
            },
        );
    if (method === "PATCH" && path === "/api/manage/settings")
        return new Response(
            JSON.stringify(await updateSettings(request, user, environment)),
            {
                headers: { "content-type": "application/json; charset=utf-8" },
            },
        );
    if (method === "GET" && path === "/api/manage/loyalty-campaigns")
        return new Response(
            JSON.stringify(await getLoyaltyCampaigns(user, environment)),
            {
                headers: {
                    "content-type": "application/json; charset=utf-8",
                    "cache-control": "no-store",
                },
            },
        );
    if (method === "POST" && path === "/api/manage/loyalty-campaigns")
        return new Response(
            JSON.stringify(
                await createLoyaltyCampaign(request, user, environment),
            ),
            {
                status: 201,
                headers: { "content-type": "application/json; charset=utf-8" },
            },
        );
    match = /^\/api\/manage\/loyalty-campaigns\/(\d+)$/.exec(path);
    if (method === "PATCH" && match)
        return new Response(
            JSON.stringify(
                await updateLoyaltyCampaign(
                    request,
                    user,
                    Number(match[1]),
                    environment,
                ),
            ),
            {
                headers: { "content-type": "application/json; charset=utf-8" },
            },
        );
    match = /^\/api\/manage\/staff\/(\d+)\/reset-password$/.exec(path);
    if (method === "POST" && match)
        return new Response(
            JSON.stringify(
                await resetStaffPassword(user, Number(match[1]), environment),
            ),
            {
                headers: { "content-type": "application/json; charset=utf-8" },
            },
        );
    match = /^\/api\/manage\/staff\/(\d+)$/.exec(path);
    if (method === "PATCH" && match)
        return new Response(
            JSON.stringify(
                await updateStaff(request, user, Number(match[1]), environment),
            ),
            {
                headers: { "content-type": "application/json; charset=utf-8" },
            },
        );
    if (method === "POST" && path === "/api/manage/branches")
        return new Response(
            JSON.stringify(await createBranch(request, user, environment)),
            {
                status: 201,
                headers: { "content-type": "application/json; charset=utf-8" },
            },
        );
    if (method === "POST" && path === "/api/manage/categories")
        return new Response(
            JSON.stringify(await createCategory(request, user, environment)),
            {
                status: 201,
                headers: { "content-type": "application/json; charset=utf-8" },
            },
        );
    if (method === "POST" && path === "/api/manage/products")
        return new Response(
            JSON.stringify(await createProduct(request, user, environment)),
            {
                status: 201,
                headers: { "content-type": "application/json; charset=utf-8" },
            },
        );
    match = /^\/api\/manage\/categories\/(\d+)$/.exec(path);
    if (method === "PATCH" && match)
        return new Response(
            JSON.stringify(
                await updateCategory(
                    request,
                    user,
                    Number(match[1]),
                    environment,
                ),
            ),
            { headers: { "content-type": "application/json; charset=utf-8" } },
        );
    match = /^\/api\/manage\/products\/(\d+)$/.exec(path);
    if (method === "PATCH" && match)
        return new Response(
            JSON.stringify(
                await updateProduct(
                    request,
                    user,
                    Number(match[1]),
                    environment,
                ),
            ),
            { headers: { "content-type": "application/json; charset=utf-8" } },
        );
    match = /^\/api\/manage\/branches\/(\d+)\/menu$/.exec(path);
    if (method === "POST" && match)
        return new Response(
            JSON.stringify(
                await switchMenu(request, user, Number(match[1]), environment),
            ),
            { headers: { "content-type": "application/json; charset=utf-8" } },
        );
    match = /^\/api\/manage\/branches\/(\d+)\/tables$/.exec(path);
    if (method === "GET" && match)
        return new Response(
            JSON.stringify(
                await getTables(user, Number(match[1]), environment),
            ),
            {
                headers: {
                    "content-type": "application/json; charset=utf-8",
                    "cache-control": "no-store",
                },
            },
        );
    if (method === "POST" && match)
        return new Response(
            JSON.stringify(
                await createTable(request, user, Number(match[1]), environment),
            ),
            {
                status: 201,
                headers: { "content-type": "application/json; charset=utf-8" },
            },
        );
    match = /^\/api\/manage\/branches\/(\d+)\/tables\/(\d+)$/.exec(path);
    if (method === "PATCH" && match)
        return new Response(
            JSON.stringify(
                await updateTable(
                    request,
                    user,
                    Number(match[1]),
                    Number(match[2]),
                    environment,
                ),
            ),
            { headers: { "content-type": "application/json; charset=utf-8" } },
        );
    if (method === "GET" && path === "/api/platform/dashboard")
        return new Response(
            JSON.stringify(await platformDashboard(user, environment)),
            {
                headers: {
                    "content-type": "application/json; charset=utf-8",
                    "cache-control": "no-store",
                },
            },
        );
    if (method === "POST" && path === "/api/platform/cafes")
        return new Response(
            JSON.stringify(await createCafe(request, user, environment)),
            {
                status: 201,
                headers: { "content-type": "application/json; charset=utf-8" },
            },
        );
    match = /^\/api\/platform\/cafes\/(\d+)$/.exec(path);
    if (method === "PATCH" && match)
        return new Response(
            JSON.stringify(
                await updateCafe(request, user, Number(match[1]), environment),
            ),
            { headers: { "content-type": "application/json; charset=utf-8" } },
        );
    match = /^\/api\/platform\/cafes\/(\d+)\/reset-owner$/.exec(path);
    if (method === "POST" && match)
        return new Response(
            JSON.stringify(
                await resetOwner(request, user, Number(match[1]), environment),
            ),
            { headers: { "content-type": "application/json; charset=utf-8" } },
        );
    return null;
}
