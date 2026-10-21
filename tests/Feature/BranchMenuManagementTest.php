<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\MenuProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchMenuManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_update_preserves_shared_menu_and_active_state_when_flags_are_omitted(): void
    {
        [$cafe, $branch, $product] = $this->createSharedMenu();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);

        $this->actingAs($owner)->patch(route('manage.branches.update', $branch), ['name' => 'Yeni Merkez'])
            ->assertRedirect(route('manage.branches.show', $branch));

        $branch->refresh();
        $this->assertSame('Yeni Merkez', $branch->name);
        $this->assertTrue($branch->uses_shared_menu);
        $this->assertTrue($branch->is_active);
        $this->assertSame('125.00', $product->fresh()->price);
    }

    public function test_owner_can_override_shared_product_price_and_availability_for_one_branch(): void
    {
        [$cafe, $branch, $product] = $this->createSharedMenu();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);
        $table = DiningTable::createWithToken(['branch_id' => $branch->id, 'name' => 'Masa 1', 'is_active' => true]);

        $this->actingAs($owner)->patch(route('manage.products.branch-update', [$branch, $product->id]), [
            'override_enabled' => 1,
            'price' => '140.00',
            'is_available' => 0,
        ])->assertRedirect(route('manage.branches.show', $branch));

        $this->assertDatabaseHas('branch_product', ['branch_id' => $branch->id, 'menu_product_id' => $product->id, 'price' => '140.00', 'is_available' => 0]);
        $this->get(route('customer.menu', $table->token()))->assertOk()->assertDontSee('Filtre kahve');

        $this->actingAs($owner)->patch(route('manage.products.branch-update', [$branch, $product->id]), [
            'override_enabled' => 0,
        ])->assertRedirect(route('manage.branches.show', $branch));
        $this->assertDatabaseMissing('branch_product', ['branch_id' => $branch->id, 'menu_product_id' => $product->id]);
        $this->get(route('customer.menu', $table->token()))->assertSee('Filtre kahve')->assertSee('125,00');

        $this->actingAs($owner)->patch(route('manage.products.branch-update', [$branch, $product->id]), [
            'override_enabled' => 1,
            'price' => '140.00',
            'is_available' => 1,
        ])->assertRedirect(route('manage.branches.show', $branch));
        $this->actingAs($owner)->delete(route('manage.products.destroy', [$branch, $product->id]))
            ->assertRedirect(route('manage.branches.show', $branch));
        $this->get(route('customer.menu', $table->token()))->assertDontSee('Filtre kahve');
    }

    /** @return array{Cafe, Branch, MenuProduct} */
    private function createSharedMenu(): array
    {
        $cafe = Cafe::query()->create(['name' => 'Kıyı Kahve', 'slug' => 'kiyi-'.uniqid(), 'status' => Cafe::STATUS_ACTIVE]);
        $branch = $cafe->branches()->create(['name' => 'Merkez', 'slug' => 'merkez', 'uses_shared_menu' => true, 'is_active' => true]);
        $category = $cafe->categories()->create(['name' => 'Kahveler', 'is_active' => true]);
        $product = $cafe->products()->create(['category_id' => $category->id, 'name' => 'Filtre kahve', 'price' => '125.00', 'is_available' => true]);

        return [$cafe, $branch, $product];
    }
}
