<?php

namespace Tests\Feature;

use App\Livewire\Staff\OrderBoard;
use App\Models\Branch;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\MenuOption;
use App\Models\MenuOptionGroup;
use App\Models\MenuProduct;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductOptionsFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_cafe_owner_can_create_free_and_paid_product_options(): void
    {
        [$cafe, $branch, $product] = $this->createCafeProduct();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id, 'is_active' => true]);

        $this->actingAs($owner)->post(route('manage.products.option-groups.store', [$branch, $product->id]), [
            'name' => 'Lezzet seçimi',
            'selection_mode' => 'single',
            'min_selections' => 1,
            'max_selections' => 1,
            'options' => [
                ['name' => 'Karamelli', 'price_delta' => '0.00', 'is_available' => 1],
                ['name' => 'Çikolatalı', 'price_delta' => '30.00', 'is_available' => 1],
            ],
        ])->assertRedirect(route('manage.branches.show', $branch));

        $group = MenuOptionGroup::query()->with('options')->firstOrFail();
        $this->assertSame('Lezzet seçimi', $group->name);
        $this->assertSame('0.00', $group->options->firstWhere('name', 'Karamelli')->price_delta);
        $this->assertSame('30.00', $group->options->firstWhere('name', 'Çikolatalı')->price_delta);
        $this->assertDatabaseHas('activity_logs', ['action' => 'menu.option_group_created']);
    }

    public function test_qr_order_prices_options_per_item_and_keeps_historical_snapshots(): void
    {
        [$cafe, $branch, $product, $table, $token] = $this->createCafeProduct('Buzlu latte', '100.00');
        [$group, $free, $paid] = $this->createFlavorOptions($product->id);

        $this->get(route('customer.menu', $token))
            ->assertOk()
            ->assertSee('Ürünü kişiselleştir')
            ->assertSee('Çikolatalı')
            ->assertSee('+₺30,00');

        $this->post(route('customer.orders.store', $token), [
            'items' => [$product->id => 2],
            'options' => [$product->id => [$group->id => $paid->id]],
        ])->assertRedirect(route('customer.menu', $token));

        $order = Order::query()->with('items.options')->firstOrFail();
        $item = $order->items->firstOrFail();
        $this->assertSame('260.00', $order->total);
        $this->assertSame('130.00', $item->unit_price);
        $this->assertSame('260.00', $item->line_total);
        $this->assertSame('Lezzet seçimi', $item->options->first()->group_name_snapshot);
        $this->assertSame('Çikolatalı', $item->options->first()->name_snapshot);
        $this->assertSame('30.00', $item->options->first()->price_delta);

        $paid->forceFill(['name' => 'Yeni ad', 'price_delta' => '50.00'])->save();
        $this->assertSame('Çikolatalı', $item->fresh()->options->first()->name_snapshot);
        $this->assertSame('30.00', $item->fresh()->options->first()->price_delta);

        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id, 'is_active' => true]);
        $branch->staff()->attach($waiter->id);
        Livewire::actingAs($waiter)->test(OrderBoard::class, ['branchId' => $branch->id])->call('accept', $order->id)->assertHasNoErrors();
        Livewire::actingAs($waiter)->test(OrderBoard::class, ['branchId' => $branch->id])->call('serve', $order->id)->assertHasNoErrors();

        $session = TableSession::query()->findOrFail($order->table_session_id);
        $this->assertSame('260.00', $session->dueAmount());
        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CASH,
            'amount' => '100.00',
        ])->assertRedirect(route('staff.sessions.show', $session->id));
        $this->assertSame('160.00', $session->fresh()->dueAmount());

        $this->actingAs($waiter)->post(route('staff.sessions.payments.store', $session->id), [
            'method' => Payment::METHOD_CARD,
            'amount' => '160.00',
        ])->assertRedirect(route('staff.branch', $branch->id));
        $this->assertSame('0.00', $session->fresh()->dueAmount());
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(TableSession::STATUS_CLOSED, $session->fresh()->status);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_qr_order_requires_configured_choices_and_rejects_another_products_option(): void
    {
        [$cafe, $branch, $product, $table, $token] = $this->createCafeProduct();
        [$group, $free, $paid] = $this->createFlavorOptions($product->id, true);
        [, , $otherProduct] = $this->createCafeProduct('Başka latte', '100.00', $cafe, $branch);
        [, $otherFree] = $this->createFlavorOptions($otherProduct->id);

        $this->post(route('customer.orders.store', $token), ['items' => [$product->id => 1]])
            ->assertSessionHasErrors('options');
        $this->post(route('customer.orders.store', $token), [
            'items' => [$product->id => 1],
            'options' => [$product->id => [$group->id => $otherFree->id]],
        ])->assertSessionHasErrors('options');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_options_for_products_with_zero_quantity_do_not_block_other_order_items(): void
    {
        [$cafe, $branch, $product, , $token] = $this->createCafeProduct();
        [$group, $free] = $this->createFlavorOptions($product->id, true);
        [, , $otherProduct] = $this->createCafeProduct('Başka latte', '100.00', $cafe, $branch);
        [$otherGroup, , $otherPaid] = $this->createFlavorOptions($otherProduct->id, true);

        $items = [$product->id => 1, $otherProduct->id => 0] + array_fill_keys(range(10000, 10034), 0);
        $this->post(route('customer.orders.store', $token), [
            'items' => $items,
            'options' => [
                $product->id => [$group->id => $free->id],
                $otherProduct->id => [$otherGroup->id => $otherPaid->id],
            ],
        ])->assertRedirect(route('customer.menu', $token));

        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(1, Order::query()->with('items')->firstOrFail()->items->count());
    }

    public function test_inactive_customer_account_cannot_place_an_order(): void
    {
        [$cafe, , $product, , $token] = $this->createCafeProduct();
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'cafe_id' => $cafe->id,
            'is_active' => false,
        ]);

        $this->actingAs($customer)->post(route('customer.orders.store', $token), [
            'items' => [$product->id => 1],
        ])->assertSessionHasErrors('items');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_customer_qr_routes_reject_a_session_with_an_outdated_password_hash(): void
    {
        [$cafe, , , , $token] = $this->createCafeProduct();
        $customer = User::factory()->create([
            'role' => User::ROLE_CUSTOMER,
            'cafe_id' => $cafe->id,
            'is_active' => true,
        ]);
        $oldPasswordHash = $customer->password;
        $customer->forceFill(['password' => 'New-Customer-Password-2026!'])->save();

        $this->withSession(['password_hash_web' => $oldPasswordHash])
            ->actingAs($customer->fresh())
            ->get(route('customer.menu', $token))
            ->assertRedirect(route('login'));
    }

    public function test_order_rejects_more_than_thirty_selected_product_lines(): void
    {
        [, , , , $token] = $this->createCafeProduct();

        $this->post(route('customer.orders.store', $token), [
            'items' => array_fill_keys(range(10000, 10030), 1),
        ])->assertSessionHasErrors('items');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_waiter_can_add_a_product_with_paid_options_to_an_open_adisyon(): void
    {
        [$cafe, $branch, $product] = $this->createCafeProduct('Buzlu latte', '100.00');
        [$group, $free, $paid] = $this->createFlavorOptions($product->id);
        $table = DiningTable::createWithToken(['branch_id' => $branch->id, 'name' => 'Masa 6', 'is_active' => true]);
        $session = TableSession::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'status' => TableSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);
        $waiter = User::factory()->create(['role' => User::ROLE_WAITER, 'cafe_id' => $cafe->id, 'is_active' => true]);
        $branch->staff()->attach($waiter->id);

        $this->actingAs($waiter)->post(route('staff.sessions.items.store', $session->id), [
            'items' => [$product->id => 1],
            'options' => [$product->id => [$group->id => $paid->id]],
        ])->assertRedirect(route('staff.sessions.show', $session->id));

        $order = Order::query()->where('source', 'waiter')->with('items.options')->firstOrFail();
        $this->assertSame('130.00', $order->total);
        $this->assertSame('Çikolatalı', $order->items->first()->options->first()->name_snapshot);
    }

    /** @return array{Cafe, Branch, MenuProduct, DiningTable|null, string|null} */
    private function createCafeProduct(
        string $productName = 'Buzlu latte',
        string $price = '100.00',
        ?Cafe $cafe = null,
        ?Branch $branch = null,
    ): array {
        $cafe ??= Cafe::query()->create(['name' => 'Kıyı Kahve', 'slug' => 'kiyi-'.uniqid(), 'status' => Cafe::STATUS_ACTIVE]);
        $branch ??= $cafe->branches()->create(['name' => 'Merkez', 'slug' => 'merkez', 'is_active' => true]);
        $category = $cafe->categories()->create(['name' => 'Kahveler', 'is_active' => true]);
        $product = $cafe->products()->create([
            'category_id' => $category->id,
            'name' => $productName,
            'price' => $price,
            'is_available' => true,
        ]);
        $table = DiningTable::createWithToken(['branch_id' => $branch->id, 'name' => 'Masa '.uniqid(), 'is_active' => true]);
        TableSession::query()->create([
            'branch_id' => $branch->id,
            'dining_table_id' => $table->id,
            'status' => TableSession::STATUS_OPEN,
            'opened_at' => now(),
        ]);

        return [$cafe, $branch, $product, $table, $table->token()];
    }

    /** @return array{MenuOptionGroup, MenuOption, MenuOption} */
    private function createFlavorOptions(int $productId, bool $required = false): array
    {
        $group = MenuOptionGroup::query()->create([
            'menu_product_id' => $productId,
            'name' => 'Lezzet seçimi',
            'selection_mode' => 'single',
            'min_selections' => $required ? 1 : 0,
            'max_selections' => 1,
            'is_active' => true,
        ]);
        $free = $group->options()->create(['name' => 'Karamelli', 'price_delta' => '0.00', 'is_available' => true]);
        $paid = $group->options()->create(['name' => 'Çikolatalı', 'price_delta' => '30.00', 'is_available' => true]);

        return [$group, $free, $paid];
    }
}
