<?php

namespace Tests\Feature;

use App\Models\MenuCategory;
use App\Models\MenuOption;
use App\Models\MenuOptionGroup;
use App\Models\MenuProduct;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_is_idempotent_and_keeps_order_item_product_links(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(4, MenuCategory::query()->count());
        $this->assertSame(15, MenuProduct::query()->count());
        $this->assertSame(9, MenuOption::query()->count());
        $this->assertSame(3, MenuOptionGroup::query()->count());
        $this->assertSame(1, MenuProduct::query()->where('name', 'Espresso')->count());

        $productIds = MenuProduct::query()->pluck('id')->sort()->values();
        $this->seed();
        $this->assertSame($productIds->all(), MenuProduct::query()->pluck('id')->sort()->values()->all());

        $linkedProductIds = OrderItem::query()->whereNotNull('menu_product_id')->pluck('menu_product_id')->unique();
        $this->assertNotEmpty($linkedProductIds);
        $this->assertSame(
            0,
            $linkedProductIds->diff(MenuProduct::query()->pluck('id'))->count(),
        );
    }

    public function test_demo_seeder_does_not_create_a_default_platform_admin(): void
    {
        $this->seed();

        $this->assertSame(0, User::query()->where('role', User::ROLE_PLATFORM_ADMIN)->count());
    }

    public function test_demo_seeder_rotates_demo_account_passwords(): void
    {
        $this->seed();
        $owner = User::query()->where('email', 'cafe34@mail.com')->firstOrFail();
        $originalHash = $owner->password;

        $this->seed();

        $this->assertNotSame($originalHash, $owner->fresh()->password);
    }
}
