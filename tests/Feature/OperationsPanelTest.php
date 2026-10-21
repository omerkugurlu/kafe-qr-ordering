<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Cafe;
use App\Models\Coupon;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_waiter_can_read_board_data_and_advance_order_status(): void
    {
        [$cafe, $branch, $session, $order] = $this->openTable();
        $waiter = $this->staffUser(User::ROLE_WAITER, $cafe, $branch);

        $this->actingAs($waiter)->get(route('staff.orders'))
            ->assertOk()
            ->assertSee('Canlı pano')
            ->assertSee('Gelen siparişler');

        $this->actingAs($waiter)->getJson(route('staff.board.data', $branch))
            ->assertOk()
            ->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('summary.open_tables', 1)
            ->assertJsonMissingPath('summary.paid_today')
            ->assertJsonMissingPath('summary.paid_today_cents')
            ->assertJsonPath('orders.0.number', $order->order_number)
            ->assertJsonPath('orders.0.table_name', 'Masa 1')
            ->assertJsonPath('orders.0.due_cents', 25000);

        $this->actingAs($waiter)->postJson(route('staff.orders.status', $order), [
            'status' => Order::STATUS_ACCEPTED,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_ACCEPTED,
        ]);

        $this->actingAs($waiter)->postJson(route('staff.orders.status', $order), [
            'status' => Order::STATUS_CANCELLED,
            'reason' => 'Müşteri vazgeçti',
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_CANCELLED,
            'cancel_reason' => 'Müşteri vazgeçti',
        ]);
    }

    public function test_waiter_without_branch_assignment_cannot_read_or_change_orders(): void
    {
        [$cafe, $branch, , $order] = $this->openTable();
        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id]);

        $this->actingAs($waiter)->get(route('staff.branch', $branch))->assertForbidden();
        $this->actingAs($waiter)->getJson(route('staff.board.data', $branch))->assertForbidden();
        $this->actingAs($waiter)->postJson(route('staff.orders.status', $order), [
            'status' => Order::STATUS_ACCEPTED,
        ])->assertForbidden();
    }

    public function test_kitchen_screen_lists_active_orders_for_assigned_branch(): void
    {
        [$cafe, $branch, , $order] = $this->openTable();
        $order->forceFill(['status' => Order::STATUS_ACCEPTED, 'accepted_at' => now()])->save();
        $kitchen = $this->staffUser(User::ROLE_KITCHEN, $cafe, $branch);

        $this->actingAs($kitchen)->get(route('staff.kitchen', $branch))
            ->assertOk()
            ->assertSee('Mutfak')
            ->assertSee('Pişiyor');

        $this->actingAs($kitchen)->getJson(route('staff.board.data', $branch))
            ->assertOk()
            ->assertJsonPath('summary.accepted', 1)
            ->assertJsonMissingPath('summary.paid_today')
            ->assertJsonMissingPath('summary.paid_today_cents')
            ->assertJsonPath('orders.0.status', Order::STATUS_ACCEPTED);
    }

    public function test_repeating_the_same_order_status_update_is_idempotent(): void
    {
        [$cafe, $branch, , $order] = $this->openTable();
        $waiter = $this->staffUser(User::ROLE_WAITER, $cafe, $branch);

        $this->actingAs($waiter)->postJson(route('staff.orders.status', $order), [
            'status' => Order::STATUS_ACCEPTED,
        ])->assertOk();

        $this->actingAs($waiter)->postJson(route('staff.orders.status', $order), [
            'status' => Order::STATUS_READY,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->actingAs($waiter)->postJson(route('staff.orders.status', $order), [
            'status' => Order::STATUS_READY,
        ])->assertOk()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_READY,
        ]);
        $this->assertSame(1, ActivityLog::query()
            ->where('subject_type', Order::class)
            ->where('subject_id', $order->id)
            ->where('action', 'order.'.Order::STATUS_READY)
            ->count());
    }

    public function test_staff_board_links_to_the_last_closed_table_session(): void
    {
        [$cafe, $branch, $session] = $this->openTable();
        $session->forceFill(['status' => TableSession::STATUS_CLOSED, 'closed_at' => now()])->save();
        $waiter = $this->staffUser(User::ROLE_WAITER, $cafe, $branch);

        $this->actingAs($waiter)->getJson(route('staff.board.data', $branch))
            ->assertOk()
            ->assertJsonPath('tables.0.is_open', false)
            ->assertJsonPath('tables.0.last_session_url', route('staff.sessions.show', $session->id))
            ->assertJsonPath('tables.0.last_session_status', TableSession::STATUS_CLOSED)
            ->assertJsonPath('can_open_tables', true);

        $this->actingAs($waiter)->get(route('staff.sessions.show', $session->id))->assertOk();

        $this->actingAs($waiter)->postJson(route('staff.sessions.open-table', [$branch, $session->diningTable]))
            ->assertOk()
            ->assertJsonPath('created', true);

        $this->assertSame(2, TableSession::query()->where('dining_table_id', $session->dining_table_id)->count());
        $this->assertDatabaseHas('table_sessions', [
            'dining_table_id' => $session->dining_table_id,
            'status' => TableSession::STATUS_OPEN,
            'qr_ordering_enabled' => true,
        ]);
    }

    public function test_waiter_can_open_a_table_and_create_a_manual_order(): void
    {
        [$cafe, $branch] = $this->cafeWithBranch();
        $table = DiningTable::createWithToken(['branch_id' => $branch->id, 'name' => 'Masa 8', 'is_active' => true]);
        $category = $cafe->categories()->create(['name' => 'Kahveler', 'is_active' => true]);
        $product = $cafe->products()->create([
            'category_id' => $category->id,
            'name' => 'Türk kahvesi',
            'price' => '80.00',
            'is_available' => true,
        ]);
        $waiter = $this->staffUser(User::ROLE_WAITER, $cafe, $branch);

        $this->actingAs($waiter)->postJson(route('staff.sessions.open-table', [$branch, $table]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('created', true);

        $session = TableSession::query()->firstOrFail();
        $this->assertSame($waiter->id, $session->opened_by);
        $this->assertTrue($session->qr_ordering_enabled);

        $this->actingAs($waiter)->post(route('staff.sessions.items.store', $session->id), [
            'items' => [$product->id => 2],
            'note' => 'Müşteri adına girildi',
        ])->assertRedirect(route('staff.sessions.show', $session->id));

        $this->assertDatabaseHas('orders', [
            'table_session_id' => $session->id,
            'source' => 'waiter',
            'status' => Order::STATUS_PENDING,
            'total' => '160.00',
        ]);

        $this->actingAs($waiter)->getJson(route('staff.board.data', $branch))
            ->assertOk()
            ->assertJsonPath('summary.pending', 1)
            ->assertJsonPath('orders.0.source', 'waiter');
    }

    public function test_owner_kitchen_preview_uses_manage_endpoints_instead_of_waiter_only_routes(): void
    {
        [$cafe, $branch] = $this->openTable();
        $owner = $this->staffUser(User::ROLE_CAFE_OWNER, $cafe, $branch);

        $this->actingAs($owner)->get(route('staff.kitchen', $branch))
            ->assertOk()
            ->assertSee(route('manage.orders.data', $branch), false)
            ->assertDontSee(route('staff.board.data', $branch), false);

        $this->actingAs($owner)->getJson(route('manage.orders.data', $branch))
            ->assertOk();
    }

    public function test_waiter_claims_a_service_request_before_resolving_it(): void
    {
        [$cafe, $branch, $session, $order] = $this->openTable();
        $waiter = $this->staffUser(User::ROLE_WAITER, $cafe, $branch);
        $request = ServiceRequest::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $session->dining_table_id,
            'table_session_id' => $session->id,
            'type' => ServiceRequest::TYPE_BILL,
            'status' => ServiceRequest::STATUS_OPEN,
            'note' => 'Hesap lütfen',
        ]);

        $this->actingAs($waiter)->getJson(route('staff.board.data', $branch))
            ->assertOk()
            ->assertJsonPath('summary.requests', 1)
            ->assertJsonPath('requests.0.type_label', 'Hesap istiyor')
            ->assertJsonPath('requests.0.claimed_by_name', null)
            ->assertJsonPath('requests.0.is_claimed_by_me', false);

        $this->actingAs($waiter)->postJson(route('staff.service-requests.claim', $request))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $request->refresh();
        $this->assertSame($waiter->id, $request->claimed_by);

        $this->actingAs($waiter)->postJson(route('staff.service-requests.resolve', $request))
            ->assertOk();

        $this->assertDatabaseHas('service_requests', [
            'id' => $request->id,
            'status' => ServiceRequest::STATUS_RESOLVED,
            'resolved_by' => $waiter->id,
        ]);
    }

    public function test_only_the_waiter_who_claimed_a_request_can_resolve_it(): void
    {
        [$cafe, $branch, $session] = $this->openTable();
        $firstWaiter = $this->staffUser(User::ROLE_WAITER, $cafe, $branch);
        $secondWaiter = $this->staffUser(User::ROLE_WAITER, $cafe, $branch);
        $request = ServiceRequest::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $session->dining_table_id,
            'table_session_id' => $session->id,
            'type' => ServiceRequest::TYPE_WAITER,
            'status' => ServiceRequest::STATUS_OPEN,
        ]);

        $this->actingAs($firstWaiter)->postJson(route('staff.service-requests.claim', $request))
            ->assertOk();

        $this->actingAs($secondWaiter)->getJson(route('staff.board.data', $branch))
            ->assertOk()
            ->assertJsonPath('requests.0.claimed_by_name', $firstWaiter->name)
            ->assertJsonPath('requests.0.is_claimed_by_me', false);

        $this->actingAs($secondWaiter)->postJson(route('staff.service-requests.claim', $request))
            ->assertStatus(409);
        $this->actingAs($secondWaiter)->postJson(route('staff.service-requests.resolve', $request))
            ->assertForbidden();

        $this->actingAs($firstWaiter)->postJson(route('staff.service-requests.release', $request))
            ->assertOk();
        $this->actingAs($secondWaiter)->postJson(route('staff.service-requests.claim', $request))
            ->assertOk();
        $this->actingAs($secondWaiter)->postJson(route('staff.service-requests.resolve', $request))
            ->assertOk();

        $this->assertDatabaseHas('service_requests', [
            'id' => $request->id,
            'status' => ServiceRequest::STATUS_RESOLVED,
            'resolved_by' => $secondWaiter->id,
        ]);
    }

    public function test_kitchen_staff_cannot_claim_or_resolve_waiter_calls(): void
    {
        [$cafe, $branch, $session] = $this->openTable();
        $kitchen = $this->staffUser(User::ROLE_KITCHEN, $cafe, $branch);
        $request = ServiceRequest::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $session->dining_table_id,
            'table_session_id' => $session->id,
            'type' => ServiceRequest::TYPE_BILL,
            'status' => ServiceRequest::STATUS_OPEN,
        ]);

        $this->actingAs($kitchen)->getJson(route('staff.board.data', $branch))
            ->assertOk()
            ->assertJsonPath('can_open_tables', false)
            ->assertJsonPath('requests.0.can_claim_requests', false);

        $this->actingAs($kitchen)->postJson(route('staff.service-requests.claim', $request))
            ->assertForbidden();
        $this->actingAs($kitchen)->postJson(route('staff.service-requests.resolve', $request))
            ->assertForbidden();

        $this->assertDatabaseHas('service_requests', [
            'id' => $request->id,
            'status' => ServiceRequest::STATUS_OPEN,
            'claimed_by' => null,
        ]);
    }

    public function test_full_payment_closes_open_waiter_calls_and_static_qr_cannot_create_another(): void
    {
        [$cafe, $branch, $session, $order] = $this->openTable();
        $order->forceFill(['status' => Order::STATUS_SERVED, 'served_at' => now()])->save();
        $table = DiningTable::query()->findOrFail($session->dining_table_id);
        $waiter = $this->staffUser(User::ROLE_WAITER, $cafe, $branch);
        $serviceRequest = ServiceRequest::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'table_session_id' => $session->id,
            'type' => ServiceRequest::TYPE_WAITER,
            'status' => ServiceRequest::STATUS_OPEN,
        ]);

        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CASH,
            'amount' => '250.00',
        ])->assertRedirect();

        $this->assertDatabaseHas('table_sessions', ['id' => $session->id, 'status' => TableSession::STATUS_CLOSED]);
        $this->assertDatabaseHas('service_requests', [
            'id' => $serviceRequest->id,
            'status' => ServiceRequest::STATUS_RESOLVED,
            'resolved_by' => $waiter->id,
            'claimed_by' => null,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'service_request.closed_with_session',
            'subject_id' => $serviceRequest->id,
        ]);

        $this->postJson(route('customer.call', $table->token()), ['type' => ServiceRequest::TYPE_WAITER])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'table_session_closed');
    }

    public function test_owner_can_create_update_and_deactivate_a_coupon(): void
    {
        [$cafe, $branch] = $this->cafeWithBranch();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);

        $this->actingAs($owner)->post(route('manage.coupons.store'), [
            'code' => ' kahve10 ',
            'type' => Coupon::TYPE_PERCENT,
            'value' => '10',
            'min_subtotal' => '100',
            'is_active' => 1,
        ])->assertRedirect(route('manage.coupons.index'));

        $coupon = Coupon::query()->firstOrFail();
        $this->assertSame('KAHVE10', $coupon->code);

        $this->actingAs($owner)->post(route('manage.coupons.store'), [
            'code' => 'KAHVE10',
            'type' => Coupon::TYPE_FIXED,
            'value' => '20',
        ])->assertSessionHasErrors('code');

        $this->actingAs($owner)->patch(route('manage.coupons.update', $coupon), [
            'code' => 'KAHVE10',
            'type' => Coupon::TYPE_FIXED,
            'value' => '25',
            'min_subtotal' => '0',
        ])->assertRedirect(route('manage.coupons.index'));

        $coupon->refresh();
        $this->assertSame(Coupon::TYPE_FIXED, $coupon->type);
        $this->assertFalse($coupon->is_active);

        $this->actingAs($owner)->delete(route('manage.coupons.destroy', $coupon))
            ->assertRedirect(route('manage.coupons.index'));
        $this->assertFalse($coupon->fresh()->is_active);
    }

    public function test_owner_reports_show_totals_and_csv_export(): void
    {
        [$cafe, $branch, $session, $order] = $this->openTable();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);
        Payment::query()->create([
            'table_session_id' => $session->id,
            'recorded_by' => $owner->id,
            'method' => Payment::METHOD_CASH,
            'amount' => '250.00',
            'recorded_at' => now(),
        ]);

        $this->actingAs($owner)->get(route('manage.reports.index'))
            ->assertOk()
            ->assertSee('Raporlar')
            ->assertSee('₺250,00');

        $response = $this->actingAs($owner)->get(route('manage.reports.export'));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString($order->order_number, $response->streamedContent());
    }

    public function test_csv_export_escapes_formula_like_user_content(): void
    {
        [, $branch, $session, $order] = $this->openTable();
        DiningTable::query()->whereKey($session->dining_table_id)->update(['name' => "\t=1+1"]);
        $branch->update(['name' => '+1+1']);
        $order->items()->firstOrFail()->update(['name_snapshot' => '=2+2']);
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $order->cafe_id]);

        $csv = $this->actingAs($owner)->get(route('manage.reports.export'))->streamedContent();

        $this->assertStringContainsString("'\t=1+1", $csv);
        $this->assertStringContainsString("'+1+1", $csv);
        $this->assertStringContainsString('2× =2+2', $csv);
    }

    public function test_owner_can_update_cafe_settings(): void
    {
        [$cafe] = $this->cafeWithBranch();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);

        $this->actingAs($owner)->patch(route('manage.settings.update'), [
            'name' => 'Yeni Kafe Adı',
            'brand_name' => 'Yeni Marka',
            'registration_policy' => Cafe::POLICY_OPTIONAL,
            'loyalty_enabled' => 1,
            'points_per_100' => 3,
        ])->assertRedirect(route('manage.settings.index'));

        $this->assertDatabaseHas('cafes', [
            'id' => $cafe->id,
            'name' => 'Yeni Kafe Adı',
            'brand_name' => 'Yeni Marka',
            'points_per_100' => 3,
            'loyalty_enabled' => true,
        ]);
    }

    public function test_platform_admin_can_view_cafe_detail_page_but_owner_cannot(): void
    {
        [$cafe, $branch] = $this->cafeWithBranch();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN, 'cafe_id' => null]);

        $this->actingAs($admin)->get(route('platform.cafes.show', $cafe))
            ->assertOk()
            ->assertSee($cafe->name)
            ->assertSee($owner->email)
            ->assertSee($branch->name);

        $this->actingAs($owner)->get(route('platform.cafes.show', $cafe))->assertForbidden();
        $this->actingAs($owner)->get(route('staff.orders'))->assertForbidden();
    }

    public function test_platform_cafe_detail_counts_distinct_customers_who_ordered_at_the_cafe(): void
    {
        [$cafe, $branch, $session, $order] = $this->openTable();
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cafe_id' => null]);
        $order->forceFill(['customer_id' => $customer->id])->save();
        $otherOrder = $order->replicate();
        $otherOrder->order_number = 'TEST'.uniqid();
        $otherOrder->save();
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN, 'cafe_id' => null]);

        $this->actingAs($admin)->get(route('platform.cafes.show', $cafe))
            ->assertOk()
            ->assertViewHas('customerCount', 1);

        $otherOrder->delete();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'customer_id' => $customer->id]);
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'cafe_id' => $cafe->id]);
        $this->assertDatabaseHas('table_sessions', ['id' => $session->id]);
    }

    /** @return array{Cafe, Branch, TableSession, Order} */
    private function openTable(): array
    {
        [$cafe, $branch] = $this->cafeWithBranch();
        $table = DiningTable::createWithToken(['branch_id' => $branch->id, 'name' => 'Masa 1', 'is_active' => true]);
        $session = TableSession::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'status' => TableSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);
        $order = Order::query()->create([
            'cafe_id' => $cafe->id,
            'branch_id' => $branch->id,
            'table_session_id' => $session->id,
            'order_number' => 'TEST'.uniqid(),
            'source' => 'qr',
            'status' => Order::STATUS_PENDING,
            'subtotal' => '250.00',
            'discount_total' => '0.00',
            'total' => '250.00',
        ]);
        $order->items()->create([
            'name_snapshot' => 'Flat White',
            'unit_price' => '125.00',
            'quantity' => 2,
            'line_total' => '250.00',
        ]);

        return [$cafe, $branch, $session, $order];
    }

    /** @return array{Cafe, Branch} */
    private function cafeWithBranch(): array
    {
        $cafe = Cafe::query()->create([
            'name' => 'Test Kafe',
            'slug' => 'test-kafe-'.uniqid(),
            'status' => Cafe::STATUS_ACTIVE,
            'registration_policy' => Cafe::POLICY_OPTIONAL,
        ]);
        $branch = $cafe->branches()->create([
            'name' => 'Merkez',
            'slug' => 'merkez',
            'is_active' => true,
        ]);

        return [$cafe, $branch];
    }

    private function staffUser(string $role, Cafe $cafe, Branch $branch): User
    {
        $user = User::factory()->create(['role' => $role, 'cafe_id' => $cafe->id]);
        $branch->staff()->attach($user->id);

        return $user;
    }
}
