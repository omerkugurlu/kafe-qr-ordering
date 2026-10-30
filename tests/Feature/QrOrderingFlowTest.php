<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\MenuProduct;
use App\Models\Order;
use App\Models\ServiceRequest;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QrOrderingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_view_a_table_menu_and_submit_server_priced_order(): void
    {
        [$cafe, $branch, $table, $token, $product] = $this->createCafeTableAndProduct();
        $this->assertSame(hash('sha256', $token), $table->public_token_hash);
        $this->assertTrue($table->is_active && $branch->is_active && $cafe->status === Cafe::STATUS_ACTIVE);

        // Existing printed QR codes without the new query flag still claim a visit on a fresh device.
        $menuResponse = $this->get(route('customer.menu', $token));
        $menuResponse
            ->assertOk()
            ->assertSee('Kıyı Kahve')
            ->assertSee('Filtre kahve');
        $session = TableSession::query()->where('dining_table_id', $table->id)->where('status', TableSession::STATUS_OPEN)->firstOrFail();
        preg_match('~<script type="application/json" id="menu-data">(.*?)</script>~s', $menuResponse->getContent(), $matches);
        $menuData = json_decode($matches[1] ?? '', true);
        $visitId = $menuData['qr_visit_id'] ?? null;
        $this->assertIsString($visitId);
        $this->assertTrue(Str::isUuid($visitId));
        $this->assertSame(hash('sha256', $visitId), $session->qr_visit_token_hash);
        $this->assertNotEmpty($session->qr_visit_metadata['issued_at'] ?? null);
        $this->assertNotEmpty($session->qr_visit_metadata['ip_hash'] ?? null);
        $this->assertNotEmpty($session->qr_visit_metadata['user_agent'] ?? null);

        $this->postJson(route('customer.orders.store', $token), [
            'session_id' => $session->id,
            'items' => [$product->id => 1],
        ])->assertUnprocessable()->assertJsonValidationErrors('qr_visit_id');

        $this->postJson(route('customer.orders.store', $token), [
            'session_id' => $session->id,
            'qr_visit_id' => (string) Str::uuid(),
            'items' => [$product->id => 1],
        ])->assertUnprocessable()->assertJsonValidationErrors('qr_visit_id');

        $this->post(route('customer.orders.store', $token), [
            'session_id' => $session->id,
            'qr_visit_id' => $visitId,
            'items' => [$product->id => 2],
            'customer_note' => 'Yulaf sütü lütfen',
        ])->assertRedirect(route('customer.menu', $token));

        $order = Order::query()->with('items')->firstOrFail();
        $this->assertSame($cafe->id, $order->cafe_id);
        $this->assertSame($branch->id, $order->branch_id);
        $this->assertSame(Order::STATUS_PENDING, $order->status);
        $this->assertSame('250.00', $order->total);
        $this->assertSame('125.00', $order->items->first()->unit_price);
        $this->assertSame(2, $order->items->first()->quantity);
        $this->assertSame('Filtre kahve', $order->items->first()->name_snapshot);
    }

    public function test_closed_qr_session_blocks_the_old_visit_but_scanning_again_opens_a_new_one(): void
    {
        [$cafe, $branch, $table, $token, $product] = $this->createCafeTableAndProduct();
        $this->get(route('customer.menu', $token).'?qr_scan=1')->assertRedirect(route('customer.menu', $token));
        $firstMenu = $this->get(route('customer.menu', $token))->assertOk();
        $oldSession = TableSession::query()->where('dining_table_id', $table->id)->firstOrFail();
        preg_match('~<script type="application/json" id="menu-data">(.*?)</script>~s', $firstMenu->getContent(), $firstMatches);
        $firstData = json_decode($firstMatches[1] ?? '', true);
        $oldVisitId = $firstData['qr_visit_id'] ?? null;
        $this->assertTrue(Str::isUuid($oldVisitId));
        $oldSession->forceFill(['status' => TableSession::STATUS_CLOSED, 'closed_at' => now()])->save();

        $this->assertDatabaseCount('table_sessions', 1);
        $this->assertSame(0, TableSession::query()->where('dining_table_id', $table->id)->where('status', TableSession::STATUS_OPEN)->count());

        $this->getJson(route('customer.status', $token).'?session_id='.$oldSession->id.'&qr_visit_id='.$oldVisitId)
            ->assertOk()
            ->assertJsonPath('session', null);

        $this->postJson(route('customer.orders.store', $token), [
            'session_id' => $oldSession->id,
            'qr_visit_id' => $oldVisitId,
            'items' => [$product->id => 1],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Bu QR oturumu sona erdi. Sipariş vermek için masadaki QR kodu yeniden okutun.');

        // A normal refresh with the revoked visit must not start another seating.
        $this->get(route('customer.menu', $token))->assertOk();
        $this->assertDatabaseCount('table_sessions', 1);

        // Scanning the fixed QR explicitly starts the next seating.
        $this->get(route('customer.menu', $token).'?qr_scan=1')->assertRedirect(route('customer.menu', $token));
        $rescannedMenu = $this->get(route('customer.menu', $token))->assertOk();
        preg_match('~<script type="application/json" id="menu-data">(.*?)</script>~s', $rescannedMenu->getContent(), $rescannedMatches);
        $rescannedData = json_decode($rescannedMatches[1] ?? '', true);
        $newSessionId = $rescannedData['session']['id'] ?? null;
        $newVisitId = $rescannedData['qr_visit_id'] ?? null;
        $this->assertNotSame($oldSession->id, $newSessionId);
        $this->assertTrue($rescannedData['ordering_available'] ?? false);
        $this->assertTrue(Str::isUuid($newVisitId));
        $this->assertNotSame($oldVisitId, $newVisitId);
        $this->assertDatabaseCount('table_sessions', 2);

        // A still-open page from the previous seating cannot use its old visit ID.
        $this->postJson(route('customer.orders.store', $token), [
            'session_id' => $newSessionId,
            'qr_visit_id' => $oldVisitId,
            'items' => [$product->id => 1],
        ])->assertUnprocessable()->assertJsonValidationErrors('qr_visit_id');

        $this->postJson(route('customer.orders.store', $token), [
            'session_id' => $newSessionId,
            'qr_visit_id' => $newVisitId,
            'items' => [$product->id => 1],
        ])->assertOk();

        $this->assertDatabaseHas('orders', ['table_session_id' => $newSessionId]);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_waiter_can_pause_qr_orders_without_closing_the_bill(): void
    {
        [$cafe, $branch, $table, $token, $product] = $this->createCafeTableAndProduct();
        $this->qrSessionId($table);
        $session = TableSession::query()->where('dining_table_id', $table->id)->firstOrFail();
        $waiter = User::factory()->create([
            'role' => User::ROLE_WAITER,
            'cafe_id' => $cafe->id,
            'is_active' => true,
        ]);
        $branch->staff()->attach($waiter->id);

        $this->actingAs($waiter)->post(route('staff.sessions.qr-ordering.update', $session->id), ['enabled' => 0])
            ->assertRedirect();

        $this->assertDatabaseHas('table_sessions', [
            'id' => $session->id,
            'status' => TableSession::STATUS_OPEN,
            'qr_ordering_enabled' => false,
        ]);

        $this->get(route('customer.menu', $token))
            ->assertOk()
            ->assertSee(__('customer.ordering_unavailable'));

        $this->postJson(route('customer.orders.store', $token), [
            'session_id' => $session->id,
            'qr_visit_id' => $this->qrVisitId($table),
            'items' => [$product->id => 1],
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Bu masa şu anda QR siparişine açık değil. Garsondan masayı açmasını isteyin.');

        $this->actingAs($waiter)->post(route('staff.sessions.qr-ordering.update', $session->id), ['enabled' => 1])
            ->assertRedirect();

        $this->postJson(route('customer.orders.store', $token), [
            'session_id' => $session->id,
            'qr_visit_id' => $this->qrVisitId($table),
            'items' => [$product->id => 1],
        ])->assertOk();
    }

    public function test_table_accepts_only_one_unacknowledged_qr_order_at_a_time(): void
    {
        [$cafe, $branch, $table, $token, $product] = $this->createCafeTableAndProduct();
        $session = TableSession::query()->where('dining_table_id', $table->id)->firstOrFail();
        $payload = [
            'session_id' => $session->id,
            'qr_visit_id' => $this->qrVisitId($table),
            'lines' => [['product_id' => $product->id, 'quantity' => 1]],
        ];

        $this->postJson(route('customer.orders.store', $token), $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->postJson(route('customer.orders.store', $token), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Bu masa için önceki siparişin garson tarafından alınmasını bekleyin.');

        $this->get(route('customer.menu', $token))
            ->assertOk()
            ->assertSee(__('customer.ordering_wait'));
        $this->getJson(route('customer.status', $token))
            ->assertOk()
            ->assertJsonPath('session.qr_order_pending', true);

        $order = Order::query()->firstOrFail();
        $waiter = User::factory()->create([
            'role' => User::ROLE_WAITER,
            'cafe_id' => $cafe->id,
            'is_active' => true,
        ]);
        $branch->staff()->attach($waiter->id);

        $this->actingAs($waiter)->postJson(route('staff.orders.status', $order), ['status' => Order::STATUS_ACCEPTED])
            ->assertOk();

        $this->postJson(route('customer.orders.store', $token), $payload)
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseCount('orders', 2);
    }

    public function test_only_the_first_phone_to_scan_can_claim_an_open_table_visit(): void
    {
        [, , $table, $token, $product] = $this->createCafeTableAndProduct();
        $this->get(route('customer.menu', $token).'?qr_scan=1')->assertRedirect(route('customer.menu', $token));
        $ownerMenu = $this->get(route('customer.menu', $token))->assertOk();
        preg_match('~<script type="application/json" id="menu-data">(.*?)</script>~s', $ownerMenu->getContent(), $matches);
        $ownerData = json_decode($matches[1] ?? '', true);
        $ownerSession = session()->all();
        $sessionId = $table->openSession()->id;

        $this->flushSession();
        $this->get(route('customer.menu', $token).'?qr_scan=1')->assertRedirect(route('customer.menu', $token));
        $this->get(route('customer.menu', $token))->assertOk()->assertSee('Bu masada başka bir telefonla açık QR ziyareti var.');
        $this->postJson(route('customer.orders.store', $token), [
            'session_id' => $sessionId,
            'qr_visit_id' => $ownerData['qr_visit_id'],
            'items' => [$product->id => 1],
        ])->assertUnprocessable()->assertJsonValidationErrors('qr_visit_id');
        $this->assertDatabaseCount('table_sessions', 1);

        $this->withSession($ownerSession)->get(route('customer.menu', $token))->assertOk();
        $this->postJson(route('customer.orders.store', $token), [
            'session_id' => $sessionId,
            'qr_visit_id' => $ownerData['qr_visit_id'],
            'items' => [$product->id => 1],
        ])->assertOk();
        $session = $table->openSession();
        $this->assertNotNull($session?->qr_visit_token_hash);
    }

    public function test_order_rejects_products_from_another_cafe(): void
    {
        [, , $table, $token] = $this->createCafeTableAndProduct();
        [$otherCafe, $otherBranch, $otherTable, , $otherProduct] = $this->createCafeTableAndProduct('Yabancı kafe');

        $this->post(route('customer.orders.store', $token), [
            'session_id' => $this->qrSessionId($table),
            'qr_visit_id' => $this->qrVisitId($table),
            'items' => [$otherProduct->id => 1],
        ])->assertSessionHasErrors('items');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_required_registration_is_bound_to_the_cafe_from_the_table_qr(): void
    {
        [, , $table, $token, $product] = $this->createCafeTableAndProduct('Hesap isteyen kafe', Cafe::POLICY_REQUIRED);

        $this->post(route('customer.orders.store', $token), [
            'session_id' => $this->qrSessionId($table),
            'items' => [$product->id => 1],
        ])
            ->assertRedirect(route('customer.login', $token));

        $this->post(route('customer.register.store', $token), [
            'name' => 'Deniz Kaya',
            'email' => 'deniz@example.test',
            'password' => 'Secure-Customer-2026!',
            'password_confirmation' => 'Secure-Customer-2026!',
        ])->assertRedirect(route('customer.menu', ['token' => $token, 'qr_scan' => 1]));

        $customer = User::query()->where('email', 'deniz@example.test')->firstOrFail();
        $this->assertNull($customer->cafe_id);
        $this->assertSame(User::ROLE_CUSTOMER, $customer->role);
        $this->assertAuthenticatedAs($customer);
    }

    public function test_signed_in_customer_menu_shows_account_name_and_account_link_without_a_login_link(): void
    {
        [$cafe, , , $token] = $this->createCafeTableAndProduct();
        $customer = User::factory()->create([
            'name' => 'Deniz Kaya',
            'role' => User::ROLE_CUSTOMER,
            'cafe_id' => $cafe->id,
            'is_active' => true,
        ]);

        $this->actingAs($customer)
            ->get(route('customer.menu', $token))
            ->assertOk()
            ->assertSee('Deniz Kaya')
            ->assertSee('Hesabım')
            ->assertSee(route('customer.account', $token), false);
    }

    public function test_customer_can_request_bill_only_after_waiter_accepts_an_order(): void
    {
        [, , $table, $token, $product] = $this->createCafeTableAndProduct();

        $this->post(route('customer.orders.store', $token), [
            'session_id' => $this->qrSessionId($table),
            'qr_visit_id' => $this->qrVisitId($table),
            'items' => [$product->id => 1],
        ])->assertRedirect();

        $order = Order::query()->firstOrFail();
        $menuResponse = $this->get(route('customer.menu', $token));
        $menuResponse->assertOk()->assertSee('data-bill-request', false);
        $trackResponse = $this->get(route('customer.order.track', ['token' => $token, 'orderNumber' => $order->order_number]));
        $billResponse = $this->get(route('customer.bill', $token));

        $this->assertMatchesRegularExpression('/<button[^>]*data-bill-request[^>]*\sdisabled(?:\s|>)/s', $menuResponse->getContent());
        $this->assertMatchesRegularExpression('/<button[^>]*data-bill-request[^>]*\sdisabled(?:\s|>)/s', $trackResponse->getContent());
        $this->assertMatchesRegularExpression('/<button[^>]*data-bill-request[^>]*\sdisabled(?:\s|>)/s', $billResponse->getContent());

        $this->getJson(route('customer.status', $token))
            ->assertOk()
            ->assertJsonPath('session.can_request_bill', false);

        $this->postJson(route('customer.call', $token), ['type' => ServiceRequest::TYPE_BILL])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'order_not_accepted')
            ->assertJsonPath('message', __('customer.bill_request_after_order_acceptance'));

        $this->assertDatabaseMissing('service_requests', [
            'dining_table_id' => $table->id,
            'type' => ServiceRequest::TYPE_BILL,
        ]);

        $order->forceFill(['status' => Order::STATUS_ACCEPTED, 'accepted_at' => now()])->save();

        $acceptedMenu = $this->get(route('customer.menu', $token));
        $acceptedMenu->assertOk();
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*data-bill-request[^>]*\sdisabled(?:\s|>)/s', $acceptedMenu->getContent());

        $this->getJson(route('customer.status', $token))
            ->assertOk()
            ->assertJsonPath('session.can_request_bill', true);

        $this->postJson(route('customer.call', $token), ['type' => ServiceRequest::TYPE_BILL])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertDatabaseHas('service_requests', [
            'dining_table_id' => $table->id,
            'table_session_id' => $order->table_session_id,
            'type' => ServiceRequest::TYPE_BILL,
            'status' => ServiceRequest::STATUS_OPEN,
        ]);

        $order->tableSession()->update(['status' => TableSession::STATUS_CLOSED, 'closed_at' => now()]);

        $this->postJson(route('customer.call', $token), ['type' => ServiceRequest::TYPE_BILL])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'table_session_closed')
            ->assertJsonPath('message', __('customer.service_unavailable'));

        $this->assertDatabaseCount('service_requests', 1);
    }

    public function test_static_qr_cannot_call_waiter_after_table_session_closes(): void
    {
        [, , $table, $token] = $this->createCafeTableAndProduct();
        TableSession::query()->where('dining_table_id', $table->id)
            ->update(['status' => TableSession::STATUS_CLOSED, 'closed_at' => now()]);

        $this->postJson(route('customer.call', $token), ['type' => ServiceRequest::TYPE_WAITER])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'table_session_closed')
            ->assertJsonPath('message', __('customer.service_unavailable'));

        $this->assertDatabaseCount('service_requests', 0);
    }

    public function test_table_qr_is_server_generated_svg_and_rotation_revokes_previous_token(): void
    {
        [, $branch, $table, $token] = $this->createCafeTableAndProduct();
        $owner = User::factory()->create([
            'role' => User::ROLE_CAFE_OWNER,
            'cafe_id' => $branch->cafe_id,
            'is_active' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('manage.tables.qr', [$branch, $table]))
            ->assertOk()
            ->assertHeader('content-type', 'image/svg+xml');

        $this->actingAs($owner)->post(route('manage.tables.rotate', [$branch, $table]))
            ->assertRedirect(route('manage.tables.index', $branch));
        $this->get(route('customer.menu', $token))->assertNotFound();
    }

    /** @return array{Cafe, Branch, DiningTable, string, MenuProduct} */
    private function createCafeTableAndProduct(string $name = 'Kıyı Kahve', string $registrationPolicy = Cafe::POLICY_OPTIONAL): array
    {
        $cafe = Cafe::query()->create(['name' => $name, 'slug' => str($name)->slug().'-'.uniqid(), 'status' => Cafe::STATUS_ACTIVE, 'registration_policy' => $registrationPolicy]);
        $branch = $cafe->branches()->create(['name' => 'Merkez', 'slug' => 'merkez', 'uses_shared_menu' => true, 'is_active' => true]);
        $category = $cafe->categories()->create(['name' => 'Kahveler', 'is_active' => true]);
        $product = $cafe->products()->create(['category_id' => $category->id, 'name' => 'Filtre kahve', 'price' => '125.00', 'is_available' => true]);
        $table = DiningTable::createWithToken(['branch_id' => $branch->id, 'name' => 'Masa 4', 'is_active' => true]);
        TableSession::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'status' => TableSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);

        return [$cafe, $branch, $table, $table->token(), $product];
    }

    private function qrSessionId(DiningTable $table): int
    {
        $this->get(route('customer.menu', $table->token()).'?qr_scan=1');

        return (int) $table->openSession()?->id;
    }

    private function qrVisitId(DiningTable $table): string
    {
        $this->get(route('customer.menu', $table->token()).'?qr_scan=1')->assertRedirect(route('customer.menu', $table->token()));
        $response = $this->get(route('customer.menu', $table->token()));
        preg_match('~<script type="application/json" id="menu-data">(.*?)</script>~s', $response->getContent(), $matches);
        $menuData = json_decode($matches[1] ?? '', true);
        $visitId = $menuData['qr_visit_id'] ?? null;
        $this->assertIsString($visitId);

        return $visitId;
    }
}
