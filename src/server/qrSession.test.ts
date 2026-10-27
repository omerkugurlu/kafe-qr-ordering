import { createHash } from "node:crypto";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { createClient, type Client } from "@libsql/client";
import { hashSync } from "bcryptjs";
import { handleApiRequest } from "./api.js";
import { initializeDatabase } from "./db/client.js";

let database: Client;
let fixture: {
    token: string;
    tableSessionId: number;
    productId: number;
    waiterEmail: string;
    waiterPassword: string;
};

beforeEach(async () => {
    database = createClient({ url: "file::memory:" });
    await initializeDatabase(database);
    fixture = await createFixture(database);
});

afterEach(async () => {
    await database.close();
});

function request(
    path: string,
    method = "GET",
    body?: unknown,
    options: { cookie?: string; csrf?: string } = {},
): Request {
    const headers = new Headers({ accept: "application/json" });
    if (body !== undefined) {
        headers.set("content-type", "application/json");
        headers.set("origin", "http://localhost");
    }
    if (options.cookie) headers.set("cookie", options.cookie);
    if (options.csrf) headers.set("x-csrf-token", options.csrf);
    return new Request(`http://localhost${path}`, {
        method,
        headers,
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

async function api(
    path: string,
    method = "GET",
    body?: unknown,
    options: { cookie?: string; csrf?: string } = {},
) {
    return handleApiRequest(
        request(path, method, body, options),
        { ip: "127.0.0.1" },
        { database },
    );
}

describe("QR table-session binding", () => {
    it("rejects a stale visit after the table session is closed and reopened", async () => {
        const firstMenu = await api(`/api/tables/${fixture.token}/menu`);
        expect(firstMenu.status).toBe(200);
        const firstSessionId = Number((await firstMenu.json()).session.id);
        expect(firstSessionId).toBe(fixture.tableSessionId);

        const waiter = await loginWaiter();
        const closed = await api(
            `/api/staff/sessions/${firstSessionId}/close`,
            "POST",
            {},
            waiter,
        );
        expect(closed.status).toBe(200);

        const reopened = await api(
            "/api/staff/branches/1/tables/1/open",
            "POST",
            {},
            waiter,
        );
        expect(reopened.status).toBe(201);
        const secondSessionId = Number((await reopened.json()).id);
        expect(secondSessionId).not.toBe(firstSessionId);

        const staleOrder = await api(
            `/api/tables/${fixture.token}/orders`,
            "POST",
            {
                tableSessionId: firstSessionId,
                lines: [{ productId: fixture.productId, quantity: 1 }],
            },
        );
        expect(staleOrder.status).toBe(409);
        expect(await staleOrder.json()).toMatchObject({
            code: "table_session_changed",
        });
        const orders = await database.execute({
            sql: "SELECT COUNT(*) AS count FROM orders",
        });
        expect(Number(orders.rows[0]?.count)).toBe(0);

        const freshMenu = await api(`/api/tables/${fixture.token}/menu`);
        expect(freshMenu.status).toBe(200);
        const freshSessionId = Number((await freshMenu.json()).session.id);
        expect(freshSessionId).toBe(secondSessionId);

        const freshOrder = await api(
            `/api/tables/${fixture.token}/orders`,
            "POST",
            {
                tableSessionId: freshSessionId,
                lines: [{ productId: fixture.productId, quantity: 1 }],
            },
        );
        expect(freshOrder.status).toBe(201);
        expect((await freshOrder.json()).order.totalCents).toBe(12000);
        const storedOrder = await database.execute({
            sql: "SELECT table_session_id FROM orders LIMIT 1",
        });
        expect(Number(storedOrder.rows[0]?.table_session_id)).toBe(
            secondSessionId,
        );
    });

    it("requires a session ID on every customer order request", async () => {
        const response = await api(
            `/api/tables/${fixture.token}/orders`,
            "POST",
            { lines: [{ productId: fixture.productId, quantity: 1 }] },
        );
        expect(response.status).toBe(422);
        expect(await response.json()).toMatchObject({
            code: "validation_failed",
        });
    });
});

async function loginWaiter(): Promise<{ cookie: string; csrf: string }> {
    const login = await api("/api/auth/login", "POST", {
        email: fixture.waiterEmail,
        password: fixture.waiterPassword,
    });
    expect(login.status).toBe(200);
    const cookie = login.headers.get("set-cookie")?.split(";")[0];
    const payload = await login.json();
    expect(cookie).toBeTruthy();
    return { cookie: String(cookie), csrf: String(payload.csrfToken) };
}

async function createFixture(client: Client) {
    const now = new Date().toISOString();
    await client.execute({
        sql: "INSERT INTO cafes (name,slug,status,created_at,updated_at) VALUES ('Cafe Test','cafe-test','active',?,?)",
        args: [now, now],
    });
    await client.execute({
        sql: `INSERT INTO cafe_subscriptions (cafe_id,subscription_plan_id,plan_name_snapshot,status,starts_at,ends_at,
                annual_price_snapshot_cents,max_tables_snapshot,max_branches_snapshot,features_snapshot_json,created_at,updated_at)
              SELECT 1,id,name,'active',?, ?,annual_price_cents,max_tables,max_branches,features_json,?,?
              FROM subscription_plans WHERE code='orta'`,
        args: [
            new Date(Date.now() - 86400_000).toISOString(),
            new Date(Date.now() + 365 * 86400_000).toISOString(),
            now,
            now,
        ],
    });
    await client.execute({
        sql: "INSERT INTO branches (cafe_id,name,slug,is_active,created_at,updated_at) VALUES (1,'Merkez','merkez',1,?,?)",
        args: [now, now],
    });
    const waiterPassword = "WaiterPass123";
    await client.execute({
        sql: "INSERT INTO users (cafe_id,name,email,password_hash,role,is_active,created_at,updated_at) VALUES (1,'Garson','waiter@example.test',?,'waiter',1,?,?)",
        args: [hashSync(waiterPassword, 4), now, now],
    });
    await client.execute({
        sql: "INSERT INTO branch_user (branch_id,user_id) VALUES (1,1)",
    });
    await client.execute({
        sql: "INSERT INTO menu_categories (cafe_id,name,sort_order,is_active,created_at,updated_at) VALUES (1,'Kahveler',0,1,?,?)",
        args: [now, now],
    });
    const product = await client.execute({
        sql: "INSERT INTO menu_products (cafe_id,category_id,name,price_cents,is_available,is_featured,created_at,updated_at) VALUES (1,1,'Buzlu latte',12000,1,0,?,?) RETURNING id",
        args: [now, now],
    });
    const token =
        "table-token-for-qr-session-test-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    const tokenHash = createHash("sha256").update(token).digest("hex");
    await client.execute({
        sql: "INSERT INTO dining_tables (branch_id,name,public_token_hash,public_token_encrypted,is_active,created_at,updated_at) VALUES (1,'Masa 1',?,'test-token',1,?,?)",
        args: [tokenHash, now, now],
    });
    const session = await client.execute({
        sql: "INSERT INTO table_sessions (branch_id,dining_table_id,status,qr_ordering_enabled,opened_by,opened_at,created_at,updated_at) VALUES (1,1,'open',1,1,?,?,?) RETURNING id",
        args: [now, now, now],
    });

    return {
        token,
        tableSessionId: Number(session.rows[0]?.id),
        productId: Number(product.rows[0]?.id),
        waiterEmail: "waiter@example.test",
        waiterPassword,
    };
}
