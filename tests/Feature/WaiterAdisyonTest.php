<?php

namespace Tests\Feature;

use App\Livewire\Staff\OrderBoard;
use App\Models\Branch;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WaiterAdisyonTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_waiter_can_record_partial_payment_and_close_paid_adisyon(): void
    {
        [$cafe, $branch, $session, $order] = $this->createOpenSession();
        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id]);
        $branch->staff()->attach($waiter->id);
        $this->assertTrue($waiter->hasRole(User::ROLE_WAITER));
        $this->assertSame($cafe->id, $waiter->cafe->id);
        $this->assertTrue($waiter->branches()->whereKey($branch->id)->exists());

        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CASH,
            'amount' => '100.00',
            'person_label' => 'Ayşe',
        ])->assertRedirect(route('staff.sessions.show', $session->id));

        $this->assertSame('150.00', $session->fresh()->dueAmount());
        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CARD,
            'amount' => '150.00',
            'person_label' => 'Mehmet',
        ])->assertRedirect(route('staff.sessions.show', $session->id));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PAID]);
        $this->actingAs($waiter)->post(route('staff.sessions.close', $session->id), [
            'closing_note' => 'Hesap tamamlandı',
        ])->assertRedirect(route('staff.orders'));
        $this->assertDatabaseHas('table_sessions', ['id' => $session->id, 'status' => TableSession::STATUS_CLOSED]);
    }

    public function test_waiter_cannot_access_an_unassigned_branch_or_overcharge_a_session(): void
    {
        [$cafe, $branch, $session] = $this->createOpenSession();
        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id]);
        $branch->staff()->attach($waiter->id);

        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CASH,
            'amount' => '251.00',
        ])->assertSessionHasErrors('amount');
        $this->assertDatabaseCount('payments', 0);

        $otherCafe = Cafe::query()->create(['name' => 'Diğer kafe', 'slug' => 'diger-kafe', 'status' => Cafe::STATUS_ACTIVE]);
        $otherBranch = $otherCafe->branches()->create(['name' => 'Diğer şube', 'slug' => 'diger', 'is_active' => true]);

        $this->actingAs($waiter)->get(route('staff.branch', $otherBranch))->assertForbidden();
    }

    public function test_order_paid_before_service_becomes_paid_and_gets_loyalty_credit_when_served(): void
    {
        [$cafe, $branch, $session, $order] = $this->createOpenSession();
        $order->forceFill(['status' => Order::STATUS_ACCEPTED])->save();
        $cafe->forceFill(['loyalty_enabled' => true, 'points_per_100' => 1])->save();
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cafe_id' => $cafe->id]);
        $order->forceFill(['customer_id' => $customer->id])->save();
        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id]);
        $branch->staff()->attach($waiter->id);

        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CASH,
            'amount' => '250.00',
        ])->assertRedirect(route('staff.sessions.show', $session->id));
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_ACCEPTED]);

        Livewire::actingAs($waiter)->test(OrderBoard::class, ['branchId' => $branch->id])->call('serve', $order->id)->assertHasNoErrors();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PAID]);
        $this->assertDatabaseHas('loyalty_accounts', ['cafe_id' => $cafe->id, 'user_id' => $customer->id, 'points' => 2, 'completed_order_count' => 1]);
    }

    public function test_order_with_payment_cannot_be_cancelled_until_payment_is_voided(): void
    {
        [$cafe, $branch, $session, $servedOrder] = $this->createOpenSession();
        $pendingOrder = Order::query()->create([
            'cafe_id' => $cafe->id, 'branch_id' => $branch->id, 'table_session_id' => $session->id,
            'order_number' => 'PENDING'.uniqid(), 'source' => 'qr', 'status' => Order::STATUS_ACCEPTED,
            'subtotal' => '25.00', 'discount_total' => '0.00', 'total' => '25.00', 'accepted_at' => now(),
        ]);
        $pendingItem = $pendingOrder->items()->create(['name_snapshot' => 'Çay', 'unit_price' => '25.00', 'quantity' => 1, 'line_total' => '25.00']);
        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id]);
        $branch->staff()->attach($waiter->id);

        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CASH, 'amount' => '10.00', 'order_item_id' => $pendingItem->id,
        ])->assertRedirect(route('staff.sessions.show', $session->id));
        Livewire::actingAs($waiter)->test(OrderBoard::class, ['branchId' => $branch->id])
            ->call('cancel', $pendingOrder->id, 'Müşteri vazgeçti')->assertHasErrors('order');

        $payment = Payment::query()->firstOrFail();
        $this->actingAs($waiter)->post(route('staff.sessions.payments.void', [$session->id, $payment->id]), [
            'reason' => 'Yanlış siparişe kaydedildi',
        ])->assertRedirect(route('staff.sessions.show', $session->id));
        Livewire::actingAs($waiter)->test(OrderBoard::class, ['branchId' => $branch->id])
            ->call('cancel', $pendingOrder->id, 'Müşteri vazgeçti')->assertHasNoErrors();

        $this->assertDatabaseHas('orders', ['id' => $pendingOrder->id, 'status' => Order::STATUS_CANCELLED]);
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'void_reason' => 'Yanlış siparişe kaydedildi']);
    }

    public function test_settled_payments_cannot_be_voided_and_new_order_balance_remains_collectible(): void
    {
        [$cafe, $branch, $session, $firstOrder] = $this->createOpenSession();
        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id]);
        $branch->staff()->attach($waiter->id);

        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CARD, 'amount' => '250.00',
        ])->assertRedirect(route('staff.sessions.show', $session->id));
        $settledPayment = Payment::query()->firstOrFail();
        $this->assertDatabaseHas('orders', ['id' => $firstOrder->id, 'status' => Order::STATUS_PAID]);
        $this->actingAs($waiter)->post(route('staff.sessions.payments.void', [$session->id, $settledPayment->id]), [
            'reason' => 'Hatalı ödeme',
        ])->assertSessionHasErrors('payment');

        $newOrder = Order::query()->create([
            'cafe_id' => $cafe->id, 'branch_id' => $branch->id, 'table_session_id' => $session->id,
            'order_number' => 'LATER'.uniqid(), 'source' => 'qr', 'status' => Order::STATUS_ACCEPTED,
            'subtotal' => '25.00', 'discount_total' => '0.00', 'total' => '25.00', 'accepted_at' => now(),
        ]);
        $item = $newOrder->items()->create(['name_snapshot' => 'Çay', 'unit_price' => '25.00', 'quantity' => 1, 'line_total' => '25.00']);
        $this->assertSame('25.00', $session->fresh()->dueAmount());

        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CASH, 'amount' => '10.00', 'order_item_id' => $item->id,
        ])->assertRedirect(route('staff.sessions.show', $session->id));
        $laterPayment = Payment::query()->where('id', '!=', $settledPayment->id)->firstOrFail();
        $this->actingAs($waiter)->post(route('staff.sessions.payments.void', [$session->id, $laterPayment->id]), [
            'reason' => 'Yanlış tutar girildi',
        ])->assertRedirect(route('staff.sessions.show', $session->id));
        $this->assertSame('25.00', $session->fresh()->dueAmount());
        $this->actingAs($waiter)->post(route('staff.sessions.close', $session->id))->assertSessionHasErrors('session');

        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CASH, 'amount' => '25.00',
        ])->assertRedirect(route('staff.sessions.show', $session->id));
        $this->assertSame('0.00', $session->fresh()->dueAmount());
    }

    public function test_waiter_can_add_products_to_an_open_adisyon_using_current_branch_price(): void
    {
        [$cafe, $branch, $session] = $this->createOpenSession();
        $category = $cafe->categories()->create(['name' => 'İçecekler', 'is_active' => true]);
        $product = $cafe->products()->create([
            'category_id' => $category->id, 'name' => 'Limonata', 'price' => '42.50', 'is_available' => true,
        ]);
        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id]);
        $branch->staff()->attach($waiter->id);

        $this->actingAs($waiter)->post(route('staff.sessions.items.store', $session->id), [
            'items' => [$product->id => 2], 'note' => 'Buzsuz',
        ])->assertRedirect(route('staff.sessions.show', $session->id));

        $addedOrder = Order::query()->where('source', 'waiter')->with('items')->firstOrFail();
        $this->assertSame(Order::STATUS_SERVED, $addedOrder->status);
        $this->assertSame('85.00', $addedOrder->total);
        $this->assertSame('Limonata', $addedOrder->items->first()->name_snapshot);
        $this->assertSame('42.50', $addedOrder->items->first()->unit_price);
        $this->assertSame('Buzsuz', $addedOrder->customer_note);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.added_by_waiter', 'subject_id' => $addedOrder->id]);
        $this->actingAs($waiter)->get(route('staff.sessions.show', $session->id))
            ->assertOk()->assertSee('Adisyona ürün ekle')->assertSee('Ödeme dağılımı');
    }

    /** @return array{Cafe, Branch, TableSession, Order} */
    private function createOpenSession(): array
    {
        $cafe = Cafe::query()->create(['name' => 'Kıyı Kahve', 'slug' => 'kiyi-'.uniqid(), 'status' => Cafe::STATUS_ACTIVE]);
        $branch = $cafe->branches()->create(['name' => 'Merkez', 'slug' => 'merkez', 'is_active' => true]);
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
            'order_number' => 'ORDER'.uniqid(),
            'source' => 'qr',
            'status' => Order::STATUS_SERVED,
            'subtotal' => '250.00',
            'discount_total' => '0.00',
            'total' => '250.00',
            'served_at' => now(),
        ]);
        $order->items()->create(['name_snapshot' => 'Kahve', 'unit_price' => '125.00', 'quantity' => 2, 'line_total' => '250.00']);

        return [$cafe, $branch, $session, $order];
    }
}
