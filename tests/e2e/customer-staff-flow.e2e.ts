import { createHash } from "node:crypto";
import { rm } from "node:fs/promises";
import { resolve } from "node:path";
import { createClient } from "@libsql/client";
import { hashSync } from "bcryptjs";
import { expect, test } from "@playwright/test";
import { initializeDatabase } from "../../src/server/db/client.js";

const databasePath = resolve(process.cwd(), ".local-e2e.db");
const databaseUrl = `file:${databasePath.replaceAll("\\", "/")}`;
const tableToken = "e2e-mobile-table-token-0123456789-ABCDEFGHIJ";

test.beforeAll(async () => {
    await rm(databasePath, { force: true });
    const database = createClient({ url: databaseUrl });
    await initializeDatabase(database);
    const now = new Date().toISOString();
    await database.execute({
        sql: `INSERT INTO cafes (name,slug,status,registration_policy,loyalty_enabled,points_per_100,created_at,updated_at)
              VALUES ('Kıyı Kahve','kiyi-kahve','active','optional',1,1,?,?)`,
        args: [now, now],
    });
    await database.execute({
        sql: `INSERT INTO branches (cafe_id,name,slug,uses_shared_menu,is_active,created_at,updated_at)
              VALUES (1,'Merkez','merkez',1,1,?,?)`,
        args: [now, now],
    });
    await database.execute({
        sql: `INSERT INTO users (cafe_id,name,email,password_hash,role,is_active,created_at,updated_at)
              VALUES (1,'Kafe Sahibi','owner@example.test',?,'cafe_owner',1,?,?)`,
        args: [hashSync("OwnerPass123", 4), now, now],
    });
    const waiter = await database.execute({
        sql: `INSERT INTO users (cafe_id,name,email,password_hash,role,is_active,created_at,updated_at)
              VALUES (1,'Ece Garson','waiter@example.test',?,'waiter',1,?,?) RETURNING id`,
        args: [hashSync("WaiterPass123", 4), now, now],
    });
    const waiterId = Number(waiter.rows[0]?.id);
    await database.execute({
        sql: "INSERT INTO branch_user (branch_id,user_id,created_at) VALUES (1,?,?)",
        args: [waiterId, now],
    });
    await database.execute({
        sql: `INSERT INTO menu_categories (cafe_id,name,sort_order,is_active,created_at,updated_at)
              VALUES (1,'Soğuk kahveler',0,1,?,?)`,
        args: [now, now],
    });
    const product = await database.execute({
        sql: `INSERT INTO menu_products (cafe_id,category_id,name,description,price_cents,prep_minutes,is_available,is_featured,created_at,updated_at)
              VALUES (1,1,'Buzlu Latte','Soğuk espresso ve süt',12000,4,1,1,?,?) RETURNING id`,
        args: [now, now],
    });
    const productId = Number(product.rows[0]?.id);
    const group = await database.execute({
        sql: `INSERT INTO menu_option_groups (menu_product_id,name,selection_mode,min_selections,max_selections,is_active,created_at,updated_at)
              VALUES (?,'Şurup','single',0,1,1,?,?) RETURNING id`,
        args: [productId, now, now],
    });
    await database.execute({
        sql: `INSERT INTO menu_options (menu_option_group_id,name,price_delta_cents,is_available,created_at,updated_at)
              VALUES (?,'Karamel',3000,1,?,?)`,
        args: [Number(group.rows[0]?.id), now, now],
    });
    const tokenHash = createHash("sha256").update(tableToken).digest("hex");
    await database.execute({
        sql: `INSERT INTO dining_tables (branch_id,name,public_token_hash,public_token_encrypted,is_active,created_at,updated_at)
              VALUES (1,'Masa 1',?,'test-token',1,?,?)`,
        args: [tokenHash, now, now],
    });
    await database.execute({
        sql: `INSERT INTO table_sessions (branch_id,dining_table_id,status,qr_ordering_enabled,opened_by,opened_at,created_at,updated_at)
              VALUES (1,1,'open',1,?,?,?,?)`,
        args: [waiterId, now, now, now],
    });
    await database.close();
});

