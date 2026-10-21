<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\MenuCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class MenuCategoryController extends Controller
{
    public function store(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $branchId = $branch->uses_shared_menu ? null : $branch->id;
        $category = MenuCategory::query()->create([
            'cafe_id' => $branch->cafe_id,
            'branch_id' => $branchId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'sort_order' => MenuCategory::query()->where('cafe_id', $branch->cafe_id)->where('branch_id', $branchId)->max('sort_order') + 1,
            'is_active' => true,
        ]);
        $this->log($request, $branch, $category, 'menu.category_created');

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Kategori eklendi.');
    }

    public function update(Request $request, Branch $branch, int $categoryId): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $category = $this->category($branch, $categoryId);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $category->fill([
            ...$validated,
            'is_active' => $request->boolean('is_active'),
        ])->save();
        $this->log($request, $branch, $category, 'menu.category_updated');

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Kategori güncellendi.');
    }

    public function destroy(Request $request, Branch $branch, int $categoryId): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $category = $this->category($branch, $categoryId);
        if ($category->products()->exists()) {
            $category->forceFill(['is_active' => false])->save();
            $this->log($request, $branch, $category, 'menu.category_deactivated');

            return redirect()->route('manage.branches.show', $branch)->with('status', 'Ürün geçmişi korunsun diye kategori pasife alındı.');
        }
        $this->log($request, $branch, $category, 'menu.category_deleted');
        $category->delete();

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Kategori kaldırıldı.');
    }

    private function category(Branch $branch, int $categoryId): MenuCategory
    {
        return MenuCategory::query()
            ->where('cafe_id', $branch->cafe_id)
            ->where('branch_id', $branch->uses_shared_menu ? null : $branch->id)
            ->whereKey($categoryId)->firstOrFail();
    }

    private function log(Request $request, Branch $branch, MenuCategory $category, string $action): void
    {
        ActivityLog::query()->create([
            'cafe_id' => $branch->cafe_id,
            'branch_id' => $branch->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => MenuCategory::class,
            'subject_id' => $category->id,
            'metadata' => ['name' => $category->name, 'active' => $category->is_active],
            'ip_address' => $request->ip(),
        ]);
    }
}
