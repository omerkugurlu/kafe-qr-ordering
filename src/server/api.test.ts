import { createHash } from "node:crypto";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { createClient, type Client } from "@libsql/client";
import { hashSync } from "bcryptjs";
import { handleApiRequest } from "./api.js";
import { initializeDatabase } from "./db/client.js";

let database: Client;
let fixture: {
    token: string;
    productId: number;
    optionId: number;
    waiterEmail: string;
    waiterPassword: string;
    sessionId: number;
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

describe("Netlify/Turso API customer and staff flow", () => {
    it("returns a mobile-ready menu with branch pricing and option prices", async () => {
        const response = await api(`/api/tables/${fixture.token}/menu`);
        expect(response.status).toBe(200);
        const payload = await response.json();
        expect(payload.table.name).toBe("Masa 1");
        expect(payload.products[0].priceCents).toBe(12000);
        expect(
            payload.products[0].optionGroups[0].options[0].priceDeltaCents,
        ).toBe(3000);
        expect(payload.orderingAvailable).toBe(true);
    });

    it("creates immutable line snapshots and prevents a second unacknowledged QR order", async () => {
        const response = await api(
            `/api/tables/${fixture.token}/orders`,
            "POST",
            {
                tableSessionId: fixture.sessionId,
                lines: [
                    {
                        productId: fixture.productId,
                        quantity: 2,
                        optionIds: [fixture.optionId],
                        note: "Az buzlu",
                    },
                ],
                customerNote: "Şekersiz olsun",
            },
        );
        expect(response.status).toBe(201);
        const payload = await response.json();
        expect(payload.order.totalCents).toBe(30000);
        expect(payload.order.items[0]).toMatchObject({
            name: "Buzlu latte",
            unitPriceCents: 15000,
            quantity: 2,
            lineTotalCents: 30000,
            note: "Az buzlu",
        });
        expect(payload.order.items[0].options[0]).toMatchObject({
            group: "Şurup",
            name: "Karamel",
            priceDeltaCents: 3000,
        });

        await database.execute({
            sql: "UPDATE menu_products SET name=?, price_cents=? WHERE id=?",
            args: ["Yeni ad", 20000, fixture.productId],
        });
        const duplicate = await api(
            `/api/tables/${fixture.token}/orders`,
            "POST",
            {
                tableSessionId: fixture.sessionId,
                lines: [
                    {
                        productId: fixture.productId,
                        quantity: 1,
                        optionIds: [],
                    },
                ],
            },
        );
        expect(duplicate.status).toBe(409);
        const snapshot = await database.execute({
            sql: "SELECT name_snapshot, unit_price_cents FROM order_items WHERE order_id=?",
            args: [payload.order.id],
        });
        expect(snapshot.rows[0]).toMatchObject({
            name_snapshot: "Buzlu latte",
            unit_price_cents: 15000,
        });
    });

    it("lets only one waiter claim a service request and serializes partial payments", async () => {
        await database.execute({
            sql: `INSERT INTO service_requests (branch_id,dining_table_id,table_session_id,type,status,created_at,updated_at)
                  VALUES (1,1,?,'bill','open',CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)`,
            args: [fixture.sessionId],
        });
        const login = await api("/api/auth/login", "POST", {
            email: fixture.waiterEmail,
            password: fixture.waiterPassword,
        });
        expect(login.status).toBe(200);
        const cookie = login.headers.get("set-cookie")?.split(";")[0];
        const loginPayload = await login.json();
        expect(cookie).toBeTruthy();

        const claim = await api(
            "/api/staff/service-requests/1/claim",
            "POST",
            {},
            { cookie, csrf: loginPayload.csrfToken },
        );
        expect(claim.status).toBe(200);
        const secondWaiter = await database.execute({
            sql: "INSERT INTO users (cafe_id,name,email,password_hash,role) VALUES (1,'Diğer','second@example.test',?,'waiter') RETURNING id",
            args: [hashSync("Password123", 4)],
        });
        await database.execute({
            sql: "INSERT INTO branch_user (branch_id,user_id) VALUES (1,?)",
            args: [Number(secondWaiter.rows[0].id)],
        });
        const secondLogin = await api("/api/auth/login", "POST", {
            email: "second@example.test",
            password: "Password123",
        });
        const secondCookie = secondLogin.headers
            .get("set-cookie")
            ?.split(";")[0];
        const secondPayload = await secondLogin.json();
        const conflict = await api(
            "/api/staff/service-requests/1/claim",
            "POST",
            {},
            { cookie: secondCookie, csrf: secondPayload.csrfToken },
        );
        expect(conflict.status).toBe(409);

        const order = await api(`/api/tables/${fixture.token}/orders`, "POST", {
            tableSessionId: fixture.sessionId,
            lines: [{ productId: fixture.productId, quantity: 1 }],
        });
        const orderPayload = await order.json();
        const accepted = await api(
            `/api/staff/orders/${orderPayload.order.id}/status`,
            "POST",
            { status: "accepted" },
            { cookie, csrf: loginPayload.csrfToken },
        );
        expect(accepted.status).toBe(200);
        const served = await api(
            `/api/staff/orders/${orderPayload.order.id}/status`,
            "POST",
            { status: "served" },
            { cookie, csrf: loginPayload.csrfToken },
        );
        expect(served.status).toBe(200);
        const half = await api(
            `/api/staff/sessions/${fixture.sessionId}/payments`,
            "POST",
            { method: "cash", amountCents: 6000 },
            { cookie, csrf: loginPayload.csrfToken },
        );
        expect(half.status).toBe(201);
        const overshoot = await api(
            `/api/staff/sessions/${fixture.sessionId}/payments`,
            "POST",
            { method: "card", amountCents: 6001 },
            { cookie, csrf: loginPayload.csrfToken },
        );
        expect(overshoot.status).toBe(422);
        const rest = await api(
            `/api/staff/sessions/${fixture.sessionId}/payments`,
            "POST",
            { method: "card", amountCents: 6000 },
            { cookie, csrf: loginPayload.csrfToken },
        );
        expect(rest.status).toBe(201);
        const session = await database.execute({
            sql: "SELECT status,qr_ordering_enabled FROM table_sessions WHERE id=?",
            args: [fixture.sessionId],
        });
        const paidOrder = await database.execute({
            sql: "SELECT status FROM orders WHERE id=?",
            args: [orderPayload.order.id],
        });
        expect(session.rows[0]).toMatchObject({
            status: "closed",
            qr_ordering_enabled: 0,
        });
        expect(paidOrder.rows[0]?.status).toBe("paid");
        const board = await api("/api/staff/board", "GET", undefined, {
            cookie,
        });
        expect(board.status).toBe(200);
        const boardPayload = await board.json();
        expect(boardPayload).not.toHaveProperty("todayCollectedCents");
        expect(boardPayload.tables[0].isOpen).toBe(false);
        const closedBill = await api("/api/tables/" + fixture.token + "/bill");
        expect(closedBill.status).toBe(200);
        expect((await closedBill.json()).session).toBeNull();
    });

    it("serves the branch-scoped waiter catalog and prevents cross-branch access", async () => {
        const login = await api("/api/auth/login", "POST", {
            email: fixture.waiterEmail,
            password: fixture.waiterPassword,
        });
        const cookie = login.headers.get("set-cookie")?.split(";")[0];
        const catalog = await api(
            "/api/staff/branches/1/menu",
            "GET",
            undefined,
            {
                cookie,
            },
        );
        expect(catalog.status).toBe(200);
        expect((await catalog.json()).products[0]).toMatchObject({
            id: fixture.productId,
            name: "Buzlu latte",
            priceCents: 12000,
        });

        const now = new Date().toISOString();
        await database.execute({
            sql: "INSERT INTO branches (cafe_id,name,slug,uses_shared_menu,is_active,created_at,updated_at) VALUES (1,'Uzak Şube','uzak-sube',1,1,?,?)",
            args: [now, now],
        });
        const forbidden = await api(
            "/api/staff/branches/2/menu",
            "GET",
            undefined,
            {
                cookie,
            },
        );
        expect(forbidden.status).toBe(403);
    });

    it("lets cafe owners manage a scoped menu, copy it to a private branch, and issue durable table QR tokens", async () => {
        const now = new Date().toISOString();
        await database.execute({
            sql: "INSERT INTO users (cafe_id,name,email,password_hash,role,is_active,created_at,updated_at) VALUES (1,'Sahip','owner@example.test',?,'cafe_owner',1,?,?)",
            args: [hashSync("OwnerPass123", 4), now, now],
        });
        const login = await api("/api/auth/login", "POST", {
            email: "owner@example.test",
            password: "OwnerPass123",
        });
        const cookie = login.headers.get("set-cookie")?.split(";")[0];
        const csrf = (await login.json()).csrfToken;

        const overview = await api("/api/manage/overview", "GET", undefined, {
            cookie,
        });
        expect(overview.status).toBe(200);
        expect((await overview.json()).metrics).toHaveProperty(
            "todayCollectedCents",
        );

        const category = await api(
            "/api/manage/categories",
            "POST",
            {
                branchId: 1,
                name: "Soğuk içecekler",
            },
            { cookie, csrf },
        );
        expect(category.status).toBe(201);
        const categoryId = (await category.json()).id as number;
        const product = await api(
            "/api/manage/products",
            "POST",
            {
                branchId: 1,
                categoryId,
                name: "Vanilyalı cold brew",
                priceCents: 14000,
                optionGroups: [
                    {
                        name: "Şurup",
                        selectionMode: "multiple",
                        minSelections: 0,
                        maxSelections: 2,
                        options: [{ name: "Vanilya", priceDeltaCents: 2500 }],
                    },
                ],
            },
            { cookie, csrf },
        );
        expect(product.status).toBe(201);
        const productId = (await product.json()).id as number;
        const updated = await api(
            `/api/manage/products/${productId}`,
            "PATCH",
            {
                branchId: 1,
                priceCents: 15500,
            },
            { cookie, csrf },
        );
        expect(updated.status).toBe(200);
        const menu = await api(
            "/api/manage/menu?branchId=1",
            "GET",
            undefined,
            { cookie },
        );
        const menuPayload = await menu.json();
        expect(
            menuPayload.products.find(
                (entry: { id: number }) => entry.id === productId,
            ).priceCents,
        ).toBe(15500);

        const branch = await api(
            "/api/manage/branches",
            "POST",
            { name: "Sahil" },
            { cookie, csrf },
        );
        const branchId = (await branch.json()).id as number;
        const copied = await api(
            `/api/manage/branches/${branchId}/menu`,
            "POST",
            {
                mode: "independent",
                sourceBranchId: 1,
            },
            { cookie, csrf },
        );
        expect(copied.status).toBe(200);
        expect(
            (
                await api(
                    `/api/manage/menu?branchId=${branchId}`,
                    "GET",
                    undefined,
                    { cookie },
                )
            ).status,
        ).toBe(200);

        const table = await api(
            `/api/manage/branches/${branchId}/tables`,
            "POST",
            { name: "Teras 1" },
            { cookie, csrf },
        );
        expect(table.status).toBe(201);
        const createdTable = await table.json();
        expect(createdTable.qrUrl).toMatch(/^\/masa\/[A-Za-z0-9_-]+$/);
        const persisted = await database.execute({
            sql: "SELECT public_token_hash,public_token_encrypted FROM dining_tables WHERE id=?",
            args: [createdTable.id],
        });
        expect(persisted.rows[0]?.public_token_encrypted).not.toContain(
            createdTable.token,
        );
        const tableList = await api(
            `/api/manage/branches/${branchId}/tables`,
            "GET",
            undefined,
            { cookie },
        );
        expect((await tableList.json()).tables[0].qrUrl).toBe(
            createdTable.qrUrl,
        );
    });

    it("provides platform cafe management and one-time owner password resets", async () => {
        const now = new Date().toISOString();
        await database.execute({
            sql: "INSERT INTO users (name,email,password_hash,role,is_active,created_at,updated_at) VALUES ('Platform','admin@example.test',?,'platform_admin',1,?,?)",
            args: [hashSync("AdminPass123", 4), now, now],
        });
        const login = await api("/api/auth/login", "POST", {
            email: "admin@example.test",
            password: "AdminPass123",
        });
        const cookie = login.headers.get("set-cookie")?.split(";")[0];
        const csrf = (await login.json()).csrfToken;
        const create = await api(
            "/api/platform/cafes",
            "POST",
            {
                cafeName: "Porto Kahve",
                branchName: "Lefkoşa",
                ownerName: "Deniz Sahin",
                ownerEmail: "deniz@example.test",
            },
            { cookie, csrf },
        );
        expect(create.status).toBe(201);
        const credentials = await create.json();
        const owner = await database.execute({
            sql: "SELECT id,password_hash FROM users WHERE email=?",
            args: [credentials.ownerEmail],
        });
        expect(owner.rows[0]?.password_hash).not.toBe(
            credentials.temporaryPassword,
        );
        const reset = await api(
            `/api/platform/cafes/${credentials.cafeId}/reset-owner`,
            "POST",
            { ownerId: Number(owner.rows[0]?.id) },
            { cookie, csrf },
        );
        expect(reset.status).toBe(200);
        const changed = await database.execute({
            sql: "SELECT password_hash FROM users WHERE id=?",
            args: [Number(owner.rows[0]?.id)],
        });
        expect(changed.rows[0]?.password_hash).not.toBe(
            owner.rows[0]?.password_hash,
        );
        const suspended = await api(
            `/api/platform/cafes/${credentials.cafeId}`,
            "PATCH",
            { status: "suspended" },
            { cookie, csrf },
        );
        expect(suspended.status).toBe(200);
        const dashboard = await api(
            "/api/platform/dashboard",
            "GET",
            undefined,
            { cookie },
        );
        const payload = await dashboard.json();
        expect(
            payload.cafes.find(
                (cafe: { id: number }) => cafe.id === credentials.cafeId,
            ).status,
        ).toBe("suspended");
        expect(payload.metrics).toHaveProperty("activeTables");
    });

    it("lets cafe owners manage settings and branch-scoped staff without exposing cross-cafe accounts", async () => {
        const now = new Date().toISOString();
        await database.execute({
            sql: "INSERT INTO users (cafe_id,name,email,password_hash,role,is_active,created_at,updated_at) VALUES (1,'Sahip','owner@example.test',?,'cafe_owner',1,?,?)",
            args: [hashSync("OwnerPass123", 4), now, now],
        });
        const login = await api("/api/auth/login", "POST", {
            email: "owner@example.test",
            password: "OwnerPass123",
        });
        const cookie = login.headers.get("set-cookie")?.split(";")[0];
        const csrf = (await login.json()).csrfToken;

        const settings = await api(
            "/api/manage/settings",
            "PATCH",
            {
                registrationPolicy: "required",
                loyaltyEnabled: true,
                pointsPer100: 3,
            },
            { cookie, csrf },
        );
        expect(settings.status).toBe(200);
        const cafe = await database.execute({
            sql: "SELECT registration_policy,loyalty_enabled,points_per_100 FROM cafes WHERE id=1",
        });
        expect(cafe.rows[0]).toMatchObject({
            registration_policy: "required",
            loyalty_enabled: 1,
            points_per_100: 3,
        });
        const campaign = await api(
            "/api/manage/loyalty-campaigns",
            "POST",
            {
                name: "10. kahve yüzde 10",
                type: "order_count",
                threshold: 10,
                pointsRequired: 0,
                rewardType: "percent",
                rewardCents: 0,
                rewardBasisPoints: 1000,
                minimumOrderCents: 0,
            },
            { cookie, csrf },
        );
        expect(campaign.status).toBe(201);
        const campaignId = (await campaign.json()).id as number;
        const campaigns = await api(
            "/api/manage/loyalty-campaigns",
            "GET",
            undefined,
            { cookie },
        );
        expect((await campaigns.json()).campaigns[0]).toMatchObject({
            id: campaignId,
            name: "10. kahve yüzde 10",
            threshold: 10,
            rewardBasisPoints: 1000,
            active: true,
        });
        const campaignOff = await api(
            `/api/manage/loyalty-campaigns/${campaignId}`,
            "PATCH",
            { active: false },
            { cookie, csrf },
        );
        expect(campaignOff.status).toBe(200);
        const invalidCampaign = await api(
            "/api/manage/loyalty-campaigns",
            "POST",
            {
                name: "Geçersiz indirim",
                type: "points",
                threshold: 0,
                pointsRequired: 10,
                rewardType: "percent",
                rewardCents: 0,
                rewardBasisPoints: 10001,
                minimumOrderCents: 0,
            },
            { cookie, csrf },
        );
        expect(invalidCampaign.status).toBe(422);

        await database.execute({
            sql: "INSERT INTO cafes (name,slug,created_at,updated_at) VALUES ('Başka Kafe','baska-kafe',?,?)",
            args: [now, now],
        });
        await database.execute({
            sql: "INSERT INTO branches (cafe_id,name,slug,created_at,updated_at) VALUES (2,'Yabancı Şube','yabanci-sube',?,?)",
            args: [now, now],
        });
        const crossCafeAssignment = await api(
            "/api/manage/staff",
            "POST",
            {
                name: "İzinsiz",
                email: "foreign@example.test",
                role: "waiter",
                branchIds: [2],
            },
            { cookie, csrf },
        );
        expect(crossCafeAssignment.status).toBe(422);

        const created = await api(
            "/api/manage/staff",
            "POST",
            {
                name: "Buse Garson",
                email: "buse@example.test",
                role: "waiter",
                branchIds: [1],
            },
            { cookie, csrf },
        );
        expect(created.status).toBe(201);
        const credentials = await created.json();
        const stored = await database.execute({
            sql: "SELECT id,password_hash FROM users WHERE email='buse@example.test'",
        });
        expect(stored.rows[0]?.password_hash).not.toBe(
            credentials.temporaryPassword,
        );

        const listed = await api("/api/manage/staff", "GET", undefined, {
            cookie,
        });
        expect((await listed.json()).staff).toContainEqual(
            expect.objectContaining({
                id: Number(stored.rows[0]?.id),
                email: "buse@example.test",
                role: "waiter",
                branches: [{ id: 1, name: "Merkez" }],
            }),
        );
        const reset = await api(
            `/api/manage/staff/${String(stored.rows[0]?.id)}/reset-password`,
            "POST",
            {},
            { cookie, csrf },
        );
        expect(reset.status).toBe(200);
        const workerLogin = await api("/api/auth/login", "POST", {
            email: "buse@example.test",
            password: (await reset.json()).temporaryPassword,
        });
        expect(workerLogin.status).toBe(200);
        const workerCookie = workerLogin.headers
            .get("set-cookie")
            ?.split(";")[0];
        const ownerView = await api("/api/manage/overview", "GET", undefined, {
            cookie,
        });
        expect((await ownerView.json()).metrics.todayCollectedCents).toBe(0);
        const worker = await database.execute({
            sql: "SELECT id FROM users WHERE email='buse@example.test'",
        });
        const deactivate = await api(
            `/api/manage/staff/${String(worker.rows[0]?.id)}`,
            "PATCH",
            { active: false },
            { cookie, csrf },
        );
        expect(deactivate.status).toBe(200);
        const workerSession = await api("/api/auth/me", "GET", undefined, {
            cookie: workerCookie,
        });
        expect((await workerSession.json()).user).toBeNull();
    });

    it("requires eight-character customer passwords and validates CSRF for authenticated writes", async () => {
        const shortPassword = await api("/api/auth/customer/register", "POST", {
            token: fixture.token,
            name: "Test Customer",
            email: "customer@example.test",
            password: "1234567",
        });
        expect(shortPassword.status).toBe(422);

        const login = await api("/api/auth/login", "POST", {
            email: fixture.waiterEmail,
            password: fixture.waiterPassword,
        });
        const cookie = login.headers.get("set-cookie")?.split(";")[0];
        const noCsrf = await api(
            "/api/staff/sessions/1/qr-ordering",
            "POST",
            { enabled: false },
            { cookie },
        );
        expect(noCsrf.status).toBe(403);
    });

    it("shows the cafe owner's subscription and blocks the active or duplicate pending plan", async () => {
        await database.execute({
            sql: "DELETE FROM cafe_subscriptions WHERE cafe_id=1",
        });
        const owner = await createOwnerSession();
        const entry = await planByCode("giris");
        const middle = await planByCode("orta");
        const current = await createSubscription(entry.id, {
            cafeId: 1,
            ownerId: owner.userId,
            features: {
                ...JSON.parse(String(entry.features_json)),
                reports: true,
            },
        });

        const overview = await api(
            "/api/manage/subscription",
            "GET",
            undefined,
            {
                cookie: owner.cookie,
            },
        );
        expect(overview.status).toBe(200);
        expect(await overview.json()).toMatchObject({
            subscription: {
                id: current.id,
                planName: "Giriş",
                annualPriceCents: 1000000,
                maxTables: 10,
                maxBranches: 2,
                features: { reports: true, customer_accounts: false },
            },
            usage: { tables: 1, branches: 1 },
            renewablePlanId: entry.id,
        });

        const requested = await api(
            "/api/manage/subscription-requests",
            "POST",
            { planId: middle.id, cafeId: 999 },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(requested.status).toBe(201);
        const requestBody = await requested.json();
        expect(requestBody.request).toMatchObject({
            planName: "Orta",
            status: "pending",
        });
        expect(requestBody.request.cafeId).toBe(1);

        const duplicate = await api(
            "/api/manage/subscription-requests",
            "POST",
            { planId: entry.id },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(duplicate.status).toBe(409);
        const rows = await database.execute({
            sql: "SELECT cafe_id,requested_by,pending_cafe_id,subscription_plan_id,features_snapshot_json FROM cafe_subscription_requests",
        });
        expect(rows.rows).toHaveLength(1);
        expect(rows.rows[0]).toMatchObject({
            cafe_id: 1,
            pending_cafe_id: 1,
            requested_by: owner.userId,
            subscription_plan_id: middle.id,
        });
        expect(
            JSON.parse(String(rows.rows[0]?.features_snapshot_json)),
        ).toEqual(JSON.parse(String(middle.features_json)));
    });

    it("allows a cafe owner to request renewal of the current plan", async () => {
        await database.execute({
            sql: "DELETE FROM cafe_subscriptions WHERE cafe_id=1",
        });
        const owner = await createOwnerSession();
        const entry = await planByCode("giris");
        const current = await createSubscription(entry.id, {
            cafeId: 1,
            ownerId: owner.userId,
        });

        const requested = await api(
            "/api/manage/subscription-requests",
            "POST",
            { planId: entry.id },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(requested.status).toBe(201);
        expect((await requested.json()).request).toMatchObject({
            planId: entry.id,
            status: "pending",
        });

        const admin = await createPlatformSession();
        const requestRows = await database.execute({
            sql: "SELECT id FROM cafe_subscription_requests WHERE pending_cafe_id=1",
        });
        const requestId = Number(requestRows.rows[0]?.id);
        const approved = await api(
            `/api/platform/subscription-requests/${requestId}/approve`,
            "POST",
            { paymentReceived: true },
            { cookie: admin.cookie, csrf: admin.csrf },
        );
        expect(approved.status).toBe(200);
        const periods = await database.execute({
            sql: "SELECT starts_at,ends_at FROM cafe_subscriptions WHERE cafe_id=1 AND id<>? ORDER BY id DESC LIMIT 1",
            args: [current.id],
        });
        expect(periods.rows[0]?.starts_at).toBe(current.endsAt);
        expect(Date.parse(String(periods.rows[0]?.ends_at))).toBeGreaterThan(
            Date.parse(current.endsAt),
        );
    });

    it("requires manual payment confirmation and approves a request into a snapshotted future term", async () => {
        await database.execute({
            sql: "DELETE FROM cafe_subscriptions WHERE cafe_id=1",
        });
        const owner = await createOwnerSession();
        const admin = await createPlatformSession();
        const entry = await planByCode("giris");
        const middle = await planByCode("orta");
        const current = await createSubscription(entry.id, {
            cafeId: 1,
            ownerId: owner.userId,
        });
        const requestResponse = await api(
            "/api/manage/subscription-requests",
            "POST",
            { planId: middle.id },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        const requestId = Number((await requestResponse.json()).request.id);

        const list = await api(
            "/api/platform/subscription-requests",
            "GET",
            undefined,
            {
                cookie: admin.cookie,
            },
        );
        expect(list.status).toBe(200);
        expect((await list.json()).requests).toEqual(
            expect.arrayContaining([
                expect.objectContaining({
                    id: requestId,
                    cafeName: "Cafe Test",
                    status: "pending",
                }),
            ]),
        );

        const unpaid = await api(
            `/api/platform/subscription-requests/${requestId}/approve`,
            "POST",
            { paymentReceived: false },
            { cookie: admin.cookie, csrf: admin.csrf },
        );
        expect(unpaid.status).toBe(422);
        expect(
            (
                await database.execute({
                    sql: "SELECT COUNT(*) AS count FROM cafe_subscriptions",
                })
            ).rows[0]?.count,
        ).toBe(1);

        const approved = await api(
            `/api/platform/subscription-requests/${requestId}/approve`,
            "POST",
            { paymentReceived: true },
            { cookie: admin.cookie, csrf: admin.csrf },
        );
        expect(approved.status).toBe(200);
        const requestRow = await database.execute({
            sql: "SELECT status,reviewed_by,cafe_subscription_id,pending_cafe_id FROM cafe_subscription_requests WHERE id=?",
            args: [requestId],
        });
        expect(requestRow.rows[0]).toMatchObject({
            status: "approved",
            reviewed_by: admin.userId,
            pending_cafe_id: null,
        });
        const next = await database.execute({
            sql: "SELECT * FROM cafe_subscriptions WHERE id=?",
            args: [Number(requestRow.rows[0]?.cafe_subscription_id)],
        });
        expect(next.rows[0]).toMatchObject({
            cafe_id: 1,
            subscription_plan_id: middle.id,
            plan_name_snapshot: "Orta",
            annual_price_snapshot_cents: Number(middle.annual_price_cents),
            max_tables_snapshot: 20,
            max_branches_snapshot: null,
            status: "active",
            activated_by: admin.userId,
        });
        expect(String(next.rows[0]?.starts_at)).toBe(current.endsAt);
        expect(
            JSON.parse(String(next.rows[0]?.features_snapshot_json)),
        ).toEqual(JSON.parse(String(middle.features_json)));
        const secondApproval = await api(
            `/api/platform/subscription-requests/${requestId}/approve`,
            "POST",
            { paymentReceived: true },
            { cookie: admin.cookie, csrf: admin.csrf },
        );
        expect(secondApproval.status).toBe(409);
        expect(
            (
                await database.execute({
                    sql: "SELECT COUNT(*) AS count FROM cafe_subscriptions",
                })
            ).rows[0]?.count,
        ).toBe(2);
    });

    it("lets only platform admins edit yearly plans and cafe package terms while preserving history", async () => {
        await database.execute({
            sql: "DELETE FROM cafe_subscriptions WHERE cafe_id=1",
        });
        const owner = await createOwnerSession();
        const admin = await createPlatformSession();
        const entry = await planByCode("giris");
        const middle = await planByCode("orta");
        const original = await createSubscription(entry.id, {
            cafeId: 1,
            ownerId: owner.userId,
            features: {
                ...JSON.parse(String(entry.features_json)),
                reports: true,
            },
        });
        const denied = await api(
            "/api/platform/subscription-plans",
            "GET",
            undefined,
            {
                cookie: owner.cookie,
            },
        );
        expect(denied.status).toBe(403);

        const plans = await api(
            "/api/platform/subscription-plans",
            "GET",
            undefined,
            {
                cookie: admin.cookie,
            },
        );
        expect(plans.status).toBe(200);
        expect((await plans.json()).plans).toHaveLength(3);

        const savedPlan = await api(
            `/api/platform/subscription-plans/${entry.id}`,
            "PATCH",
            {
                name: "Başlangıç",
                annualPriceCents: 1499900,
                maxTables: 12,
                maxBranches: null,
                features: {
                    customer_accounts: true,
                    coupons: false,
                    loyalty: false,
                    reports: false,
                    announcements: true,
                },
                isActive: true,
            },
            { cookie: admin.cookie, csrf: admin.csrf },
        );
        expect(savedPlan.status).toBe(200);
        const oldSnapshot = await database.execute({
            sql: "SELECT plan_name_snapshot,annual_price_snapshot_cents,max_tables_snapshot,max_branches_snapshot,features_snapshot_json FROM cafe_subscriptions WHERE id=?",
            args: [original.id],
        });
        expect(oldSnapshot.rows[0]).toMatchObject({
            plan_name_snapshot: "Giriş",
            annual_price_snapshot_cents: 1000000,
            max_tables_snapshot: 10,
            max_branches_snapshot: 2,
        });
        expect(
            JSON.parse(String(oldSnapshot.rows[0]?.features_snapshot_json))
                .reports,
        ).toBe(true);

        const endDate = new Date(Date.now() + 120 * 86400_000)
            .toISOString()
            .slice(0, 10);
        const override = await api(
            "/api/platform/cafes/1/subscription",
            "PATCH",
            {
                subscriptionId: original.id,
                planId: middle.id,
                endsOn: endDate,
                reason: "Kafe talebiyle paket ve tarih güncellendi.",
            },
            { cookie: admin.cookie, csrf: admin.csrf },
        );
        expect(override.status).toBe(200);
        const records = await database.execute({
            sql: "SELECT id,status,subscription_plan_id,plan_name_snapshot,annual_price_snapshot_cents,max_tables_snapshot,max_branches_snapshot,features_snapshot_json FROM cafe_subscriptions WHERE cafe_id=1 ORDER BY id",
        });
        expect(records.rows).toHaveLength(2);
        expect(records.rows[0]).toMatchObject({
            id: original.id,
            status: "replaced",
        });
        expect(records.rows[1]).toMatchObject({
            subscription_plan_id: middle.id,
            plan_name_snapshot: "Orta",
            annual_price_snapshot_cents: Number(middle.annual_price_cents),
            max_tables_snapshot: 20,
            max_branches_snapshot: null,
            status: "active",
        });
        expect(
            JSON.parse(String(records.rows[1]?.features_snapshot_json)),
        ).toEqual(JSON.parse(String(middle.features_json)));
        expect(
            await database.execute({
                sql: "SELECT 1 FROM activity_logs WHERE action='subscription.admin_overridden' AND user_id=? AND cafe_id=1",
                args: [admin.userId],
            }),
        ).toMatchObject({ rows: [expect.any(Object)] });
    });

    it("enforces the active package's branch, table, and customer-account limits on API writes", async () => {
        await database.execute({
            sql: "DELETE FROM cafe_subscriptions WHERE cafe_id=1",
        });
        const owner = await createOwnerSession();
        const entry = await planByCode("giris");
        const current = await createSubscription(entry.id, {
            cafeId: 1,
            ownerId: owner.userId,
            maxTables: 1,
            maxBranches: 1,
            features: {
                ...JSON.parse(String(entry.features_json)),
                customer_accounts: false,
            },
        });

        const tableLimit = await api(
            "/api/manage/branches/1/tables",
            "POST",
            { name: "Masa sınır" },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(tableLimit.status).toBe(403);
        const branchLimit = await api(
            "/api/manage/branches",
            "POST",
            { name: "İkinci şube" },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(branchLimit.status).toBe(403);
        const register = await api("/api/auth/customer/register", "POST", {
            token: fixture.token,
            name: "Yeni Müşteri",
            email: "new-customer@example.test",
            password: "CustomerPass123",
        });
        expect(register.status).toBe(403);

        await database.execute({
            sql: "UPDATE cafe_subscriptions SET max_tables_snapshot=2,max_branches_snapshot=NULL WHERE id=?",
            args: [current.id],
        });
        const createdTable = await api(
            "/api/manage/branches/1/tables",
            "POST",
            { name: "Masa iki" },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(createdTable.status).toBe(201);
        const nextTable = await api(
            "/api/manage/branches/1/tables",
            "POST",
            { name: "Masa üç" },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(nextTable.status).toBe(403);
        const secondBranch = await api(
            "/api/manage/branches",
            "POST",
            { name: "İkinci şube" },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(secondBranch.status).toBe(201);
    });
});

describe("cafe owner subscription features", () => {
    it("reports paid sales and manages branch announcements", async () => {
        const owner = await createOwnerSession();
        const now = new Date().toISOString();
        const day = new Intl.DateTimeFormat("sv-SE", {
            timeZone: "Europe/Istanbul",
            year: "numeric",
            month: "2-digit",
            day: "2-digit",
        }).format(new Date());
        const paidAt = new Date(`${day}T01:00:00+03:00`).toISOString();
        const paid = await database.execute({
            sql: `INSERT INTO orders (cafe_id,branch_id,table_session_id,order_number,status,subtotal_cents,total_cents,paid_at,created_at,updated_at)
                  VALUES (1,1,?,'RPT-001','paid',12000,12000,?,?,?) RETURNING id`,
            args: [fixture.sessionId, paidAt, now, now],
        });
        const orderId = Number(paid.rows[0]?.id);
        await database.execute({
            sql: "INSERT INTO order_items (order_id,name_snapshot,unit_price_cents,quantity,line_total_cents,created_at,updated_at) VALUES (?,'Buzlu latte',12000,1,12000,?,?)",
            args: [orderId, now, now],
        });
        await database.execute({
            sql: `INSERT INTO orders (cafe_id,branch_id,table_session_id,order_number,status,subtotal_cents,total_cents,cancelled_at,created_at,updated_at)
                  VALUES (1,1,?,'RPT-002','cancelled',5000,5000,?,?,?)`,
            args: [fixture.sessionId, paidAt, now, now],
        });

        const report = await api(
            `/api/manage/reports?from=${day}&to=${day}`,
            "GET",
            undefined,
            { cookie: owner.cookie },
        );
        expect(report.status).toBe(200);
        expect(await report.json()).toMatchObject({
            summary: {
                paidOrders: 1,
                revenueCents: 12000,
                averageOrderCents: 12000,
                cancelledOrders: 1,
            },
            daily: [{ day, paidOrders: 1, revenueCents: 12000 }],
            topProducts: [
                { name: "Buzlu latte", quantity: 1, revenueCents: 12000 },
            ],
        });

        const created = await api(
            "/api/manage/announcements",
            "POST",
            {
                type: "promotion",
                title: "Öğle kampanyası",
                body: "Saat 15.00'e kadar geçerli.",
                branchId: 1,
                startsAt: null,
                endsAt: null,
                isActive: true,
            },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(created.status).toBe(201);
        const announcementId = Number((await created.json()).id);
        const menu = await api(`/api/tables/${fixture.token}/menu`);
        expect((await menu.json()).announcements).toMatchObject([
            { id: announcementId, title: "Öğle kampanyası" },
        ]);

        const invalidBranch = await api(
            "/api/manage/announcements",
            "POST",
            {
                title: "Yanlış şube",
                branchId: 999,
                startsAt: null,
                endsAt: null,
                isActive: true,
            },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(invalidBranch.status).toBe(422);

        const disabled = await api(
            `/api/manage/announcements/${announcementId}`,
            "PATCH",
            {
                type: "promotion",
                title: "Öğle kampanyası",
                body: "Saat 15.00'e kadar geçerli.",
                branchId: 1,
                startsAt: null,
                endsAt: null,
                isActive: false,
            },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(disabled.status).toBe(200);
        expect((await api(`/api/tables/${fixture.token}/menu`)).status).toBe(
            200,
        );
        const hiddenMenu = await api(`/api/tables/${fixture.token}/menu`);
        expect((await hiddenMenu.json()).announcements).toEqual([]);

        const wrongRoleLogin = await api("/api/auth/login", "POST", {
            email: fixture.waiterEmail,
            password: fixture.waiterPassword,
        });
        const wrongRoleCookie =
            wrongRoleLogin.headers.get("set-cookie")?.split(";")[0] ?? "";
        const forbiddenReport = await api(
            "/api/manage/reports",
            "GET",
            undefined,
            { cookie: wrongRoleCookie },
        );
        expect(forbiddenReport.status).toBe(403);
    });

    it("creates usable coupons and enforces package features", async () => {
        const owner = await createOwnerSession();
        const created = await api(
            "/api/manage/coupons",
            "POST",
            {
                code: "welcome15",
                type: "percent",
                value: 15,
                minSubtotalCents: 10000,
                usageLimit: 5,
                startsAt: null,
                endsAt: null,
                isActive: true,
            },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(created.status).toBe(201);

        const check = await api(
            `/api/tables/${fixture.token}/coupons/check`,
            "POST",
            { code: "welcome15", subtotalCents: 20000 },
        );
        expect(check.status).toBe(200);
        expect(await check.json()).toMatchObject({
            valid: true,
            discountCents: 3000,
        });

        const duplicate = await api(
            "/api/manage/coupons",
            "POST",
            {
                code: "WELCOME15",
                type: "fixed",
                value: 1000,
                minSubtotalCents: 0,
                usageLimit: null,
                startsAt: null,
                endsAt: null,
                isActive: true,
            },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(duplicate.status).toBe(409);

        await database.execute({
            sql: "UPDATE cafe_subscriptions SET features_snapshot_json=? WHERE cafe_id=1",
            args: [
                JSON.stringify({
                    customer_accounts: true,
                    coupons: false,
                    loyalty: true,
                    reports: false,
                    announcements: false,
                }),
            ],
        });
        const gatedList = await api("/api/manage/coupons", "GET", undefined, {
            cookie: owner.cookie,
        });
        expect(gatedList.status).toBe(403);
        const gatedCheck = await api(
            `/api/tables/${fixture.token}/coupons/check`,
            "POST",
            { code: "WELCOME15", subtotalCents: 20000 },
        );
        expect(gatedCheck.status).toBe(403);
    });

    it("limits report periods to valid ranges", async () => {
        const owner = await createOwnerSession();
        const invalid = await api(
            "/api/manage/reports?from=2026-02-30&to=2026-03-02",
            "GET",
            undefined,
            { cookie: owner.cookie },
        );
        expect(invalid.status).toBe(422);
    });

    it("normalizes offset timestamps before storing owner announcements", async () => {
        const owner = await createOwnerSession();
        const response = await api(
            "/api/manage/announcements",
            "POST",
            {
                title: "Saat dönüşümü",
                startsAt: "2026-01-01T10:00:00+03:00",
                endsAt: "2026-01-01T08:00:00Z",
                isActive: true,
            },
            { cookie: owner.cookie, csrf: owner.csrf },
        );
        expect(response.status).toBe(201);
        const stored = await database.execute({
            sql: "SELECT starts_at,ends_at FROM announcements WHERE title='Saat dönüşümü'",
        });
        expect(stored.rows[0]).toMatchObject({
            starts_at: "2026-01-01T07:00:00.000Z",
            ends_at: "2026-01-01T08:00:00.000Z",
        });
    });
});

async function createOwnerSession() {
    const now = new Date().toISOString();
    await database.execute({
        sql: "INSERT INTO users (cafe_id,name,email,password_hash,role,is_active,created_at,updated_at) VALUES (1,'Kafe Sahibi','owner@example.test',?,'cafe_owner',1,?,?)",
        args: [hashSync("OwnerPass123", 4), now, now],
    });
    const login = await api("/api/auth/login", "POST", {
        email: "owner@example.test",
        password: "OwnerPass123",
    });
    const payload = await login.json();
    return {
        userId: Number(payload.user.id),
        cookie: login.headers.get("set-cookie")?.split(";")[0] ?? "",
        csrf: String(payload.csrfToken),
    };
}

async function createPlatformSession() {
    const now = new Date().toISOString();
    await database.execute({
        sql: "INSERT INTO users (name,email,password_hash,role,is_active,created_at,updated_at) VALUES ('Platform Admin','admin@example.test',?,'platform_admin',1,?,?)",
        args: [hashSync("AdminPass123", 4), now, now],
    });
    const login = await api("/api/auth/login", "POST", {
        email: "admin@example.test",
        password: "AdminPass123",
    });
    const payload = await login.json();
    return {
        userId: Number(payload.user.id),
        cookie: login.headers.get("set-cookie")?.split(";")[0] ?? "",
        csrf: String(payload.csrfToken),
    };
}

type SubscriptionPlanRow = {
    id: number;
    name: string;
    annual_price_cents: number;
    max_tables: number;
    max_branches: number | null;
    features_json: string;
};

async function planByCode(code: string): Promise<SubscriptionPlanRow> {
    const result = await database.execute({
        sql: "SELECT * FROM subscription_plans WHERE code=?",
        args: [code],
    });
    const row = result.rows[0];
    if (!row) throw new Error(`Paket bulunamadı: ${code}`);
    return {
        id: Number(row.id),
        name: String(row.name),
        annual_price_cents: Number(row.annual_price_cents),
        max_tables: Number(row.max_tables),
        max_branches:
            row.max_branches === null ? null : Number(row.max_branches),
        features_json: String(row.features_json),
    };
}

async function createSubscription(
    planId: number,
    options: {
        cafeId: number;
        ownerId: number;
        maxTables?: number;
        maxBranches?: number | null;
        features?: Record<string, boolean>;
    },
) {
    const plan = await database.execute({
        sql: "SELECT * FROM subscription_plans WHERE id=?",
        args: [planId],
    });
    const row = plan.rows[0] as unknown as SubscriptionPlanRow | undefined;
    if (!row) throw new Error(`Paket bulunamadı: ${planId}`);
    const startsAt = new Date(Date.now() - 86400_000).toISOString();
    const endsAt = new Date(Date.now() + 365 * 86400_000).toISOString();
    const inserted = await database.execute({
        sql: `INSERT INTO cafe_subscriptions (cafe_id,subscription_plan_id,activated_by,plan_name_snapshot,status,starts_at,ends_at,
                annual_price_snapshot_cents,max_tables_snapshot,max_branches_snapshot,features_snapshot_json,created_at,updated_at)
              VALUES (?,?,?,?,'active',?,?,?,?,?,?,?,?) RETURNING id`,
        args: [
            options.cafeId,
            planId,
            options.ownerId,
            String(row?.name),
            startsAt,
            endsAt,
            Number(row?.annual_price_cents),
            options.maxTables ?? Number(row?.max_tables),
            options.maxBranches === undefined
                ? row?.max_branches === null
                    ? null
                    : Number(row?.max_branches)
                : options.maxBranches,
            JSON.stringify(
                options.features ?? JSON.parse(String(row?.features_json)),
            ),
            startsAt,
            startsAt,
        ],
    });
    return { id: Number(inserted.rows[0]?.id), startsAt, endsAt };
}

async function createFixture(client: Client) {
    const now = new Date().toISOString();
    await client.execute({
        sql: `INSERT INTO cafes (name,slug,status,registration_policy,loyalty_enabled,points_per_100,created_at,updated_at)
              VALUES ('Cafe Test','cafe-test','active','optional',1,1,?,?)`,
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
        sql: `INSERT INTO branches (cafe_id,name,slug,uses_shared_menu,is_active,created_at,updated_at) VALUES (1,'Merkez','merkez',1,1,?,?)`,
        args: [now, now],
    });
    await client.execute({
        sql: `INSERT INTO users (cafe_id,name,email,password_hash,role,is_active,created_at,updated_at) VALUES (1,'Garson','waiter@example.test',?,'waiter',1,?,?)`,
        args: [hashSync("WaiterPass123", 4), now, now],
    });
    await client.execute({
        sql: "INSERT INTO branch_user (branch_id,user_id) VALUES (1,1)",
    });
    await client.execute({
        sql: `INSERT INTO menu_categories (cafe_id,name,sort_order,is_active,created_at,updated_at) VALUES (1,'Kahveler',0,1,?,?)`,
        args: [now, now],
    });
    await client.execute({
        sql: `INSERT INTO menu_products (cafe_id,category_id,name,description,price_cents,is_available,is_featured,created_at,updated_at) VALUES (1,1,'Buzlu latte','Kahve ve süt',12000,1,1,?,?)`,
        args: [now, now],
    });
    await client.execute({
        sql: `INSERT INTO menu_option_groups (menu_product_id,name,selection_mode,min_selections,max_selections,is_active,created_at,updated_at) VALUES (1,'Şurup','single',0,1,1,?,?)`,
        args: [now, now],
    });
    await client.execute({
        sql: `INSERT INTO menu_options (menu_option_group_id,name,price_delta_cents,is_available,created_at,updated_at) VALUES (1,'Karamel',3000,1,?,?)`,
        args: [now, now],
    });
    const token =
        "table-token-for-testing-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    const tokenHash = createHash("sha256").update(token).digest("hex");
    await client.execute({
        sql: `INSERT INTO dining_tables (branch_id,name,public_token_hash,public_token_encrypted,is_active,created_at,updated_at) VALUES (1,'Masa 1',?,'test',1,?,?)`,
        args: [tokenHash, now, now],
    });
    await client.execute({
        sql: `INSERT INTO table_sessions (branch_id,dining_table_id,status,qr_ordering_enabled,opened_by,opened_at,created_at,updated_at) VALUES (1,1,'open',1,1,?,?,?)`,
        args: [now, now, now],
    });
    return {
        token,
        productId: 1,
        optionId: 1,
        waiterEmail: "waiter@example.test",
        waiterPassword: "WaiterPass123",
        sessionId: 1,
    };
}
