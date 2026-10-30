<?php

namespace Tests\Feature;

use App\Livewire\Staff\OrderBoard;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyCampaign;
use App\Models\MenuCategory;
use App\Models\MenuProduct;
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

    public function test_customer_can_redeem_points_for_a_free_product(): void
    {
        $cafe = Cafe::query()->create([
            'name' => 'Puan Kafe', 'slug' => 'puan-'.uniqid(), 'status' => Cafe::STATUS_ACTIVE,
            'loyalty_enabled' => true, 'points_per_100' => 1,
        ]);
        $branch = $cafe->branches()->create(['name' => 'Merkez', 'slug' => 'merkez', 'uses_shared_menu' => true, 'is_active' => true]);
        $category = MenuCategory::query()->create(['cafe_id' => $cafe->id, 'name' => 'Kahveler', 'is_active' => true]);
        $orderProduct = MenuProduct::query()->create([
            'cafe_id' => $cafe->id, 'category_id' => $category->id, 'name' => 'Filtre kahve',
            'price' => '50.00', 'is_available' => true,
        ]);
        $freeProduct = MenuProduct::query()->create([
            'cafe_id' => $cafe->id, 'category_id' => $category->id, 'name' => 'Kurabiye',
            'price' => '25.00', 'is_available' => true,
        ]);
        $table = DiningTable::createWithToken(['branch_id' => $branch->id, 'name' => 'Masa 1', 'is_active' => true]);
        TableSession::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'status' => TableSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cafe_id' => $cafe->id]);
        LoyaltyAccount::query()->create(['cafe_id' => $cafe->id, 'user_id' => $customer->id, 'points' => 100, 'completed_order_count' => 0]);
        $campaign = $cafe->loyaltyCampaigns()->create([
            'name' => '100 puana kurabiye', 'type' => LoyaltyCampaign::TYPE_POINTS, 'threshold' => 100,
            'points_required' => 100, 'reward_type' => LoyaltyCampaign::REWARD_PRODUCT,
            'reward_product_id' => $freeProduct->id, 'reward_value' => '0.00',
            'minimum_order_total' => '0.00', 'is_active' => true,
        ]);

        $menuResponse = $this->actingAs($customer)->get(route('customer.menu', $table->token()));
        $menuResponse->assertOk()->assertSee('Ücretsiz: Kurabiye');
        preg_match('~<script type="application/json" id="menu-data">(.*?)</script>~s', $menuResponse->getContent(), $matches);
        $menuData = json_decode($matches[1] ?? '', true);
        $this->actingAs($customer)->post(route('customer.orders.store', $table->token()), [
            'session_id' => $table->openSession()->id,
            'qr_visit_id' => $menuData['qr_visit_id'] ?? null,
            'items' => [$orderProduct->id => 1],
            'loyalty_campaign_id' => $campaign->id,
        ])->assertRedirect(route('customer.menu', $table->token()));

        $order = Order::query()->firstOrFail();
        $this->assertSame('50.00', $order->total);
        $this->assertSame(2, $order->items()->count());
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'menu_product_id' => $freeProduct->id,
            'name_snapshot' => 'Kurabiye',
            'unit_price' => '0.00',
            'quantity' => 1,
            'line_total' => '0.00',
        ]);
        $this->assertDatabaseHas('loyalty_accounts', ['user_id' => $customer->id, 'points' => 0]);
        $this->assertDatabaseHas('loyalty_transactions', [
            'order_id' => $order->id,
            'campaign_id' => $campaign->id,
            'type' => 'reward_redeemed',
            'points_delta' => -100,
            'discount_amount' => '0.00',
        ]);
    }

    public function test_customer_redeems_points_and_earns_points_after_paid_service(): void
    {
        $cafe = Cafe::query()->create([
            'name' => 'Kıyı Kahve', 'slug' => 'kiyi-'.uniqid(), 'status' => Cafe::STATUS_ACTIVE,
            'loyalty_enabled' => true, 'points_per_100' => 1, 'loyalty_spend_threshold' => 200,
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

        $menuResponse = $this->actingAs($customer)->get(route('customer.menu', $table->token()));
        $menuResponse->assertSee('100 puan indirimi');
        preg_match('~<script type="application/json" id="menu-data">(.*?)</script>~s', $menuResponse->getContent(), $matches);
        $menuData = json_decode($matches[1] ?? '', true);
        $this->actingAs($customer)->post(route('customer.orders.store', $table->token()), [
            'session_id' => $table->openSession()->id,
            'qr_visit_id' => $menuData['qr_visit_id'] ?? null,
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
        $this->assertDatabaseHas('loyalty_accounts', ['user_id' => $customer->id, 'points' => 1, 'completed_order_count' => 1]);
        $this->assertDatabaseHas('loyalty_transactions', ['order_id' => $order->id, 'type' => 'earned', 'points_delta' => 1]);
    }
}
