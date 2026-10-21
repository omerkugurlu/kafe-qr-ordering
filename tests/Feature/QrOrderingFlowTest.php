<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\MenuProduct;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrOrderingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_view_a_table_menu_and_submit_server_priced_order(): void
    {
        [$cafe, $branch, $table, $token, $product] = $this->createCafeTableAndProduct();

        $this->get(route('customer.menu', $token))
            ->assertOk()
            ->assertSee('Kıyı Kahve')
            ->assertSee('Filtre kahve');

        $this->post(route('customer.orders.store', $token), [
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

    public function test_order_rejects_products_from_another_cafe(): void
    {
        [, , , $token] = $this->createCafeTableAndProduct();
        [$otherCafe, $otherBranch, $otherTable, , $otherProduct] = $this->createCafeTableAndProduct('Yabancı kafe');

        $this->post(route('customer.orders.store', $token), [
            'items' => [$otherProduct->id => 1],
        ])->assertSessionHasErrors('items');

        $this->assertDatabaseCount('orders', 0);
    }

    public function test_required_registration_is_bound_to_the_cafe_from_the_table_qr(): void
    {
        [, , $table, $token, $product] = $this->createCafeTableAndProduct('Hesap isteyen kafe', Cafe::POLICY_REQUIRED);

        $this->post(route('customer.orders.store', $token), ['items' => [$product->id => 1]])
            ->assertRedirect(route('customer.login', $token));

        $this->post(route('customer.register.store', $token), [
            'name' => 'Deniz Kaya',
            'email' => 'deniz@example.test',
            'password' => 'Secure-Customer-2026!',
            'password_confirmation' => 'Secure-Customer-2026!',
        ])->assertRedirect(route('customer.menu', $token));

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

        return [$cafe, $branch, $table, $table->token(), $product];
    }
}
