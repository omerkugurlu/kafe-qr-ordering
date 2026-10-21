<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyCampaign;
use App\Models\MenuProduct;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Money;
use App\Services\OrderPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MenuController extends Controller
{
    public function show(Request $request, string $token, OrderPresenter $presenter): View|RedirectResponse
    {
        $table = DiningTable::query()
            ->where('public_token_hash', hash('sha256', $token))
            ->with('branch.cafe')
            ->firstOrFail();

        abort_unless(
            $table->is_active
            && $table->branch?->is_active
            && $table->branch?->cafe?->status === Cafe::STATUS_ACTIVE,
            404,
        );

        $branch = $table->branch;
        $cafe = $branch->cafe;
        $branchMenuId = $branch->uses_shared_menu ? null : $branch->id;
        $customer = $request->user()?->role === User::ROLE_CUSTOMER && $request->user()->is_active
            ? $request->user()
            : null;

        if ($cafe->registration_policy === Cafe::POLICY_REQUIRED && $customer === null) {
            return redirect()
                ->route('customer.login', $token)
                ->with('status', __('customer.login_required_title'));
        }

        $categories = $cafe->categories()
            ->where('branch_id', $branchMenuId)
            ->where('is_active', true)
            ->with([
                'products' => fn ($query) => $query
                    ->where('branch_id', $branchMenuId)
                    ->with([
                        'branches' => fn ($branches) => $branches->whereKey($branch->id),
                        'optionGroups' => fn ($groups) => $groups
                            ->where('is_active', true)
                            ->with(['options' => fn ($options) => $options->where('is_available', true)->orderBy('sort_order')])
                            ->orderBy('sort_order'),
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $categories->each(function ($category) use ($branch): void {
            $category->setRelation(
                'products',
                $category->products
                    ->filter(fn (MenuProduct $product): bool => $product->isAvailableAt($branch))
                    ->values(),
            );
        });

        $allProducts = $categories->flatMap(fn ($category) => $category->products)->values();
        $productsById = $allProducts->keyBy('id');
        $categoryNames = $categories->pluck('name', 'id');
        $featuredIds = $allProducts->where('is_featured', true)->pluck('id')->take(8);

        if ($featuredIds->isEmpty()) {
            $featuredIds = $allProducts->take(6)->pluck('id');
        }

        $productData = $allProducts->map(function (MenuProduct $product) use ($branch, $productsById, $categoryNames): array {
            $priceCents = Money::toCents($product->priceFor($branch));

            return [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'price_cents' => $priceCents,
                'price_label' => Money::format($priceCents),
                'image_url' => $product->image_url,
                'category_id' => $product->category_id,
                'category_name' => $categoryNames[$product->category_id] ?? null,
                'is_available' => $product->isAvailableAt($branch),
                'is_featured' => $product->is_featured,
                'prep_minutes' => $product->prep_minutes,
                'option_groups' => $product->optionGroups->map(fn ($group): array => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'selection_mode' => $group->selection_mode,
                    'min_selections' => $group->min_selections,
                    'max_selections' => $group->max_selections,
                    'options' => $group->options->map(fn ($option): array => [
                        'id' => $option->id,
                        'name' => $option->name,
                        'price_delta_cents' => Money::toCents($option->price_delta),
                        'price_delta_label' => '+'.Money::format(Money::toCents($option->price_delta)),
                    ])->values()->all(),
                ])->values()->all(),
                'suggestions' => collect($product->suggestions ?? [])
                    ->map(fn ($id): int => (int) $id)
                    ->filter(fn (int $id): bool => $id !== $product->id && $productsById->has($id))
                    ->unique()
                    ->values()
                    ->all(),
            ];
        })->values();

        $categoryData = $categories->map(fn ($category): array => [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'image_url' => $category->image_url,
            'product_ids' => $category->products->pluck('id')->values()->all(),
        ])->values();

        $session = TableSession::query()
            ->where('dining_table_id', $table->id)
            ->where('status', TableSession::STATUS_OPEN)
            ->latest('id')
            ->first();

        $sessionPayload = $session !== null ? $presenter->session($session) : null;

        $loyalty = null;

        if ($customer !== null && $cafe->loyalty_enabled) {
            $account = LoyaltyAccount::query()
                ->where('cafe_id', $cafe->id)
                ->where('user_id', $customer->id)
                ->first();

            $campaigns = $cafe->loyaltyCampaigns()
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
                ->filter(fn (LoyaltyCampaign $campaign): bool => $campaign->isCurrentlyActive())
                ->map(fn (LoyaltyCampaign $campaign): array => [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'type' => $campaign->type,
                    'required' => $campaign->type === LoyaltyCampaign::TYPE_POINTS
                        ? $campaign->requiredPoints()
                        : $campaign->requiredOrderCount(),
                    'reward_type' => $campaign->reward_type,
                    'reward_value_cents' => Money::toCents($campaign->reward_value),
                    'minimum_order_cents' => Money::toCents($campaign->minimum_order_total),
                    'reward_label' => $campaign->reward_type === LoyaltyCampaign::REWARD_PERCENT
                        ? '%'.rtrim(rtrim(number_format((float) $campaign->reward_value, 2, ',', ''), '0'), ',')
                        : Money::format(Money::toCents($campaign->reward_value)),
                ])
                ->values()
                ->all();

            if ($campaigns !== []) {
                $loyalty = [
                    'points' => (int) ($account?->points ?? 0),
                    'completed_orders' => (int) ($account?->completed_order_count ?? 0),
                    'campaigns' => $campaigns,
                ];
            }
        }

        $announcements = $cafe->announcements()
            ->active()
            ->forBranch($branch->id)
            ->orderByDesc('created_at')
            ->take(6)
            ->get();

        $loyaltyTeaser = $customer === null
            && $cafe->registration_policy === Cafe::POLICY_OPTIONAL
            && $cafe->loyalty_enabled;

        $menuData = [
            'locale' => app()->getLocale(),
            'table' => [
                'name' => $table->name,
                'token' => $token,
            ],
            'cafe' => [
                'name' => $cafe->name,
                'brand_name' => $cafe->brand_name,
                'description' => $cafe->description,
                'logo_url' => $cafe->logo_url,
                'cover_url' => $cafe->cover_url,
                'address' => $cafe->address,
                'phone' => $cafe->phone,
                'instagram' => $cafe->instagram,
            ],
            'branch' => [
                'name' => $branch->name,
                'address' => $branch->address,
            ],
            'customer' => $customer !== null ? ['name' => $customer->name] : null,
            'categories' => $categoryData,
            'products' => $productData,
            'featured_ids' => $featuredIds->values()->all(),
            'session' => $sessionPayload,
            'loyalty' => $loyalty,
            'urls' => [
                'order' => route('customer.orders.store', $token),
                'coupon' => route('customer.coupon.check', $token),
                'status' => route('customer.status', $token),
                'bill' => route('customer.bill', $token),
                'call' => route('customer.call', $token),
                'menu' => route('customer.menu', $token),
                'login' => $cafe->registration_policy !== Cafe::POLICY_GUEST
                    ? route('customer.login', $token)
                    : null,
                'register' => $cafe->registration_policy !== Cafe::POLICY_GUEST
                    ? route('customer.register', $token)
                    : null,
                'logout' => route('logout'),
            ],
            'strings' => trans('customer'),
        ];

        return view('customer.menu', [
            'table' => $table,
            'branch' => $branch,
            'cafe' => $cafe,
            'token' => $token,
            'categories' => $categories,
            'session' => $sessionPayload,
            'menuData' => $menuData,
            'customer' => $customer,
            'announcements' => $announcements,
            'loyaltyTeaser' => $loyaltyTeaser,
            'orderCount' => $session !== null
                ? $session->orders()->whereNotIn('status', [Order::STATUS_CANCELLED])->count()
                : 0,
        ]);
    }
}
