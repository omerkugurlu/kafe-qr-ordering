<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Services\LoyaltyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->with([
                'products' => fn ($query) => $query->where('branch_id', $branchId)
                    ->with([
                        'branches' => fn ($branches) => $branches->whereKey($branch->id),
                        'optionGroups' => fn ($groups) => $groups->where('is_active', true)->with(['options' => fn ($options) => $options->where('is_available', true)]),
                    ])
                    ->orderBy('sort_order'),
            ])
            ->orderBy('sort_order')
            ->get();
        $categories->each(fn ($category) => $category->setRelation(
            'products',
            $category->products->filter(fn ($product) => $product->isAvailableAt($branch))->values(),
        ));
        $productsById = $categories->flatMap(fn ($category) => $category->products)->keyBy('id');
        $popularIds = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.branch_id', $branch->id)
            ->whereIn('orders.status', [Order::STATUS_SERVED, Order::STATUS_PAID])
            ->whereIn('order_items.menu_product_id', $productsById->keys())
            ->select('order_items.menu_product_id')
            ->selectRaw('SUM(order_items.quantity) as units_sold')
            ->groupBy('order_items.menu_product_id')
            ->orderByDesc('units_sold')
            ->limit(4)
            ->pluck('menu_product_id');
        $popularProducts = $popularIds->map(fn ($id) => $productsById->get($id))->filter()->values();

        $customer = $request->user();
        $campaigns = $branch->cafe->loyalty_enabled
            ? $branch->cafe->loyaltyCampaigns()->where('is_active', true)->orderBy('created_at')->get()->filter->isCurrentlyActive()->values()
            : collect();
        $redeemableCampaigns = $customer?->is_active && $customer->role === 'customer' && $customer->cafe_id === $branch->cafe_id
            ? $loyalty->availableCampaigns($branch->cafe, $customer)
            : collect();
        $loyaltyAccount = $customer?->is_active && $customer->role === 'customer' && $customer->cafe_id === $branch->cafe_id
            ? LoyaltyAccount::query()->where('cafe_id', $branch->cafe_id)->where('user_id', $customer->id)->first()
            : null;

        return view('customer.menu', compact('table', 'branch', 'categories', 'campaigns', 'redeemableCampaigns', 'loyaltyAccount', 'popularProducts'));
    }
}