test("mobile QR order moves through waiter service and payment", async ({
    browser,
}) => {
    const browserErrors: string[] = [];
    const customerContext = await browser.newContext({
        viewport: { width: 390, height: 844 },
        isMobile: true,
        hasTouch: true,
    });
    const customer = await customerContext.newPage();
    customer.on("pageerror", (error) => browserErrors.push(error.message));
    customer.on("response", (response) => {
        if (response.status() >= 400)
            browserErrors.push(
                String(response.status()) + " " + response.url(),
            );
    });
    customer.on("console", (message) => {
        if (message.type() === "error") browserErrors.push(message.text());
    });

    const token = encodeURIComponent(tableToken);
    await customer.goto(`/masa/${token}`);
    await expect(
        customer.getByRole("heading", { name: "Ne istersin?" }),
    ).toBeVisible();
    const documentWidth = await customer.evaluate(
        () => document.documentElement.scrollWidth,
    );
    expect(documentWidth).toBeLessThanOrEqual(390);

    await customer
        .getByRole("button", { name: /Buzlu Latte/ })
        .first()
        .click();
    await expect(customer.getByRole("dialog")).toBeVisible();
    await customer.getByRole("radio", { name: /Karamel/ }).check();
    await customer.getByRole("button", { name: /Sepete ekle/ }).click();
    await customer.getByRole("button", { name: /Sepeti görüntüle/ }).click();
    await customer
        .getByRole("button", { name: /Siparişi gözden geçir/ })
        .click();
    await expect(customer.getByText("Siparişini onayla")).toBeVisible();
    await expect(customer.getByText("₺150,00").first()).toBeVisible();
    await customer.getByRole("button", { name: /Siparişi gönder/ }).click();
    await expect(customer).toHaveURL(/\/masa\/.*\/siparis\//);
    await expect(customer.getByText("Sipariş alındı")).toBeVisible();
    const trackingUrl = customer.url();
    const billUrl = new URL(trackingUrl);
    billUrl.pathname = billUrl.pathname.replace(
        /\/siparis\/[^/]+$/,
        "/adisyon",
    );

    const staffContext = await browser.newContext({
        viewport: { width: 1440, height: 1000 },
    });
    const staff = await staffContext.newPage();
    staff.on("pageerror", (error) => browserErrors.push(error.message));
    staff.on("response", (response) => {
        if (response.status() >= 400)
            browserErrors.push(
                String(response.status()) + " " + response.url(),
            );
    });
    staff.on("console", (message) => {
        if (message.type() === "error") browserErrors.push(message.text());
    });
    await staff.goto("/giris");
    await staff.getByLabel("E-posta").fill("waiter@example.test");
    await staff.getByLabel("Şifre").fill("WaiterPass123");
    await staff.getByRole("button", { name: /Panele giriş yap/ }).click();
    await expect(staff).toHaveURL(/\/garson/);
    await expect(
        staff.getByRole("button", { name: "Siparişi al" }),
    ).toBeVisible({ timeout: 15_000 });
    await staff.getByRole("button", { name: "Siparişi al" }).click();
    await expect(customer.getByText("Hazırlanıyor")).toBeVisible({
        timeout: 12_000,
    });
    await staff.getByRole("button", { name: "Hazır", exact: true }).click();
    await expect(customer.getByText("Hazır", { exact: true })).toBeVisible({
        timeout: 12_000,
    });
    await staff
        .getByRole("button", { name: "Teslim edildi", exact: true })
        .click();
    await customer.goto(billUrl.href);
    await expect(customer.getByText("Buzlu Latte").first()).toBeVisible();

    await staff.getByRole("button", { name: /Masa 1.*Adisyonu aç/ }).click();
    await expect(staff.getByRole("dialog", { name: /Masa 1/ })).toBeVisible();
    await expect(staff.getByLabel("Tutar (₺)")).toHaveValue("150.00");
    await staff.getByRole("button", { name: "Ödemeyi kaydet" }).click();
    await expect(staff.getByText("Bu adisyon kapalı.")).toBeVisible();
    await expect(staff.getByText("₺150,00").first()).toBeVisible();

    await customer.goto(trackingUrl);
    await expect(
        customer.getByText(/Ödemen alındı, masa hesabı kapandı/),
    ).toBeVisible();
    await customer.goto(`/masa/${token}/adisyon`);
    await expect(customer.getByText("Açık adisyon yok")).toBeVisible();
    expect(browserErrors).toEqual([]);

    await staffContext.close();
    await customerContext.close();
});
