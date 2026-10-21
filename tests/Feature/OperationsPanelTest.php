<?php

namespace Tests\Feature;

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
            ->assertJsonPath('orders.0.status', Order::STATUS_ACCEPTED);
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

    public function test_waiter_can_resolve_a_service_request_only_for_own_branch(): void
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
            ->assertJsonPath('requests.0.type_label', 'Hesap istiyor');

        $this->actingAs($waiter)->postJson(route('staff.service-requests.resolve', $request))
            ->assertOk();

        $this->assertDatabaseHas('service_requests', [
            'id' => $request->id,
            'status' => ServiceRequest::STATUS_RESOLVED,
            'resolved_by' => $waiter->id,
        ]);
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
