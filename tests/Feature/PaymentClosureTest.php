<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_full_payment_closes_the_adisyon_and_redirects_to_the_owner_panel(): void
    {
        [$cafe, $branch, $session, $order] = $this->openSession();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);

        $this->actingAs($owner)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CASH,
            'amount' => '100.00',
        ])->assertRedirect(route('manage.orders.index', ['sube' => $branch->id]));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PAID]);
        $this->assertDatabaseHas('table_sessions', [
            'id' => $session->id,
            'status' => TableSession::STATUS_CLOSED,
            'closed_by' => $owner->id,
        ]);
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'table_session.closed',
            'subject_id' => $session->id,
            'user_id' => $owner->id,
        ]);
    }

    public function test_owner_can_close_an_empty_session_and_returns_to_manage_orders(): void
    {
        [$cafe, $branch, $session] = $this->openSession(withOrder: false);
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);

        $this->actingAs($owner)->post(route('staff.sessions.close', $session->id))
            ->assertRedirect(route('manage.orders.index', ['sube' => $branch->id]));

        $this->assertDatabaseHas('table_sessions', ['id' => $session->id, 'status' => TableSession::STATUS_CLOSED]);
    }

    public function test_adisyon_back_link_is_role_aware(): void
    {
        [$cafe, $branch, $session] = $this->openSession();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);
        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id]);
        $branch->staff()->attach($waiter->id);

        $this->actingAs($owner)->get(route('staff.sessions.show', $session->id))
            ->assertOk()
            ->assertSee(route('manage.orders.index', ['sube' => $branch->id]), false);

        $this->actingAs($waiter)->get(route('staff.sessions.show', $session->id))
            ->assertOk()
            ->assertSee(route('staff.branch', $branch->id), false);
    }

    /** @return array{Cafe, Branch, TableSession, Order|null} */
    private function openSession(bool $withOrder = true): array
    {
        $cafe = Cafe::query()->create(['name' => 'Ödeme Kafe', 'slug' => 'odeme-'.uniqid(), 'status' => Cafe::STATUS_ACTIVE]);
        $branch = $cafe->branches()->create(['name' => 'Merkez', 'slug' => 'merkez', 'is_active' => true]);
        $table = DiningTable::createWithToken(['branch_id' => $branch->id, 'name' => 'Masa 1', 'is_active' => true]);
        $session = TableSession::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'status' => TableSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);
        $order = null;

        if ($withOrder) {
            $order = Order::query()->create([
                'cafe_id' => $cafe->id,
                'branch_id' => $branch->id,
                'table_session_id' => $session->id,
                'order_number' => 'PAY'.uniqid(),
                'source' => 'qr',
                'status' => Order::STATUS_SERVED,
                'subtotal' => '100.00',
                'discount_total' => '0.00',
                'total' => '100.00',
                'served_at' => now(),
            ]);
        }

        return [$cafe, $branch, $session, $order];
    }
}
