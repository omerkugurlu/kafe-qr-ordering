<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\LoyaltyAccount;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MenuController extends Controller
{
    public function show(Request $request, string $token, LoyaltyService $loyalty): View
    {
        $table = DiningTable::query()->where('public_token_hash', hash('sha256', $token))->firstOrFail();
        abort_unless($table->is_active && $table->branch?->is_active && $table->branch?->cafe?->status === 'active', 404);
        $branch = $table->branch;
        $branchId = $branch->uses_shared_menu ? null : $branch->id;
        $categories = $branch->cafe->categories()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->with(['products' => fn ($query) => $query->where('branch_id', $branchId)->with(['branches' => fn ($branches) => $branches->whereKey($branch->id)])->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get();
        $categories->each(fn ($category) => $category->setRelation(
            'products',
            $category->products->filter(fn ($product) => $product->isAvailableAt($branch))->values(),
        ));

        $customer = $request->user();
        $campaigns = $customer?->role === 'customer' && $customer->cafe_id === $branch->cafe_id
            ? $loyalty->availableCampaigns($branch->cafe, $customer)
            : collect();
        $loyaltyAccount = $customer?->role === 'customer' && $customer->cafe_id === $branch->cafe_id
            ? LoyaltyAccount::query()->where('cafe_id', $branch->cafe_id)->where('user_id', $customer->id)->first()
            : null;

        return view('customer.menu', compact('table', 'branch', 'categories', 'campaigns', 'loyaltyAccount'));
    }
}
