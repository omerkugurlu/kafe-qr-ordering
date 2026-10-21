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

    public function test_owner_can_create_a_branch_menu_by_copying_the_shared_menu(): void
    {
        [$cafe, $branch, $sharedProduct] = $this->createSharedMenu();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);

        $this->actingAs($owner)->post("/yonetim/subeler/{$branch->id}/menu-kopyala", [
            'action' => 'copy',
            'source' => 'shared',
        ])->assertRedirect(route('manage.branches.show', $branch));

        $branch->refresh();
        $this->assertFalse($branch->uses_shared_menu);
        $this->assertDatabaseHas('menu_categories', [
            'cafe_id' => $cafe->id,
            'branch_id' => $branch->id,
            'name' => 'Kahveler',
        ]);
        $this->assertDatabaseHas('menu_products', [
            'cafe_id' => $cafe->id,
            'branch_id' => $branch->id,
            'name' => 'Filtre kahve',
            'price' => '125.00',
        ]);
        $this->assertNotSame($sharedProduct->id, $cafe->products()->where('branch_id', $branch->id)->value('id'));
    }

    public function test_owner_can_start_a_branch_menu_empty(): void
    {
        [$cafe, $branch] = $this->createSharedMenu();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);

        $this->actingAs($owner)->post("/yonetim/subeler/{$branch->id}/menu-kopyala", [
            'action' => 'empty',
        ])->assertRedirect(route('manage.branches.show', $branch));

        $this->assertFalse($branch->fresh()->uses_shared_menu);
        $this->assertDatabaseMissing('menu_categories', ['cafe_id' => $cafe->id, 'branch_id' => $branch->id]);
    }

    public function test_owner_can_copy_another_active_branch_menu_and_edit_it_independently(): void
    {
        [$cafe, $target] = $this->createSharedMenu();
        $source = $cafe->branches()->create(['name' => 'Kadıköy', 'slug' => 'kadikoy', 'uses_shared_menu' => false, 'is_active' => true]);
        $sourceCategory = $cafe->categories()->create(['branch_id' => $source->id, 'name' => 'Soğuklar', 'is_active' => true]);
        $sourceProduct = $sourceCategory->products()->create([
            'cafe_id' => $cafe->id,
            'branch_id' => $source->id,
            'name' => 'Cold brew',
            'price' => '155.00',
            'is_available' => true,
        ]);
        $sourceGroup = $sourceProduct->optionGroups()->create([
            'name' => 'Lezzet seç',
            'selection_mode' => 'single',
            'min_selections' => 0,
            'max_selections' => 1,
            'sort_order' => 2,
            'is_active' => true,
        ]);
        $sourceGroup->options()->create(['name' => 'Karamel', 'price_delta' => '0.00', 'is_available' => true, 'sort_order' => 1]);
        $sourceGroup->options()->create(['name' => 'Çikolata', 'price_delta' => '30.00', 'is_available' => true, 'sort_order' => 2]);
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);

        $this->actingAs($owner)->post("/yonetim/subeler/{$target->id}/menu-kopyala", [
            'action' => 'copy',
            'source' => 'branch',
            'source_branch_id' => $source->id,
        ])->assertRedirect(route('manage.branches.show', $target));

        $this->assertFalse($target->fresh()->uses_shared_menu);
        $copy = $cafe->products()->where('branch_id', $target->id)->where('name', 'Cold brew')->firstOrFail();
        $this->assertSame('155.00', $copy->price);
        $this->assertNotSame($sourceProduct->id, $copy->id);
        $copy->forceFill(['price' => '165.00'])->save();
        $this->assertSame('155.00', $sourceProduct->fresh()->price);

        $copyGroup = $copy->optionGroups()->with('options')->firstOrFail();
        $this->assertNotSame($sourceGroup->id, $copyGroup->id);
        $this->assertSame('single', $copyGroup->selection_mode);
        $this->assertSame(0, $copyGroup->min_selections);
        $this->assertSame(1, $copyGroup->max_selections);
        $this->assertSame(['Karamel', 'Çikolata'], $copyGroup->options->pluck('name')->all());
        $this->assertSame(['0.00', '30.00'], $copyGroup->options->pluck('price_delta')->all());
    }

    public function test_branch_menu_copy_rejects_a_source_from_another_cafe(): void
    {
        [$cafe, $target] = $this->createSharedMenu();
        $otherCafe = Cafe::query()->create(['name' => 'Başka Kafe', 'slug' => 'baska-'.uniqid(), 'status' => Cafe::STATUS_ACTIVE]);
        $foreignSource = $otherCafe->branches()->create(['name' => 'Merkez', 'slug' => 'merkez', 'uses_shared_menu' => false, 'is_active' => true]);
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);

        $this->actingAs($owner)->post("/yonetim/subeler/{$target->id}/menu-kopyala", [
            'action' => 'copy',
            'source' => 'branch',
            'source_branch_id' => $foreignSource->id,
        ])->assertSessionHasErrors('source_branch_id');

        $this->assertTrue($target->fresh()->uses_shared_menu);
        $this->assertDatabaseMissing('menu_categories', ['cafe_id' => $cafe->id, 'branch_id' => $target->id]);
    }

    public function test_switching_back_to_shared_keeps_the_existing_branch_menu_for_later(): void
    {
        [$cafe, $branch] = $this->createSharedMenu();
        $privateCategory = $cafe->categories()->create(['branch_id' => $branch->id, 'name' => 'Özel içecekler', 'is_active' => true]);
        $branch->forceFill(['uses_shared_menu' => false])->save();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);

        $this->actingAs($owner)->post("/yonetim/subeler/{$branch->id}/menu-kopyala", [
            'action' => 'shared',
        ])->assertRedirect(route('manage.branches.show', $branch));

        $this->assertTrue($branch->fresh()->uses_shared_menu);
        $this->assertDatabaseHas('menu_categories', ['id' => $privateCategory->id, 'branch_id' => $branch->id, 'name' => 'Özel içecekler']);
    }

    public function test_replacing_existing_branch_menu_requires_explicit_confirmation(): void
    {
        [$cafe, $branch] = $this->createSharedMenu();
        $oldCategory = $cafe->categories()->create(['branch_id' => $branch->id, 'name' => 'Özel menü', 'is_active' => true]);
        $branch->forceFill(['uses_shared_menu' => false])->save();
        $owner = User::factory()->create(['role' => User::ROLE_CAFE_OWNER, 'cafe_id' => $cafe->id]);

        $this->actingAs($owner)->post("/yonetim/subeler/{$branch->id}/menu-kopyala", [
            'action' => 'empty',
        ])->assertSessionHasErrors('confirm_replace');

        $this->assertDatabaseHas('menu_categories', ['id' => $oldCategory->id]);

        $this->actingAs($owner)->post("/yonetim/subeler/{$branch->id}/menu-kopyala", [
            'action' => 'empty',
            'confirm_replace' => 1,
        ])->assertRedirect(route('manage.branches.show', $branch));

        $this->assertDatabaseMissing('menu_categories', ['id' => $oldCategory->id]);
        $this->assertFalse($branch->fresh()->uses_shared_menu);
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
