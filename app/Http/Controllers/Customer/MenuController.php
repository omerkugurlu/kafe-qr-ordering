<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyCampaign;
use App\Models\MenuProduct;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Money;
use App\Services\LoyaltyService;
use App\Services\OrderPresenter;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuController extends Controller
{
    public function show(Request $request, string $token, OrderPresenter $presenter, SubscriptionService $subscriptions, LoyaltyService $loyaltyService): Response|RedirectResponse
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
        $customerAccountsEnabled = $subscriptions->featureEnabled($cafe, 'customer_accounts');
        $customer = $customerAccountsEnabled && $request->user()?->role === User::ROLE_CUSTOMER && $request->user()->is_active
            ? $request->user()
            : null;

        if ($customerAccountsEnabled && $cafe->registration_policy === Cafe::POLICY_REQUIRED && $customer === null) {
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
        $productsSuggestedByCategory = [];
        foreach ($allProducts as $recommendedProduct) {
            foreach ($recommendedProduct->suggestedCategoryIds() as $categoryId) {
                $productsSuggestedByCategory[$categoryId][] = $recommendedProduct->id;
            }
        }
        $featuredIds = $allProducts->where('is_featured', true)->pluck('id')->take(8);

        if ($featuredIds->isEmpty()) {
            $featuredIds = $allProducts->take(6)->pluck('id');
        }

        $productData = $allProducts->map(function (MenuProduct $product) use ($branch, $productsById, $categoryNames, $productsSuggestedByCategory): array {
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
                'suggestions' => $product->suggestedProductIds()
                    ->filter(fn (int $id): bool => $productsById->has($id))
                    ->values()->all(),
                'category_suggestions' => collect($productsSuggestedByCategory[$product->category_id] ?? [])
                    ->filter(fn (int $id): bool => $id !== $product->id)
                    ->unique()->take(6)->values()->all(),
            ];
        })->values();

        $categoryData = $categories->map(fn ($category): array => [
            'id' => $category->id,
            'name' => $category->name,
            'description' => $category->description,
            'image_url' => $category->image_url,
            'product_ids' => $category->products->pluck('id')->values()->all(),
        ])->values();

        $session = $this->startOrFindQrSession($table, $request);
        $qrVisit = $request->session()->get('qr_visits.'.$table->id);

        $sessionPayload = $session !== null ? $presenter->session($session) : null;

        $loyalty = null;

        if ($customer !== null && $cafe->loyalty_enabled && $subscriptions->featureEnabled($cafe, 'loyalty')) {
            $account = LoyaltyAccount::query()
                ->where('cafe_id', $cafe->id)
                ->where('user_id', $customer->id)
                ->first();

            $availableCampaignIds = $loyaltyService->availableCampaigns($cafe, $customer)->modelKeys();
            $campaigns = $cafe->loyaltyCampaigns()
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
                ->filter(function (LoyaltyCampaign $campaign) use ($allProducts, $availableCampaignIds): bool {
                    if (!$campaign->isCurrentlyActive()) {
                        return false;
                    }

                    return $campaign->reward_type !== LoyaltyCampaign::REWARD_PRODUCT
                        || $allProducts->whereIn('id', $campaign->rewardProductIds())
                            ->filter(fn (MenuProduct $product): bool => ! $product->optionGroups->contains(fn ($group): bool => $group->is_active && $group->min_selections > 0))
                            ->isNotEmpty();
                })
                ->map(function (LoyaltyCampaign $campaign) use ($allProducts, $availableCampaignIds): array {
                    $rewardProducts = $campaign->reward_type === LoyaltyCampaign::REWARD_PRODUCT
                        ? $allProducts->whereIn('id', $campaign->rewardProductIds())
                            ->filter(fn (MenuProduct $product): bool => ! $product->optionGroups->contains(fn ($group): bool => $group->is_active && $group->min_selections > 0))
                            ->values()
                        : collect();

                    return [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'type' => $campaign->type,
                    'required' => $campaign->type === LoyaltyCampaign::TYPE_POINTS
                        ? $campaign->requiredPoints()
                        : $campaign->requiredOrderCount(),
                    'reward_type' => $campaign->reward_type,
                    'available' => in_array((int) $campaign->id, array_map('intval', $availableCampaignIds), true),
                    'reward_value_cents' => Money::toCents($campaign->reward_value),
                    'minimum_order_cents' => Money::toCents($campaign->minimum_order_total),
                    'reward_label' => $campaign->reward_type === LoyaltyCampaign::REWARD_PRODUCT
                        ? 'Ücretsiz ürün seçimi'
                        : ($campaign->reward_type === LoyaltyCampaign::REWARD_PERCENT
                            ? '%'.rtrim(rtrim(number_format((float) $campaign->reward_value, 2, ',', ''), '0'), ',')
                            : Money::format(Money::toCents($campaign->reward_value))),
                    'reward_products' => $rewardProducts->map(fn (MenuProduct $product): array => [
                        'id' => $product->id,
                        'name' => $product->name,
                        'image_url' => $product->image_url,
                        'price_cents' => Money::toCents($product->priceFor($branch)),
                    ])->all(),
                    ];
                })
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

        $announcements = $subscriptions->featureEnabled($cafe, 'announcements') ? $cafe->announcements()
            ->active()
            ->forBranch($branch->id)
            ->orderByDesc('created_at')
            ->take(6)
            ->get() : collect();

        $loyaltyTeaser = $customer === null
            && $cafe->registration_policy === Cafe::POLICY_OPTIONAL
            && $cafe->loyalty_enabled
            && $subscriptions->featureEnabled($cafe, 'loyalty');

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
            'qr_visit_id' => $session !== null && is_array($qrVisit) ? ($qrVisit['id'] ?? null) : null,
            'ordering_available' => $subscriptions->serviceEnabled($cafe)
                && $session?->status === TableSession::STATUS_OPEN
                && $session->qr_ordering_enabled
                && is_array($qrVisit)
                && (int) ($qrVisit['session_id'] ?? 0) === (int) $session->id
                && ! ($sessionPayload['qr_order_pending'] ?? false),
            'loyalty' => $loyalty,
            'urls' => [
                'order' => route('customer.orders.store', $token),
                'coupon' => route('customer.coupon.check', $token),
                'status' => route('customer.status', $token),
                'bill' => route('customer.bill', $token),
                'call' => route('customer.call', $token),
                'menu' => route('customer.menu', $token),
                'login' => $customerAccountsEnabled && $cafe->registration_policy !== Cafe::POLICY_GUEST
                    ? route('customer.login', $token)
                    : null,
                'register' => $customerAccountsEnabled && $cafe->registration_policy !== Cafe::POLICY_GUEST
                    ? route('customer.register', $token)
                    : null,
                'logout' => route('logout'),
            ],
            'strings' => trans('customer'),
        ];

        return response()->view('customer.menu', [
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
        ])->header('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
    }

    private function startOrFindQrSession(DiningTable $table, Request $request): ?TableSession
    {
        $visitKey = 'qr_visits.'.$table->id;
        $qrVisit = $request->session()->get($visitKey);
        $hasValidVisit = is_array($qrVisit)
            && (int) ($qrVisit['session_id'] ?? 0) > 0
            && Str::isUuid((string) ($qrVisit['id'] ?? ''));

        $session = DB::transaction(function () use ($table, $request, $hasValidVisit, $qrVisit): ?TableSession {
            $lockedTable = DiningTable::query()->whereKey($table->id)->lockForUpdate()->firstOrFail();
            $branch = $lockedTable->branch()->lockForUpdate()->firstOrFail();
            $cafe = $branch->cafe()->lockForUpdate()->firstOrFail();

            abort_unless(
                $lockedTable->is_active && $branch->is_active && $cafe->status === Cafe::STATUS_ACTIVE,
                404,
            );

            $session = TableSession::query()
                ->where('dining_table_id', $lockedTable->id)
                ->where('status', TableSession::STATUS_OPEN)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($session !== null) {
                if ($hasValidVisit && (int) $qrVisit['session_id'] !== (int) $session->id) {
                    return null;
                }

                return $session;
            }

            $session = TableSession::query()->create([
                'branch_id' => $branch->id,
                'dining_table_id' => $lockedTable->id,
                'status' => TableSession::STATUS_OPEN,
                'qr_ordering_enabled' => true,
                'opened_by' => null,
                'opened_at' => now(),
            ]);

            ActivityLog::query()->create([
                'cafe_id' => $cafe->id,
                'branch_id' => $branch->id,
                'user_id' => null,
                'action' => 'table_session.opened_from_qr',
                'subject_type' => TableSession::class,
                'subject_id' => $session->id,
                'metadata' => ['table_id' => $lockedTable->id],
                'ip_address' => $request->ip(),
            ]);

            return $session;
        });

        if ($session === null) {
            return null;
        }

        $qrVisit = $request->session()->get($visitKey);

        if (
            ! is_array($qrVisit)
            || (int) ($qrVisit['session_id'] ?? 0) !== (int) $session->id
            || ! Str::isUuid((string) ($qrVisit['id'] ?? ''))
        ) {
            // A new QR seating gets a fresh visit ID for this browser.
            // Requests carrying the previous session ID or visit ID remain invalid.
            $qrVisit = ['session_id' => $session->id, 'id' => (string) Str::uuid()];
            $request->session()->put($visitKey, $qrVisit);
        }

        return $session;
    }
}
