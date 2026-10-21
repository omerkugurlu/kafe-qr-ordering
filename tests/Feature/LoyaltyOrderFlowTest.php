<?php

namespace Tests\Feature;

use App\Livewire\Staff\OrderBoard;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyCampaign;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LoyaltyOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_redeems_points_and_earns_points_after_paid_service(): void
    {
        $cafe = Cafe::query()->create([
            'name' => 'Kıyı Kahve', 'slug' => 'kiyi-'.uniqid(), 'status' => Cafe::STATUS_ACTIVE,
            'loyalty_enabled' => true, 'points_per_100' => 1,
        ]);
        $branch = $cafe->branches()->create(['name' => 'Merkez', 'slug' => 'merkez', 'uses_shared_menu' => true, 'is_active' => true]);
        $category = $cafe->categories()->create(['name' => 'Kahveler', 'is_active' => true]);
        $product = $cafe->products()->create(['category_id' => $category->id, 'name' => 'Filtre kahve', 'price' => '250.00', 'is_available' => true]);
        $table = DiningTable::createWithToken(['branch_id' => $branch->id, 'name' => 'Masa 4', 'is_active' => true]);
        TableSession::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'status' => TableSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cafe_id' => $cafe->id]);
        LoyaltyAccount::query()->create(['cafe_id' => $cafe->id, 'user_id' => $customer->id, 'points' => 100, 'completed_order_count' => 0]);
        $campaign = $cafe->loyaltyCampaigns()->create([
            'name' => '100 puan indirimi', 'type' => LoyaltyCampaign::TYPE_POINTS, 'threshold' => 100,
            'points_required' => 100, 'reward_type' => LoyaltyCampaign::REWARD_FIXED, 'reward_value' => '30.00',
            'minimum_order_total' => '0.00', 'is_active' => true,
        ]);

        $this->actingAs($customer)->get(route('customer.menu', $table->token()))->assertSee('100 puan indirimi');
        $this->actingAs($customer)->post(route('customer.orders.store', $table->token()), [
            'items' => [$product->id => 1], 'loyalty_campaign_id' => $campaign->id,
        ])->assertRedirect(route('customer.menu', $table->token()));

        $order = Order::query()->firstOrFail();
        $this->assertSame('30.00', $order->discount_total);
        $this->assertSame('220.00', $order->total);
        $this->assertDatabaseHas('loyalty_accounts', ['user_id' => $customer->id, 'points' => 0]);

        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id]);
        $branch->staff()->attach($waiter->id);
        Livewire::actingAs($waiter)->test(OrderBoard::class, ['branchId' => $branch->id])->call('accept', $order->id)->assertHasNoErrors();
        $session = $order->tableSession;
        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CARD, 'amount' => '220.00',
        ])->assertRedirect(route('staff.branch', $branch->id));

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => Order::STATUS_PAID]);
        $this->assertNotNull($order->fresh()->served_at);
        $this->assertDatabaseHas('loyalty_accounts', ['user_id' => $customer->id, 'points' => 2, 'completed_order_count' => 1]);
        $this->assertDatabaseHas('loyalty_transactions', ['order_id' => $order->id, 'type' => 'earned', 'points_delta' => 2]);
    }
}
