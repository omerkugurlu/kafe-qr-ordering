import { createHash, randomBytes, timingSafeEqual } from "node:crypto";
import type { Client, Transaction } from "@libsql/client";
import { initializeDatabase } from "./db/client.js";

export class ApiError extends Error {
    constructor(
        readonly status: number,
        message: string,
        readonly code = "request_failed",
        readonly details?: unknown,
    ) {
        super(message);
    }
}

export interface ApiContext {
    ip?: string;
}

export interface AuthenticatedUser {
    id: number;
    cafeId: number | null;
    name: string;
    email: string;
    role: string;
    csrfToken: string;
}

export interface ApiEnvironment {
    database?: Client;
    now?: () => Date;
    context?: ApiContext;
}

export function nowIso(environment?: ApiEnvironment): string {
    return (environment?.now?.() ?? new Date()).toISOString();
}

export function hash(value: string): string {
    return createHash("sha256").update(value).digest("hex");
}

export function newToken(bytes = 32): string {
    return randomBytes(bytes).toString("base64url");
}

export function equalsSecret(actual: string, expected: string): boolean {
    const actualBytes = Buffer.from(actual);
    const expectedBytes = Buffer.from(expected);
    return (
        actualBytes.length === expectedBytes.length &&
        timingSafeEqual(actualBytes, expectedBytes)
    );
}

export function jsonResponse(
    data: unknown,
    status = 200,
    headers?: HeadersInit,
): Response {
    const responseHeaders = new Headers(headers);
    responseHeaders.set("content-type", "application/json; charset=utf-8");
    responseHeaders.set(
        "cache-control",
        "private, no-store, max-age=0, must-revalidate",
    );
    responseHeaders.set("x-content-type-options", "nosniff");
    responseHeaders.set("referrer-policy", "strict-origin-when-cross-origin");
    responseHeaders.set("x-frame-options", "DENY");
    return new Response(JSON.stringify(data), {
        status,
        headers: responseHeaders,
    });
}

export function errorResponse(error: unknown): Response {
    if (error instanceof ApiError) {
        return jsonResponse(
            {
                error: error.message,
                code: error.code,
                ...(error.details === undefined
                    ? {}
                    : { details: error.details }),
            },
            error.status,
        );
    }

    if (error instanceof SyntaxError) {
        return jsonResponse(
            {
                error: "İstek gövdesi geçerli JSON olmalıdır.",
                code: "invalid_json",
            },
            400,
        );
    }

    return jsonResponse(
        { error: "İşlem sırasında bir hata oluştu.", code: "internal_error" },
        500,
    );
}

export async function getDatabase(environment: ApiEnvironment) {
    return initializeDatabase(environment.database);
}

export async function readJson(request: Request): Promise<unknown> {
    const contentType = request.headers.get("content-type") ?? "";
    if (!contentType.toLowerCase().includes("application/json")) {
        throw new ApiError(
            415,
            "İstek JSON biçiminde gönderilmelidir.",
            "unsupported_media_type",
        );
    }
    return request.json();
}

export function getCookie(request: Request, name: string): string | null {
    const cookieHeader = request.headers.get("cookie");
    if (!cookieHeader) return null;

    for (const cookie of cookieHeader.split(";")) {
        const [key, ...parts] = cookie.trim().split("=");
        if (key === name) return decodeURIComponent(parts.join("="));
    }
    return null;
}

export function sessionCookie(
    value: string,
    request: Request,
    maxAge: number,
): string {
    const secure =
        new URL(request.url).protocol === "https:" ||
        process.env.NETLIFY === "true";
    return [
        `sk_session=${encodeURIComponent(value)}`,
        "Path=/",
        "HttpOnly",
        "SameSite=Lax",
        `Max-Age=${maxAge}`,
        ...(secure ? ["Secure"] : []),
    ].join("; ");
}

export function clearedSessionCookie(request: Request): string {
    return sessionCookie("", request, 0);
}

export function checkSameOrigin(request: Request): void {
    const origin = request.headers.get("origin");
    if (!origin) return;

    try {
        if (new URL(origin).origin !== new URL(request.url).origin) {
            throw new ApiError(
                403,
                "Bu istek farklı bir siteden gönderilemez.",
                "origin_rejected",
            );
        }
    } catch (error) {
        if (error instanceof ApiError) throw error;
        throw new ApiError(
            403,
            "İstek kaynağı doğrulanamadı.",
            "origin_rejected",
        );
    }
}

export async function withTransaction<T>(
    environment: ApiEnvironment,
    operation: (transaction: Transaction) => Promise<T>,
): Promise<T> {
    const database = await getDatabase(environment);
    const transaction = await database.transaction("write");
    try {
        const result = await operation(transaction);
        await transaction.commit();
        return result;
    } catch (error) {
        await transaction.rollback();
        throw error;
    }
}
