<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuOptionGroup;
use App\Models\MenuProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MenuProductController extends Controller
{
    public function storeOptionGroup(Request $request, Branch $branch, int $productId): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $product = $this->product($branch, $productId);
        $validated = $this->validateOptionGroup($request);
        $this->assertOptionLimits($validated);

        DB::transaction(function () use ($request, $branch, $product, $validated): void {
            $lockedProduct = $this->product($branch, $product->id)->newQuery()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $group = $lockedProduct->optionGroups()->create([
                'name' => $validated['name'],
                'selection_mode' => $validated['selection_mode'],
                'min_selections' => $validated['min_selections'],
                'max_selections' => $validated['max_selections'] ?? null,
                'sort_order' => $lockedProduct->optionGroups()->max('sort_order') + 1,
                'is_active' => $request->boolean('is_active', true),
            ]);
            $this->syncOptionChoices($group, $validated['options']);
            $this->log($request, $branch, $lockedProduct, 'menu.option_group_created', ['group_id' => $group->id, 'name' => $group->name]);
        });

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Ürün seçenekleri eklendi.');
    }

    public function updateOptionGroup(Request $request, Branch $branch, int $productId, int $groupId): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $product = $this->product($branch, $productId);
        $group = MenuOptionGroup::query()->where('menu_product_id', $product->id)->whereKey($groupId)->firstOrFail();
        $validated = $this->validateOptionGroup($request);
        $this->assertOptionLimits($validated);

        DB::transaction(function () use ($request, $branch, $product, $group, $validated): void {
            $this->product($branch, $product->id)->newQuery()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $lockedGroup = MenuOptionGroup::query()->where('menu_product_id', $product->id)->whereKey($group->id)->lockForUpdate()->firstOrFail();
            $lockedGroup->fill([
                'name' => $validated['name'],
                'selection_mode' => $validated['selection_mode'],
                'min_selections' => $validated['min_selections'],
                'max_selections' => $validated['max_selections'] ?? null,
                'is_active' => $request->boolean('is_active'),
            ])->save();
            $this->syncOptionChoices($lockedGroup, $validated['options']);
            $this->log($request, $branch, $product, 'menu.option_group_updated', ['group_id' => $lockedGroup->id, 'name' => $lockedGroup->name]);
        });

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Ürün seçenekleri güncellendi.');
    }

    public function destroyOptionGroup(Request $request, Branch $branch, int $productId, int $groupId): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $product = $this->product($branch, $productId);
        DB::transaction(function () use ($request, $branch, $product, $groupId): void {
            $this->product($branch, $product->id)->newQuery()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $group = MenuOptionGroup::query()->where('menu_product_id', $product->id)->whereKey($groupId)->lockForUpdate()->firstOrFail();
            $group->forceFill(['is_active' => false])->save();
            $group->options()->update(['is_available' => false]);
            $this->log($request, $branch, $product, 'menu.option_group_deactivated', ['group_id' => $group->id, 'name' => $group->name]);
        });

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Seçenek grubu menüden kaldırıldı. Eski sipariş kayıtları korundu.');
    }

    public function store(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $validated = $request->validate([
            'category_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'is_available' => ['sometimes', 'boolean'],
        ]);
        $category = $this->category($branch, (int) $validated['category_id']);
        $branchId = $branch->uses_shared_menu ? null : $branch->id;
        $product = $category->products()->create([
            'cafe_id' => $branch->cafe_id,
            'branch_id' => $branchId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'is_available' => $request->boolean('is_available', true),
            'sort_order' => MenuProduct::query()->where('category_id', $category->id)->max('sort_order') + 1,
        ]);
        $this->log($request, $branch, $product, 'menu.product_created');

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Ürün eklendi.');
    }

    public function update(Request $request, Branch $branch, int $productId): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $product = $this->product($branch, $productId);
        $validated = $request->validate([
            'category_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'is_available' => ['sometimes', 'boolean'],
        ]);
        $category = $this->category($branch, (int) $validated['category_id']);
        $product->fill([...$validated, 'category_id' => $category->id, 'is_available' => $request->boolean('is_available')])->save();
        $this->log($request, $branch, $product, 'menu.product_updated');

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Ürün güncellendi.');
    }

    public function destroy(Request $request, Branch $branch, int $productId): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $product = $this->product($branch, $productId)->forceFill(['is_available' => false]);
        $product->save();
        $this->log($request, $branch, $product, 'menu.product_deactivated');

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Ürün satışa kapatıldı. Geçmiş sipariş kayıtları korundu.');
    }

    public function updateBranchOverride(Request $request, Branch $branch, int $productId): RedirectResponse
    {
        Gate::authorize('update', $branch);
        abort_unless($branch->uses_shared_menu, 404);
        $product = MenuProduct::query()->where('cafe_id', $branch->cafe_id)->whereNull('branch_id')->whereKey($productId)->firstOrFail();
        $validated = $request->validate([
            'override_enabled' => ['required', 'boolean'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'is_available' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('override_enabled') && ! array_key_exists('price', $validated)) {
            throw ValidationException::withMessages(['price' => 'Şube fiyatı girin.']);
        }

        DB::transaction(function () use ($branch, $product, $request, $validated): void {
            if ($request->boolean('override_enabled')) {
                DB::table('branch_product')->updateOrInsert(
                    ['branch_id' => $branch->id, 'menu_product_id' => $product->id],
                    [
                        'price' => $validated['price'],
                        'is_available' => $request->boolean('is_available'),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            } else {
                DB::table('branch_product')->where('branch_id', $branch->id)->where('menu_product_id', $product->id)->delete();
            }

            ActivityLog::query()->create([
                'cafe_id' => $branch->cafe_id,
                'branch_id' => $branch->id,
                'user_id' => $request->user()->id,
                'action' => 'menu.branch_override_updated',
                'subject_type' => MenuProduct::class,
                'subject_id' => $product->id,
                'metadata' => ['override_enabled' => $request->boolean('override_enabled'), 'price' => $validated['price'] ?? null, 'is_available' => $request->boolean('is_available')],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Şube fiyatı ve bulunurluğu güncellendi.');
    }

    private function category(Branch $branch, int $categoryId): MenuCategory
    {
        return MenuCategory::query()->where('cafe_id', $branch->cafe_id)
            ->where('branch_id', $branch->uses_shared_menu ? null : $branch->id)
            ->whereKey($categoryId)->firstOrFail();
    }

    private function product(Branch $branch, int $productId): MenuProduct
    {
        return MenuProduct::query()->where('cafe_id', $branch->cafe_id)
            ->where('branch_id', $branch->uses_shared_menu ? null : $branch->id)
            ->whereKey($productId)->firstOrFail();
    }

    /** @param array<string, mixed> $extra */
    private function log(Request $request, Branch $branch, MenuProduct $product, string $action, array $extra = []): void
    {
        ActivityLog::query()->create([
            'cafe_id' => $branch->cafe_id,
            'branch_id' => $branch->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => MenuProduct::class,
            'subject_id' => $product->id,
            'metadata' => ['name' => $product->name, 'price' => $product->price, 'is_available' => $product->is_available, ...$extra],
            'ip_address' => $request->ip(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validateOptionGroup(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'selection_mode' => ['required', 'in:single,multiple'],
            'min_selections' => ['required', 'integer', 'min:0', 'max:10'],
            'max_selections' => ['nullable', 'integer', 'min:1', 'max:10'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['required', 'array', 'min:1', 'max:30'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.name' => ['required', 'string', 'max:80'],
            'options.*.price_delta' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'options.*.is_available' => ['sometimes', 'boolean'],
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function assertOptionLimits(array $validated): void
    {
        $availableCount = count(array_filter(
            $validated['options'],
            fn (array $option): bool => (bool) ($option['is_available'] ?? false),
        ));
        $max = $validated['selection_mode'] === 'single'
            ? 1
            : ($validated['max_selections'] ?? count($validated['options']));

        if (
            $validated['min_selections'] > $max
            || $validated['min_selections'] > $availableCount
            || ($validated['selection_mode'] === 'single' && ($validated['max_selections'] ?? 1) > 1)
        ) {
            throw ValidationException::withMessages(['max_selections' => 'Seçim sınırı seçenek sayısıyla ve asgari seçimle uyumlu olmalıdır.']);
        }
    }

    /** @param array<int, array<string, mixed>> $choices */
    private function syncOptionChoices(MenuOptionGroup $group, array $choices): void
    {
        $retainedIds = [];
        foreach (array_values($choices) as $sortOrder => $choice) {
            $attributes = [
                'name' => $choice['name'],
                'price_delta' => $choice['price_delta'],
                'is_available' => (bool) ($choice['is_available'] ?? false),
                'sort_order' => $sortOrder,
            ];

            if (! empty($choice['id'])) {
                $option = $group->options()->whereKey($choice['id'])->lockForUpdate()->firstOrFail();
                $option->fill($attributes)->save();
                $retainedIds[] = $option->id;
            } else {
                $option = $group->options()->create($attributes);
                $retainedIds[] = $option->id;
            }
        }

        $group->options()->whereNotIn('id', $retainedIds)->update(['is_available' => false]);
    }
}
