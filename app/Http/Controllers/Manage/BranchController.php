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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function show(Branch $branch): View
    {
        Gate::authorize('view', $branch);
        $branch->loadCount('diningTables');
        $categories = MenuCategory::query()
            ->where('cafe_id', $branch->cafe_id)
            ->where('branch_id', $branch->uses_shared_menu ? null : $branch->id)
            ->with(['products' => function ($query): void {
                $query->orderBy('sort_order');
                if (method_exists(MenuProduct::class, 'optionGroups')) {
                    $query->with('optionGroups.options');
                }
            }])
            ->withCount('products')->orderBy('sort_order')->orderBy('name')->get();
        $menuSources = Branch::query()->where('cafe_id', $branch->cafe_id)
            ->where('id', '!=', $branch->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'uses_shared_menu']);
        $hasPrivateMenu = MenuCategory::query()->where('cafe_id', $branch->cafe_id)->where('branch_id', $branch->id)->exists();

        return view('manage.branch', compact('branch', 'categories', 'menuSources', 'hasPrivateMenu'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        Gate::authorize('create', Branch::class);
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

        foreach ($categories as $category) {
            $categoryCopy = MenuCategory::query()->create([
                'cafe_id' => $target->cafe_id,
                'branch_id' => $target->id,
                'name' => $category->name,
                'description' => $category->description,
                'sort_order' => $category->sort_order,
                'is_active' => $category->is_active,
            ]);

            foreach ($category->products as $product) {
                $productCopy = $categoryCopy->products()->create([
                    'cafe_id' => $target->cafe_id,
                    'branch_id' => $target->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => $product->priceFor($pricingBranch),
                    'is_available' => $product->isAvailableAt($pricingBranch),
                    'sort_order' => $product->sort_order,
                ]);

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
    }
}
