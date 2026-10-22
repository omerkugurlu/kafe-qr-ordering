import {
    hash,
    newToken,
    nowIso,
    ApiError,
    type ApiEnvironment,
    type AuthenticatedUser,
} from "./http.js";
import {
    equalsSecret,
    getCookie,
    getDatabase,
    sessionCookie,
    withTransaction,
} from "./http.js";
import { checkSameOrigin, jsonResponse, readJson } from "./http.js";
import { z } from "zod";
import { compare, hash as hashPassword } from "bcryptjs";

const SESSION_COOKIE = "sk_session";
const SESSION_LIFETIME_SECONDS = 60 * 60 * 24 * 14;
const loginSchema = z.object({
    email: z.string().email().max(254),
    password: z.string().min(1).max(200),
});
const registerSchema = z.object({
    token: z.string().min(24).max(160),
    name: z.string().trim().min(2).max(120),
    email: z.string().trim().email().max(254),
    password: z.string().min(8).max(200),
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

function clientIp(request: Request, environment: ApiEnvironment): string {
    return (
        environment.context?.ip ??
        request.headers.get("x-nf-client-connection-ip") ??
        request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ??
        "local"
    );
}

async function rateLimit(
    request: Request,
    environment: ApiEnvironment,
    purpose: string,
    identifier: string,
    limit: number,
): Promise<void> {
    const key = hash(
        `${purpose}:${clientIp(request, environment)}:${identifier.toLowerCase()}`,
    );
    const now = nowIso(environment);
    const windowStart = new Date(Date.parse(now) - 15 * 60_000).toISOString();
    const blockUntil = new Date(Date.parse(now) + 15 * 60_000).toISOString();
    const blocked = await withTransaction(environment, async (transaction) => {
        const result = await transaction.execute({
            sql: "SELECT attempts, window_started_at, blocked_until FROM rate_limits WHERE bucket_key = ?",
            args: [key],
        });
        const row = result.rows[0];
        const blockedUntil = row?.blocked_until
            ? String(row.blocked_until)
            : null;
        if (blockedUntil && blockedUntil > now) return true;

        const currentWindow = row?.window_started_at
            ? String(row.window_started_at)
            : null;
        const expired = !currentWindow || currentWindow < windowStart;
        const attempts = expired ? 1 : Number(row?.attempts ?? 0) + 1;
        const currentBlock = attempts >= limit ? blockUntil : null;
        await transaction.execute({
            sql: `INSERT INTO rate_limits (bucket_key, attempts, window_started_at, blocked_until)
                  VALUES (?, ?, ?, ?)
                  ON CONFLICT(bucket_key) DO UPDATE SET attempts = excluded.attempts,
                    window_started_at = excluded.window_started_at, blocked_until = excluded.blocked_until`,
            args: [key, attempts, expired ? now : currentWindow, currentBlock],
        });
        return attempts > limit;
    });

    if (blocked)
        throw new ApiError(
            429,
            "Çok fazla deneme yapıldı. Bir süre sonra tekrar deneyin.",
            "rate_limited",
        );
}

export async function currentUser(
    request: Request,
    environment: ApiEnvironment,
): Promise<AuthenticatedUser | null> {
    const rawSession = getCookie(request, SESSION_COOKIE);
    if (!rawSession) return null;

    const database = await getDatabase(environment);
    const result = await database.execute({
        sql: `SELECT u.id, u.cafe_id, u.name, u.email, u.role, u.is_active, s.csrf_token
              FROM auth_sessions s JOIN users u ON u.id = s.user_id
              WHERE s.id_hash = ? AND s.expires_at > ?`,
        args: [hash(rawSession), nowIso(environment)],
    });
    const row = result.rows[0];
    if (!row || Number(row.is_active) !== 1) return null;

    await database.execute({
        sql: "UPDATE auth_sessions SET last_seen_at = ? WHERE id_hash = ?",
        args: [nowIso(environment), hash(rawSession)],
    });
    return {
        id: Number(row.id),
        cafeId: row.cafe_id === null ? null : Number(row.cafe_id),
        name: String(row.name),
        email: String(row.email),
        role: String(row.role),
        csrfToken: String(row.csrf_token),
    };
}

export async function requireUser(
    request: Request,
    environment: ApiEnvironment,
): Promise<AuthenticatedUser> {
    const user = await currentUser(request, environment);
    if (!user)
        throw new ApiError(
            401,
            "Bu işlem için giriş yapın.",
            "authentication_required",
        );
    return user;
}

export function requireCsrf(request: Request, user: AuthenticatedUser): void {
    const submitted = request.headers.get("x-csrf-token");
    if (!submitted || !equalsSecret(submitted, user.csrfToken)) {
        throw new ApiError(
            403,
            "Sayfayı yenileyip tekrar deneyin.",
            "csrf_mismatch",
        );
    }
}

export function requireRole(user: AuthenticatedUser, roles: string[]): void {
    if (!roles.includes(user.role))
        throw new ApiError(403, "Bu işlem için yetkiniz yok.", "forbidden");
}

export async function requireBranchAccess(
    user: AuthenticatedUser,
    branchId: number,
    environment: ApiEnvironment,
): Promise<void> {
    if (user.role === "platform_admin") return;

    const database = await getDatabase(environment);
    const branchResult = await database.execute({
        sql: "SELECT cafe_id FROM branches WHERE id = ? AND is_active = 1 AND deleted_at IS NULL",
        args: [branchId],
    });
    const branch = branchResult.rows[0];
    if (!branch)
        throw new ApiError(404, "Şube bulunamadı.", "branch_not_found");

    if (
        (user.role === "cafe_owner" || user.role === "cafe_manager") &&
        user.cafeId === Number(branch.cafe_id)
    )
        return;
    if (!["waiter", "kitchen"].includes(user.role))
        throw new ApiError(403, "Bu şubeye erişim yetkiniz yok.", "forbidden");

    const assignment = await database.execute({
        sql: "SELECT 1 FROM branch_user WHERE branch_id = ? AND user_id = ?",
        args: [branchId, user.id],
    });
    if (assignment.rows.length === 0)
        throw new ApiError(403, "Bu şubeye erişim yetkiniz yok.", "forbidden");
}

async function createSession(
    request: Request,
    userId: number,
    environment: ApiEnvironment,
): Promise<{ cookie: string; csrfToken: string }> {
    const rawSession = newToken(32);
    const csrfToken = newToken(32);
    const now = nowIso(environment);
    const expiresAt = new Date(
        Date.parse(now) + SESSION_LIFETIME_SECONDS * 1000,
    ).toISOString();
    const database = await getDatabase(environment);
    await database.execute({
        sql: "INSERT INTO auth_sessions (id_hash, user_id, csrf_token, expires_at, created_at, last_seen_at, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)",
        args: [
            hash(rawSession),
            userId,
            csrfToken,
            expiresAt,
            now,
            now,
            request.headers.get("user-agent")?.slice(0, 500) ?? null,
        ],
    });
    return {
        cookie: sessionCookie(rawSession, request, SESSION_LIFETIME_SECONDS),
        csrfToken,
    };
}

async function authenticate(
    request: Request,
    environment: ApiEnvironment,
    input: z.infer<typeof loginSchema>,
    allowedRoles: string[],
): Promise<Response> {
    const email = input.email.trim().toLowerCase();
    await rateLimit(request, environment, "login", email, 8);
    const database = await getDatabase(environment);
    const result = await database.execute({
        sql: "SELECT id, name, email, role, cafe_id, password_hash, is_active FROM users WHERE email = ? COLLATE NOCASE LIMIT 1",
        args: [email],
    });
    const user = result.rows[0];
    const valid =
        user &&
        Number(user.is_active) === 1 &&
        allowedRoles.includes(String(user.role))
            ? await compare(input.password, String(user.password_hash))
            : false;

    if (!valid || !user)
        throw new ApiError(
            422,
            "E-posta veya şifre hatalı.",
            "invalid_credentials",
        );

    const session = await createSession(request, Number(user.id), environment);
    return jsonResponse(
        {
            user: {
                id: Number(user.id),
                name: String(user.name),
                email: String(user.email),
                role: String(user.role),
                cafeId: user.cafe_id === null ? null : Number(user.cafe_id),
            },
            csrfToken: session.csrfToken,
        },
        200,
        { "set-cookie": session.cookie },
    );
}

export async function handleAuthRequest(
    request: Request,
    path: string,
    environment: ApiEnvironment,
): Promise<Response | null> {
    const method = request.method.toUpperCase();
    const database = await getDatabase(environment);

    if (method === "GET" && path === "/api/auth/me") {
        const user = await currentUser(request, environment);
        return jsonResponse({
            user: user
                ? {
                      id: user.id,
                      name: user.name,
                      email: user.email,
                      role: user.role,
                      cafeId: user.cafeId,
                  }
                : null,
            csrfToken: user?.csrfToken ?? null,
        });
    }

    if (method === "POST" && path === "/api/auth/login") {
        checkSameOrigin(request);
        const input = parseInput(loginSchema, await readJson(request));
        return authenticate(request, environment, input, [
            "platform_admin",
            "cafe_owner",
            "cafe_manager",
            "waiter",
            "kitchen",
        ]);
    }

    if (method === "POST" && path === "/api/auth/customer/login") {
        checkSameOrigin(request);
        const body = parseInput(
            loginSchema.extend({ token: z.string().min(24).max(160) }),
            await readJson(request),
        );
        const cafe = await getCafeForTableToken(database, body.token);
        if (cafe.registration_policy === "guest")
            throw new ApiError(
                403,
                "Bu kafede müşteri hesabı kullanılamıyor.",
                "customer_accounts_disabled",
            );
        return authenticate(request, environment, body, ["customer"]);
    }

    if (method === "POST" && path === "/api/auth/customer/register") {
        checkSameOrigin(request);
        const input = parseInput(registerSchema, await readJson(request));
        const cafe = await getCafeForTableToken(database, input.token);
        if (cafe.registration_policy === "guest")
            throw new ApiError(
                403,
                "Bu kafede müşteri hesabı kullanılamıyor.",
                "customer_accounts_disabled",
            );
        await rateLimit(request, environment, "register", input.email, 5);

        const email = input.email.toLowerCase();
        const existing = await database.execute({
            sql: "SELECT id FROM users WHERE email = ? COLLATE NOCASE",
            args: [email],
        });
        if (existing.rows.length > 0)
            throw new ApiError(
                409,
                "Bu e-posta adresiyle bir hesap zaten var.",
                "email_in_use",
            );

        const now = nowIso(environment);
        const passwordHash = await hashPassword(input.password, 12);
        const inserted = await database.execute({
            sql: `INSERT INTO users (cafe_id, name, email, password_hash, role, is_active, created_at, updated_at)
                  VALUES (?, ?, ?, ?, 'customer', 1, ?, ?) RETURNING id`,
            args: [cafe.id, input.name, email, passwordHash, now, now],
        });
        const userId = Number(inserted.rows[0]?.id);
        const session = await createSession(request, userId, environment);
        await database.execute({
            sql: "INSERT INTO loyalty_accounts (cafe_id, user_id, points, completed_order_count) VALUES (?, ?, 0, 0) ON CONFLICT(cafe_id,user_id) DO NOTHING",
            args: [cafe.id, userId],
        });
        return jsonResponse(
            {
                user: {
                    id: userId,
                    name: input.name,
                    email,
                    role: "customer",
                    cafeId: cafe.id,
                },
                csrfToken: session.csrfToken,
            },
            201,
            { "set-cookie": session.cookie },
        );
    }

    if (method === "POST" && path === "/api/auth/logout") {
        const user = await currentUser(request, environment);
        if (user) {
            requireCsrf(request, user);
            const rawSession = getCookie(request, SESSION_COOKIE);
            if (rawSession)
                await database.execute({
                    sql: "DELETE FROM auth_sessions WHERE id_hash = ?",
                    args: [hash(rawSession)],
                });
        }
        return jsonResponse({ ok: true }, 200, {
            "set-cookie": sessionCookie("", request, 0),
        });
    }

    return null;
}

async function getCafeForTableToken(
    database: Awaited<ReturnType<typeof getDatabase>>,
    token: string,
) {
    const result = await database.execute({
        sql: `SELECT c.id, c.registration_policy FROM dining_tables t
              JOIN branches b ON b.id = t.branch_id JOIN cafes c ON c.id = b.cafe_id
              WHERE t.public_token_hash = ? AND t.is_active = 1 AND t.deleted_at IS NULL
                AND b.is_active = 1 AND b.deleted_at IS NULL AND c.status = 'active' AND c.deleted_at IS NULL`,
        args: [hash(token)],
    });
    const cafe = result.rows[0];
    if (!cafe)
        throw new ApiError(
            404,
            "Masa bulunamadı veya şu anda kullanılamıyor.",
            "table_not_found",
        );
    return {
        id: Number(cafe.id),
        registration_policy: String(cafe.registration_policy),
    };
}

export async function getTableByToken(
    database: Awaited<ReturnType<typeof getDatabase>>,
    token: string,
) {
    const result = await database.execute({
        sql: `SELECT t.id AS table_id, t.name AS table_name, t.branch_id, b.name AS branch_name,
                     b.address AS branch_address, b.uses_shared_menu, b.cafe_id,
                     c.name AS cafe_name, c.brand_name, c.description AS cafe_description,
                     c.logo_path, c.cover_path, c.address AS cafe_address, c.phone AS cafe_phone,
                     c.instagram, c.status AS cafe_status, c.registration_policy, c.loyalty_enabled,
                     c.points_per_100
              FROM dining_tables t JOIN branches b ON b.id = t.branch_id JOIN cafes c ON c.id = b.cafe_id
              WHERE t.public_token_hash = ? AND t.is_active = 1 AND t.deleted_at IS NULL
                AND b.is_active = 1 AND b.deleted_at IS NULL AND c.status = 'active' AND c.deleted_at IS NULL`,
        args: [hash(token)],
    });
    const table = result.rows[0];
    if (!table)
        throw new ApiError(
            404,
            "Masa bulunamadı veya şu anda kullanılamıyor.",
            "table_not_found",
        );
    return table;
}

export async function getCustomerForToken(
    request: Request,
    environment: ApiEnvironment,
    table: { cafe_id: number; registration_policy: string },
): Promise<AuthenticatedUser | null> {
    const user = await currentUser(request, environment);
    if (
        user &&
        user.role === "customer" &&
        user.cafeId === Number(table.cafe_id)
    )
        return user;
    if (table.registration_policy === "required") {
        throw new ApiError(
            401,
            "Sipariş vermek için müşteri hesabınızla giriş yapın.",
            "customer_login_required",
        );
    }
    return null;
}

export async function getTokenPolicy(
    database: Awaited<ReturnType<typeof getDatabase>>,
    token: string,
) {
    return getCafeForTableToken(database, token);
}
