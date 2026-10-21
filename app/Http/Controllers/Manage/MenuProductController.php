<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MenuProductController extends Controller
{
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

    private function log(Request $request, Branch $branch, MenuProduct $product, string $action): void
    {
        ActivityLog::query()->create([
            'cafe_id' => $branch->cafe_id,
            'branch_id' => $branch->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => MenuProduct::class,
            'subject_id' => $product->id,
            'metadata' => ['name' => $product->name, 'price' => $product->price, 'is_available' => $product->is_available],
            'ip_address' => $request->ip(),
        ]);
    }
}
