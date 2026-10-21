<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_cannot_access_another_cafes_branch_or_announcement(): void
    {
        [$cafeA, $branchA] = $this->cafeWithBranch('Kafe A', 'kafe-a');
        [$cafeB, $branchB] = $this->cafeWithBranch('Kafe B', 'kafe-b');
        $ownerA = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafeA->id]);
        $announcementB = $cafeB->announcements()->create([
            'type' => 'announcement',
            'title' => 'B kafesinin duyurusu',
            'body' => 'Bu duyuru A kafesinden görünmemeli.',
            'is_active' => true,
        ]);

        $this->actingAs($ownerA)->get(route('manage.branches.show', $branchB))->assertForbidden();
        $this->actingAs($ownerA)->patch(route('manage.branches.update', $branchB), [
            'name' => 'Ele geçirildi',
        ])->assertForbidden();

        $this->actingAs($ownerA)->patch(route('manage.announcements.update', $announcementB), [
            'title' => 'Ele geçirildi',
            'type' => 'announcement',
            'is_active' => '1',
        ])->assertNotFound();
        $this->actingAs($ownerA)->delete(route('manage.announcements.destroy', $announcementB))
            ->assertNotFound();

        $this->assertDatabaseHas('branches', ['id' => $branchB->id, 'name' => 'Merkez']);
        $this->assertDatabaseHas('announcements', ['id' => $announcementB->id, 'title' => 'B kafesinin duyurusu']);
        $this->assertSame($cafeA->id, $ownerA->cafe_id);
        $this->assertSame($cafeB->id, $branchB->cafe_id);
        $this->assertSame($cafeA->id, $branchA->cafe_id);
    }

    public function test_waiter_cannot_view_or_modify_another_branch_session_or_order(): void
    {
        [$cafeA, $branchA] = $this->cafeWithBranch('Kafe A', 'kafe-a');
        [$cafeB, $branchB] = $this->cafeWithBranch('Kafe B', 'kafe-b');
        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafeA->id]);
        $branchA->staff()->attach($waiter->id);

        $table = DiningTable::createWithToken(['branch_id' => $branchB->id, 'name' => 'Masa B', 'is_active' => true]);
        $session = TableSession::query()->create([
            'branch_id' => $branchB->id,
            'dining_table_id' => $table->id,
            'status' => TableSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);
        $order = Order::query()->create([
            'cafe_id' => $cafeB->id,
            'branch_id' => $branchB->id,
            'table_session_id' => $session->id,
            'order_number' => 'B'.uniqid(),
            'source' => 'qr',
            'status' => Order::STATUS_PENDING,
            'subtotal' => '100.00',
            'discount_total' => '0.00',
            'total' => '100.00',
        ]);

        $this->actingAs($waiter)->get(route('staff.sessions.show', $session->id))->assertForbidden();
        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => 'cash',
            'amount' => '10.00',
        ])->assertForbidden();
        $this->actingAs($waiter)->post(route('staff.sessions.close', $session->id))->assertForbidden();
        $this->actingAs($waiter)->get(route('staff.board.data', $branchB->id))->assertForbidden();
        $this->actingAs($waiter)->post(route('staff.orders.status', $order->id), [
            'status' => Order::STATUS_ACCEPTED,
        ])->assertForbidden();

        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('table_sessions', ['id' => $session->id, 'status' => TableSession::STATUS_OPEN]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PENDING]);
    }

    /** @return array{Cafe, Branch} */
    private function cafeWithBranch(string $name, string $slug): array
    {
        $cafe = Cafe::query()->create([
            'name' => $name,
            'slug' => $slug,
            'status' => Cafe::STATUS_ACTIVE,
        ]);
        $branch = $cafe->branches()->create([
            'name' => 'Merkez',
            'slug' => 'merkez',
            'is_active' => true,
        ]);

        return [$cafe, $branch];
    }
}
