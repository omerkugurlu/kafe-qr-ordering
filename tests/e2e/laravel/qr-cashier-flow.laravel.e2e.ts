import { expect, test } from '@playwright/test';

type MenuState = {
    session: { id: number } | null;
    qr_visit_id: string | null;
    ordering_available: boolean;
    products: Array<{ id: number; name: string }>;
};

async function menuState(
    page: import('@playwright/test').Page,
): Promise<MenuState> {
    const content = await page.locator('#menu-data').textContent();
    if (!content)
        throw new Error('The customer menu did not include its state payload.');

    return JSON.parse(content) as MenuState;
}

async function submitCustomerOrder(
    page: import('@playwright/test').Page,
    productId: number,
): Promise<void> {
    await page.locator(`[data-quick-add="${productId}"]`).first().click();
    await page.locator('[data-cart-open]').click();
    await page.locator('[data-checkout]').click();
    await expect(page.locator('[data-confirm-order]')).toBeVisible();
    await page.locator('[data-confirm-order]').click();
    await expect(page.locator('#order-track')).toHaveCount(1);
}

test('QR order, waiter serves and takes payment, then the next scan rotates visit IDs', async ({
    browser,
    page,
}) => {
    const qrToken = process.env.LARAVEL_E2E_QR_TOKEN;
    const waiterEmail = process.env.LARAVEL_E2E_WAITER_EMAIL;
    const waiterPassword = process.env.LARAVEL_E2E_WAITER_PASSWORD;
    expect(qrToken).toBeTruthy();
    expect(waiterEmail).toBeTruthy();
    expect(waiterPassword).toBeTruthy();

    const menuUrl = `/masa/${qrToken}`;
    await page.goto(menuUrl);
    await expect(page.locator('#menu-data')).toHaveCount(1);
    const firstVisit = await menuState(page);
    expect(firstVisit.ordering_available).toBe(true);
    expect(firstVisit.session?.id).toBeTruthy();
    expect(firstVisit.qr_visit_id).toMatch(/^[0-9a-f-]{36}$/i);
    const productId = firstVisit.products[0]?.id;
    expect(productId).toBeTruthy();
    await submitCustomerOrder(page, productId);

    const waiterPage = await browser.newPage();
    await waiterPage.goto('/giris');
    await waiterPage.getByLabel('E-posta adresi').fill(waiterEmail!);
    await waiterPage.getByLabel('Şifre').fill(waiterPassword!);
    await waiterPage.getByRole('button', { name: 'Giriş yap' }).click();
    await expect(waiterPage).toHaveURL(/\/garson(?:\/|$)/);
    const board = waiterPage.locator('[data-staff-board]');
    const pendingLane = waiterPage.locator('[data-lane-body="pending"]');
    const acceptButton = pendingLane.locator(
        '[data-order-action][data-status="accepted"]',
    );
    await expect(acceptButton).toBeVisible();
    await expect(
        pendingLane.locator('[data-order-action][data-status="cancelled"]'),
    ).toHaveCount(0);

    const orderId = await acceptButton.getAttribute('data-order');
    const adisyonLink = pendingLane.getByRole('link', { name: 'Adisyon' });
    const sessionHref = await adisyonLink.getAttribute('href');
    expect(orderId).toBeTruthy();
    expect(sessionHref).toBeTruthy();

    const statusTemplate = await board.getAttribute('data-status-url');
    const csrf = await waiterPage
        .locator('meta[name="csrf-token"]')
        .getAttribute('content');
    expect(statusTemplate).toBeTruthy();
    expect(csrf).toBeTruthy();
    const waiterCancelResponse = await waiterPage.evaluate(
        async ({ template, token, id }) => {
            const response = await fetch(template.replace('__ID__', id), {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({
                    status: 'cancelled',
                    reason: 'E2E yetki kontrolü',
                }),
            });

            return response.status;
        },
        { template: statusTemplate!, token: csrf!, id: orderId! },
    );
    expect(waiterCancelResponse).toBe(403);

    await adisyonLink.click();
    await expect(
        waiterPage.getByText('Siparişi düzelt', { exact: true }),
    ).toBeVisible();
    const editForm = waiterPage
        .locator('details')
        .filter({ hasText: 'Siparişi düzelt' });
    await editForm.locator('summary').click();
    await editForm.locator('input[name^="items["]').first().fill('2');
    await editForm
        .getByPlaceholder('Örn. adet yanlış girildi')
        .fill('Müşteri adedi değiştirdi');
    await editForm.getByRole('button', { name: 'Siparişi kaydet' }).click();
    await expect(waiterPage.locator('#flash-data')).toHaveAttribute(
        'data-status',
        /Sipariş düzeltildi/,
    );
    await expect(
        waiterPage.getByText('₺500,00', { exact: true }).first(),
    ).toBeVisible();

    const sessionUrl = new URL(sessionHref!, waiterPage.url()).toString();
    await waiterPage.goto('/garson');
    const refreshedPendingLane = waiterPage.locator(
        '[data-lane-body="pending"]',
    );
    await expect(
        refreshedPendingLane.locator(
            '[data-order-action][data-status="accepted"]',
        ),
    ).toBeVisible();
    await refreshedPendingLane
        .locator('[data-order-action][data-status="accepted"]')
        .click();

    const acceptedLane = waiterPage.locator('[data-lane-body="accepted"]');
    const readyButton = acceptedLane.locator(
        '[data-order-action][data-status="ready"]',
    );
    await expect(readyButton).toBeVisible();
    await readyButton.click();

    const readyLane = waiterPage.locator('[data-lane-body="ready"]');
    const servedButton = readyLane.locator(
        '[data-order-action][data-status="served"]',
    );
    await expect(servedButton).toBeVisible();
    await servedButton.click();
    await expect(
        readyLane.locator(`[data-order-action][data-order="${orderId}"]`),
    ).toHaveCount(0);

    await waiterPage.goto(sessionUrl);
    const paymentForm = waiterPage.locator('form[action*="/odemeler"]');
    await paymentForm.locator('input[name="amount"]').fill('500.00');
    await paymentForm.locator('select[name="method"]').selectOption('cash');
    await paymentForm.getByRole('button', { name: 'Ödemeyi kaydet' }).click();
    await expect(waiterPage.locator('#flash-data')).toHaveAttribute(
        'data-status',
        /Ödeme kaydedildi ve adisyon kapatıldı/,
    );

    await waiterPage.goto(sessionUrl);
    await expect(
        waiterPage.getByText('Kapandı', { exact: true }),
    ).toBeVisible();

    // This is the old page, still loaded with the first visit ID.
    const oldPostStatus = await page.evaluate(
        async ({ path, sessionId, visitId, itemId }) => {
            const token =
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? '';
            const response = await fetch(path, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    qr_visit_id: visitId,
                    lines: [{ product_id: itemId, quantity: 1 }],
                }),
            });

            return response.status;
        },
        {
            path: `/masa/${qrToken}/siparis`,
            sessionId: firstVisit.session!.id,
            visitId: firstVisit.qr_visit_id!,
            itemId: productId,
        },
    );
    expect(oldPostStatus).toBe(422);

    // The next customer rescans the same static QR on this device.
    await page.goto(menuUrl);
    const nextVisit = await menuState(page);
    expect(nextVisit.ordering_available).toBe(true);
    expect(nextVisit.session?.id).not.toBe(firstVisit.session?.id);
    expect(nextVisit.qr_visit_id).not.toBe(firstVisit.qr_visit_id);
    await submitCustomerOrder(page, nextVisit.products[0]!.id);

    const staleVisitStatus = await page.evaluate(
        async ({ path, sessionId, visitId, itemId }) => {
            const token =
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? '';
            const response = await fetch(path, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': token,
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    qr_visit_id: visitId,
                    lines: [{ product_id: itemId, quantity: 1 }],
                }),
            });

            return response.status;
        },
        {
            path: `/masa/${qrToken}/siparis`,
            sessionId: nextVisit.session!.id,
            visitId: firstVisit.qr_visit_id!,
            itemId: productId,
        },
    );
    expect(staleVisitStatus).toBe(422);

    await waiterPage.goto('/garson');
    await expect(
        waiterPage.locator(
            '[data-lane-body="pending"] [data-order-action][data-status="accepted"]',
        ),
    ).toBeVisible();
    await waiterPage.close();
});
