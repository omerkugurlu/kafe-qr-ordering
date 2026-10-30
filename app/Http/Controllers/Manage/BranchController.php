<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\MenuCategory;
use App\Models\MenuProduct;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function show(Request $request, Branch $branch): View
    {
        Gate::authorize('view', $branch);
        $branch->loadCount('diningTables');
        $menuBranchId = $branch->uses_shared_menu ? null : $branch->id;
        $categories = MenuCategory::query()
            ->where('cafe_id', $branch->cafe_id)
            ->where('branch_id', $menuBranchId)
            ->withCount('products')
            ->orderBy('sort_order')->orderBy('name')->get();
        $categorySessionKey = 'manage.branch.category.'.$branch->id;
        $selectedCategoryId = $request->integer('category_id') ?: (int) $request->session()->get($categorySessionKey, 0);
        $selectedCategory = $categories->firstWhere('id', $selectedCategoryId) ?? $categories->first();

        if ($selectedCategory !== null) {
            $request->session()->put($categorySessionKey, $selectedCategory->id);
        } else {
            $request->session()->forget($categorySessionKey);
        }

        if ($selectedCategory !== null) {
            $selectedCategory->load(['products' => function ($query) use ($branch): void {
                $query->orderBy('sort_order');
                if ($branch->uses_shared_menu) {
                    $query->with(['branches' => fn ($branches) => $branches->whereKey($branch->id)]);
                }
                if (method_exists(MenuProduct::class, 'optionGroups')) {
                    $query->with('optionGroups.options');
                }
            }]);
        }

        $suggestionPool = MenuProduct::query()
            ->where('cafe_id', $branch->cafe_id)
            ->where('branch_id', $menuBranchId)
            ->orderBy('name')
            ->get(['id', 'name', 'category_id', 'suggestions', 'is_available', 'is_featured']);
        $activeCategoryIds = $categories->where('is_active', true)->pluck('id');
        $recommendationProducts = $suggestionPool
            ->where('is_available', true)
            ->whereIn('category_id', $activeCategoryIds)
            ->values();
        $categoryRecommendationMap = [];
        foreach ($recommendationProducts as $recommendedProduct) {
            foreach ($recommendedProduct->suggestedCategoryIds() as $triggerCategoryId) {
                $categoryRecommendationMap[$triggerCategoryId][] = $recommendedProduct->id;
            }
        }
        $availableProductCount = $suggestionPool->where('is_available', true)->count();
        $menuSources = Branch::query()->where('cafe_id', $branch->cafe_id)
            ->where('id', '!=', $branch->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'uses_shared_menu']);
        $hasPrivateMenu = MenuCategory::query()->where('cafe_id', $branch->cafe_id)->where('branch_id', $branch->id)->exists();

        return view('manage.branch', compact('branch', 'categories', 'selectedCategory', 'suggestionPool', 'recommendationProducts', 'categoryRecommendationMap', 'availableProductCount', 'menuSources', 'hasPrivateMenu'));
    }

    public function store(Request $request, SubscriptionService $subscriptions): RedirectResponse
    {
        $user = $request->user();
        Gate::authorize('create', Branch::class);
        $subscriptions->requireCapacity($user->cafe, 'branches');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);
        $slug = Str::slug($validated['name']);
        $suffix = 2;
        while ($user->cafe->branches()->where('slug', $slug)->exists()) {
            $slug = Str::slug($validated['name']).'-'.$suffix++;
        }

        $branch = $user->cafe->branches()->create([
            'name' => $validated['name'],
            'slug' => $slug,
            'address' => $validated['address'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'uses_shared_menu' => true,
            'is_active' => true,
        ]);

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Şube oluşturuldu.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        DB::transaction(function () use ($branch, $validated, $request): void {
            $branch->fill([
                'name' => $validated['name'],
                'address' => array_key_exists('address', $validated) ? $validated['address'] : $branch->address,
                'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $branch->phone,
                'is_active' => array_key_exists('is_active', $validated) ? $request->boolean('is_active') : $branch->is_active,
            ])->save();

            ActivityLog::query()->create([
                'cafe_id' => $branch->cafe_id,
                'branch_id' => $branch->id,
                'user_id' => $request->user()->id,
                'action' => 'branch.updated',
                'subject_type' => Branch::class,
                'subject_id' => $branch->id,
                'metadata' => ['shared_menu' => $branch->uses_shared_menu, 'active' => $branch->is_active],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Şube bilgileri güncellendi.');
    }

    public function copyMenu(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $validated = $request->validate([
            'action' => ['required', 'in:shared,copy,empty'],
            'source' => ['exclude_unless:action,copy', 'required', 'in:shared,branch'],
            'source_branch_id' => ['exclude_unless:source,branch', 'required', 'integer', 'exists:branches,id'],
            'confirm_replace' => ['sometimes', 'boolean'],
        ]);
        $sourceBranch = null;
        if (($validated['source'] ?? null) === 'branch') {
            $sourceBranch = Branch::query()->find($validated['source_branch_id'] ?? null);
            if ($sourceBranch === null || $sourceBranch->cafe_id !== $branch->cafe_id || $sourceBranch->id === $branch->id || ! $sourceBranch->is_active) {
                throw ValidationException::withMessages(['source_branch_id' => 'Kaynak şube bu kafeye ait aktif ve farklı bir şube olmalıdır.']);
            }
        }

        $hasPrivateMenu = MenuCategory::query()->where('cafe_id', $branch->cafe_id)->where('branch_id', $branch->id)->exists();
        if ($validated['action'] !== 'shared' && $hasPrivateMenu && ! $request->boolean('confirm_replace')) {
            throw ValidationException::withMessages(['confirm_replace' => 'Bu işlem mevcut şubeye özel menüyü değiştirir. Devam etmek için onaylayın.']);
        }

        DB::transaction(function () use ($branch, $sourceBranch, $validated, $request): void {
            $lockedBranch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if ($validated['action'] === 'shared') {
                $lockedBranch->forceFill(['uses_shared_menu' => true])->save();
            } else {
                if (($validated['source'] ?? null) === 'branch' && $sourceBranch !== null) {
                    $sourceBranch = Branch::query()->whereKey($sourceBranch->id)->lockForUpdate()->firstOrFail();
                    if ($sourceBranch->cafe_id !== $lockedBranch->cafe_id || $sourceBranch->id === $lockedBranch->id || ! $sourceBranch->is_active) {
                        throw ValidationException::withMessages(['source_branch_id' => 'Kaynak şube artık kullanılamıyor. Sayfayı yenileyip tekrar deneyin.']);
                    }
                }

                $existingCategories = MenuCategory::query()->where('cafe_id', $lockedBranch->cafe_id)
                    ->where('branch_id', $lockedBranch->id)->exists();
                if ($existingCategories) {
                    if (! $request->boolean('confirm_replace')) {
                        throw ValidationException::withMessages(['confirm_replace' => 'Bu şubenin özel menüsü değişmiş olabilir. Sayfayı yenileyip tekrar onaylayın.']);
                    }

                    MenuProduct::query()->where('cafe_id', $lockedBranch->cafe_id)->where('branch_id', $lockedBranch->id)->delete();
                    MenuCategory::query()->where('cafe_id', $lockedBranch->cafe_id)->where('branch_id', $lockedBranch->id)->delete();
                }

                $lockedBranch->forceFill(['uses_shared_menu' => false])->save();
                if ($validated['action'] === 'copy') {
                    $this->copyMenuFrom($lockedBranch, $sourceBranch);
                }
            }

            ActivityLog::query()->create([
                'cafe_id' => $lockedBranch->cafe_id,
                'branch_id' => $lockedBranch->id,
                'user_id' => $request->user()->id,
                'action' => 'branch.menu_changed',
                'subject_type' => Branch::class,
                'subject_id' => $lockedBranch->id,
                'metadata' => [
                    'action' => $validated['action'],
                    'source' => $validated['source'] ?? null,
                    'source_branch_id' => $sourceBranch?->id,
                    'shared_menu' => $lockedBranch->uses_shared_menu,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        $message = match ($validated['action']) {
            'shared' => 'Ortak menü yeniden etkinleştirildi. Şubeye özel menünüz saklandı.',
            'empty' => 'Boş ve şubeye özel menü oluşturuldu.',
            default => 'Seçilen menünün kopyası oluşturuldu. Değişiklikler yalnızca bu şubeyi etkiler.',
        };

        return redirect()->route('manage.branches.show', $branch)->with('status', $message);
    }

    private function copyMenuFrom(Branch $target, ?Branch $sourceBranch): void
    {
        $sourceMenuBranchId = $sourceBranch?->uses_shared_menu ? null : $sourceBranch?->id;
        $pricingBranch = $sourceBranch ?? $target;
        $productRelations = ['branches' => fn ($query) => $query->whereKey($pricingBranch->id)];
        if (method_exists(MenuProduct::class, 'optionGroups')) {
            $productRelations['optionGroups.options'] = fn ($query) => $query->orderBy('sort_order');
        }

        $categories = MenuCategory::query()->where('cafe_id', $target->cafe_id)->where('branch_id', $sourceMenuBranchId)
            ->with(['products' => fn ($query) => $query->orderBy('sort_order')->with($productRelations)])
            ->orderBy('sort_order')->orderBy('name')->get();

        /** @var array<int, int> $productIdMap */
        $productIdMap = [];
        /** @var array<int, int> $categoryIdMap */
        $categoryIdMap = [];
        /** @var array<int, array<int, int|string>> $pendingSuggestions */
        $pendingSuggestions = [];

        foreach ($categories as $category) {
            $categoryCopy = MenuCategory::query()->create([
                'cafe_id' => $target->cafe_id,
                'branch_id' => $target->id,
                'name' => $category->name,
                'description' => $category->description,
                'sort_order' => $category->sort_order,
                'is_active' => $category->is_active,
            ]);
            $categoryIdMap[$category->id] = $categoryCopy->id;

            foreach ($category->products as $product) {
                $productCopy = $categoryCopy->products()->create([
                    'cafe_id' => $target->cafe_id,
                    'branch_id' => $target->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'image_path' => $product->image_path,
                    'price' => $product->priceFor($pricingBranch),
                    'prep_minutes' => $product->prep_minutes,
                    'is_available' => $product->isAvailableAt($pricingBranch),
                    'is_featured' => $product->is_featured,
                    'sort_order' => $product->sort_order,
                ]);

                $productIdMap[$product->id] = $productCopy->id;
                $pendingSuggestions[$productCopy->id] = $product->suggestions ?? [];

                if (method_exists($product, 'optionGroups') && method_exists($productCopy, 'optionGroups')) {
                    foreach ($product->optionGroups as $group) {
                        $groupCopy = $productCopy->optionGroups()->create([
                            'name' => $group->name,
                            'selection_mode' => $group->selection_mode,
                            'min_selections' => $group->min_selections,
                            'max_selections' => $group->max_selections,
                            'sort_order' => $group->sort_order,
                            'is_active' => $group->is_active,
                        ]);
                        $groupCopy->options()->createMany($group->options->map(fn ($option): array => [
                            'name' => $option->name,
                            'price_delta' => $option->price_delta,
                            'is_available' => $option->is_available,
                            'sort_order' => $option->sort_order,
                        ])->all());
                    }
                }
            }
        }

        foreach ($pendingSuggestions as $copyId => $sourceValues) {
            $mapped = collect($sourceValues)
                ->map(function ($value) use ($productIdMap, $categoryIdMap): int|string|null {
                    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                        return $productIdMap[(int) $value] ?? null;
                    }

                    if (is_string($value) && preg_match('/^category:(\\d+)$/', $value, $matches) === 1) {
                        $categoryId = $categoryIdMap[(int) $matches[1]] ?? null;

                        return $categoryId !== null ? 'category:'.$categoryId : null;
                    }

                    return null;
                })
                ->filter(fn (int|string|null $id): bool => $id !== null)
                ->values()
                ->all();

            $copy = MenuProduct::query()->find($copyId);
            $copy?->forceFill(['suggestions' => $mapped])->save();
        }
    }
}
