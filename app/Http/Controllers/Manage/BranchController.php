<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\MenuCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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
            ->with(['products' => fn ($query) => $query->orderBy('sort_order')])
            ->withCount('products')->orderBy('sort_order')->orderBy('name')->get();

        return view('manage.branch', compact('branch', 'categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        Gate::authorize('create', Branch::class);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'uses_shared_menu' => ['sometimes', 'boolean'],
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
            'uses_shared_menu' => $request->boolean('uses_shared_menu', true),
            'is_active' => true,
        ]);

        if (! $branch->uses_shared_menu) {
            $this->copySharedMenuToBranch($branch);
        }

        return redirect()->route('manage.branches.show', $branch)->with('status', 'Şube oluşturuldu.');
    }

    public function update(Request $request, Branch $branch): RedirectResponse
    {
        Gate::authorize('update', $branch);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'uses_shared_menu' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $shouldUseShared = array_key_exists('uses_shared_menu', $validated)
            ? $request->boolean('uses_shared_menu')
            : $branch->uses_shared_menu;

        DB::transaction(function () use ($branch, $validated, $request, $shouldUseShared): void {
            $branch->fill([
                'name' => $validated['name'],
                'address' => array_key_exists('address', $validated) ? $validated['address'] : $branch->address,
                'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $branch->phone,
                'uses_shared_menu' => $shouldUseShared,
                'is_active' => array_key_exists('is_active', $validated) ? $request->boolean('is_active') : $branch->is_active,
            ])->save();

            if (! $shouldUseShared && MenuCategory::query()->where('branch_id', $branch->id)->doesntExist()) {
                $this->copySharedMenuToBranch($branch);
            }

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

    private function copySharedMenuToBranch(Branch $branch): void
    {
        $categories = MenuCategory::query()->where('cafe_id', $branch->cafe_id)->whereNull('branch_id')->with(['products.branches' => fn ($query) => $query->whereKey($branch->id)])->get();

        foreach ($categories as $category) {
            $copy = MenuCategory::query()->create([
                'cafe_id' => $branch->cafe_id,
                'branch_id' => $branch->id,
                'name' => $category->name,
                'description' => $category->description,
                'sort_order' => $category->sort_order,
                'is_active' => $category->is_active,
            ]);

            foreach ($category->products as $product) {
                $branchOverride = $product->branches->firstWhere('id', $branch->id)?->pivot;
                $copy->products()->create([
                    'cafe_id' => $branch->cafe_id,
                    'branch_id' => $branch->id,
                    'name' => $product->name,
                    'description' => $product->description,
                    'price' => $branchOverride?->price ?? $product->price,
                    'is_available' => $branchOverride?->is_available ?? $product->is_available,
                    'sort_order' => $product->sort_order,
                ]);
            }
        }
    }
}
