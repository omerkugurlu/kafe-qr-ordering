<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyCampaign;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Services\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class AccountController extends Controller
{
    public function show(Request $request, string $token, LoyaltyService $loyalty): Response|RedirectResponse
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

        $cafe = $table->branch->cafe;
        $customer = $request->user();

        if ($customer?->role !== User::ROLE_CUSTOMER || ! $customer->is_active) {
            return redirect()
                ->route('customer.login', $token)
                ->with('status', __('customer.account_login_required'));
        }

        abort_if($cafe->registration_policy === Cafe::POLICY_GUEST, 404);

        $account = LoyaltyAccount::query()
            ->where('cafe_id', $cafe->id)
            ->where('user_id', $customer->id)
            ->first();
        $availableCampaignIds = $cafe->loyalty_enabled
            ? $loyalty->availableCampaigns($cafe, $customer)->modelKeys()
            : [];
        $campaignOrderDeltas = LoyaltyTransaction::query()
            ->where('cafe_id', $cafe->id)
            ->where('user_id', $customer->id)
            ->whereNotNull('campaign_id')
            ->selectRaw('campaign_id, SUM(orders_delta) as orders_delta')
            ->groupBy('campaign_id')
            ->pluck('orders_delta', 'campaign_id');

        $campaigns = $cafe->loyalty_enabled
            ? $cafe->loyaltyCampaigns()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->filter(fn (LoyaltyCampaign $campaign): bool => $campaign->isCurrentlyActive())
                ->map(function (LoyaltyCampaign $campaign) use ($account, $availableCampaignIds, $campaignOrderDeltas): array {
                    $required = $campaign->type === LoyaltyCampaign::TYPE_POINTS
                        ? $campaign->requiredPoints()
                        : $campaign->requiredOrderCount();
                    $current = $campaign->type === LoyaltyCampaign::TYPE_POINTS
                        ? (int) ($account?->points ?? 0)
                        : max(0, (int) ($account?->completed_order_count ?? 0) + (int) $campaignOrderDeltas->get($campaign->id, 0));

                    return [
                        'campaign' => $campaign,
                        'current' => $current,
                        'required' => $required,
                        'progress' => min(100, (int) round($current / max(1, $required) * 100)),
                        'available' => in_array($campaign->id, $availableCampaignIds, true),
                        'reward' => $campaign->reward_type === LoyaltyCampaign::REWARD_PERCENT
                            ? '%'.rtrim(rtrim(number_format((float) $campaign->reward_value, 2, ',', ''), '0'), ',')
                            : Money::format(Money::toCents((string) $campaign->reward_value)),
                    ];
                })
                ->values()
            : collect();

        $paidOrders = Order::query()
            ->where('cafe_id', $cafe->id)
            ->where('customer_id', $customer->id)
            ->where('status', Order::STATUS_PAID);
        $savingsCents = Money::toCents((string) (clone $paidOrders)->sum('discount_total'));
        $paidOrderCount = (clone $paidOrders)->count();
        $lifetimePoints = (int) LoyaltyTransaction::query()
            ->where('cafe_id', $cafe->id)
            ->where('user_id', $customer->id)
            ->where('type', 'earned')
            ->sum('points_delta');
        $recentOrders = Order::query()
            ->where('cafe_id', $cafe->id)
            ->where('customer_id', $customer->id)
            ->with(['branch', 'tableSession.diningTable'])
            ->latest('created_at')
            ->take(8)
            ->get();
        $announcements = $cafe->announcements()
            ->active()
            ->forBranch($table->branch_id)
            ->latest('created_at')
            ->take(6)
            ->get();

        return response()->view('customer.account', [
            'cafe' => $cafe,
            'branch' => $table->branch,
            'table' => $table,
            'token' => $token,
            'customer' => $customer,
            'account' => $account,
            'campaigns' => $campaigns,
            'paidOrderCount' => $paidOrderCount,
            'savingsCents' => $savingsCents,
            'lifetimePoints' => $lifetimePoints,
            'recentOrders' => $recentOrders,
            'announcements' => $announcements,
        ])->header('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
    }
}
