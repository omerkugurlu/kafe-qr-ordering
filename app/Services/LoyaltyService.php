<?php

namespace App\Services;

use App\Models\Cafe;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyCampaign;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class LoyaltyService
{
    /** @return Collection<int, LoyaltyCampaign> */
    public function availableCampaigns(Cafe $cafe, User $customer): Collection
    {
        if (! $cafe->loyalty_enabled || $customer->role !== User::ROLE_CUSTOMER || ! $customer->is_active) {
            return collect();
        }

        $account = LoyaltyAccount::query()->where('cafe_id', $cafe->id)->where('user_id', $customer->id)->first();

        return $cafe->loyaltyCampaigns()->where('is_active', true)->orderBy('name')->get()
            ->filter(function (LoyaltyCampaign $campaign) use ($cafe, $customer, $account): bool {
                if (! $campaign->isCurrentlyActive()) {
                    return false;
                }

                if ($campaign->type === LoyaltyCampaign::TYPE_POINTS) {
                    return ($account?->points ?? 0) >= $campaign->requiredPoints();
                }

                $spent = abs((int) LoyaltyTransaction::query()->where('cafe_id', $cafe->id)->where('user_id', $customer->id)
                    ->where('campaign_id', $campaign->id)->where('type', 'reward_redeemed')->sum('orders_delta'));
                $refunded = (int) LoyaltyTransaction::query()->where('cafe_id', $cafe->id)->where('user_id', $customer->id)
                    ->where('campaign_id', $campaign->id)->where('type', 'reward_refunded')->sum('orders_delta');

                return ($account?->completed_order_count ?? 0) - $spent + $refunded >= $campaign->requiredOrderCount();
            })->values();
    }

    public function redeem(Cafe $cafe, User $customer, LoyaltyCampaign $campaign, Order $order, int $subtotalCents, ?int $actorId): int
    {
        abort_unless($cafe->loyalty_enabled && $campaign->cafe_id === $cafe->id && $customer->role === User::ROLE_CUSTOMER && $customer->is_active, 404);
        $lockedCustomer = User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
        $account = LoyaltyAccount::query()->firstOrCreate(
            ['cafe_id' => $cafe->id, 'user_id' => $lockedCustomer->id],
            ['points' => 0, 'completed_order_count' => 0],
        );
        $account = LoyaltyAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        $lockedCampaign = LoyaltyCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();

        if (! $lockedCampaign->isCurrentlyActive()) {
            throw ValidationException::withMessages(['loyalty_campaign_id' => 'Bu kampanya şu anda kullanılamıyor.']);
        }

        if ($lockedCampaign->reward_type === LoyaltyCampaign::REWARD_PERCENT && (float) $lockedCampaign->reward_value > 100) {
            throw ValidationException::withMessages(['loyalty_campaign_id' => 'Yüzde indirim en fazla yüzde 100 olabilir.']);
        }

        if ($subtotalCents < self::moneyToCents((string) $lockedCampaign->minimum_order_total)) {
            throw ValidationException::withMessages(['loyalty_campaign_id' => 'Sipariş tutarı bu ödül için gereken minimum tutarın altında.']);
        }

        $ordersDelta = 0;
        $pointsDelta = 0;
        if ($lockedCampaign->type === LoyaltyCampaign::TYPE_ORDER_COUNT) {
            $spentOrderCount = abs((int) LoyaltyTransaction::query()
                ->where('cafe_id', $cafe->id)->where('user_id', $customer->id)
                ->where('campaign_id', $lockedCampaign->id)
                ->where('type', 'reward_redeemed')->sum('orders_delta'));
            $refundedOrderCount = (int) LoyaltyTransaction::query()
                ->where('cafe_id', $cafe->id)->where('user_id', $customer->id)
                ->where('campaign_id', $lockedCampaign->id)
                ->where('type', 'reward_refunded')->sum('orders_delta');
            $availableOrderCount = $account->completed_order_count - $spentOrderCount + $refundedOrderCount;

            if ($availableOrderCount < $lockedCampaign->requiredOrderCount()) {
                throw ValidationException::withMessages(['loyalty_campaign_id' => 'Bu ödül için yeterli tamamlanmış siparişiniz yok.']);
            }
            $ordersDelta = -$lockedCampaign->requiredOrderCount();
        } else {
            $requiredPoints = $lockedCampaign->requiredPoints();
            if ($account->points < $requiredPoints) {
                throw ValidationException::withMessages(['loyalty_campaign_id' => 'Bu ödül için yeterli puanınız yok.']);
            }
            $pointsDelta = -$requiredPoints;
        }

        $rewardValueCents = self::moneyToCents((string) $lockedCampaign->reward_value);
        $discountCents = $lockedCampaign->reward_type === LoyaltyCampaign::REWARD_PERCENT
            ? intdiv($subtotalCents * $rewardValueCents, 10000)
            : min($subtotalCents, $rewardValueCents);
        $account->forceFill(['points' => $account->points + $pointsDelta])->save();
        LoyaltyTransaction::query()->create([
            'cafe_id' => $cafe->id,
            'user_id' => $customer->id,
            'campaign_id' => $lockedCampaign->id,
            'order_id' => $order->id,
            'actor_id' => $actorId,
            'type' => 'reward_redeemed',
            'points_delta' => $pointsDelta,
            'orders_delta' => $ordersDelta,
            'discount_amount' => self::centsToMoney($discountCents),
            'description' => 'Sipariş ödülü kullanıldı: '.$lockedCampaign->name,
        ]);

        return $discountCents;
    }

    public function refundReward(Order $order, ?int $actorId): void
    {
        if ($order->customer_id === null || $order->loyalty_campaign_id === null) {
            return;
        }

        $redemption = LoyaltyTransaction::query()
            ->where('order_id', $order->id)->where('type', 'reward_redeemed')->first();
        if ($redemption === null) {
            return;
        }

        $customer = User::query()->whereKey($order->customer_id)->lockForUpdate()->first();
        if ($customer === null) {
            return;
        }
        $account = LoyaltyAccount::query()->where('cafe_id', $order->cafe_id)->where('user_id', $customer->id)->lockForUpdate()->first();
        if ($account === null) {
            return;
        }

        $account->forceFill(['points' => max(0, $account->points + abs($redemption->points_delta))])->save();
        LoyaltyTransaction::query()->create([
            'cafe_id' => $order->cafe_id,
            'user_id' => $customer->id,
            'campaign_id' => $redemption->campaign_id,
            'order_id' => $order->id,
            'actor_id' => $actorId,
            'type' => 'reward_refunded',
            'points_delta' => abs($redemption->points_delta),
            'orders_delta' => abs($redemption->orders_delta),
            'discount_amount' => $redemption->discount_amount,
            'description' => 'Ödül iadesi: sipariş iptal edildi',
        ]);
    }

    public function earnForPaidOrder(Order $order): void
    {
        $cafe = Cafe::query()->find($order->cafe_id);
        $customer = User::query()->whereKey($order->customer_id)->first();

        if ($cafe === null || ! $cafe->loyalty_enabled || $customer === null || $customer->role !== User::ROLE_CUSTOMER) {
            return;
        }

        $account = LoyaltyAccount::query()->firstOrCreate(
            ['cafe_id' => $cafe->id, 'user_id' => $customer->id],
            ['points' => 0, 'completed_order_count' => 0],
        );
        $account = LoyaltyAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
        $earnedPoints = intdiv(self::moneyToCents((string) $order->total), 10000) * $cafe->points_per_100;
        $account->forceFill([
            'points' => $account->points + $earnedPoints,
            'completed_order_count' => $account->completed_order_count + 1,
        ])->save();
        LoyaltyTransaction::query()->create([
            'cafe_id' => $cafe->id,
            'user_id' => $customer->id,
            'order_id' => $order->id,
            'type' => 'earned',
            'points_delta' => $earnedPoints,
            'orders_delta' => 1,
            'discount_amount' => 0,
            'description' => 'Ödenmiş siparişten puan kazanıldı',
        ]);
    }

    public function reverseEarningsForPaidOrder(Order $order, ?int $actorId): void
    {
        if ($order->customer_id === null) {
            return;
        }

        $transactions = LoyaltyTransaction::query()
            ->where('order_id', $order->id)
            ->whereIn('type', ['earned', 'earned_reversed'])
            ->lockForUpdate()
            ->get();
        $pointsDelta = (int) $transactions->sum('points_delta');
        $ordersDelta = (int) $transactions->sum('orders_delta');

        if ($pointsDelta <= 0 && $ordersDelta <= 0) {
            return;
        }

        $customer = User::query()->whereKey($order->customer_id)->lockForUpdate()->first();
        if ($customer === null) {
            return;
        }

        $account = LoyaltyAccount::query()
            ->where('cafe_id', $order->cafe_id)
            ->where('user_id', $customer->id)
            ->lockForUpdate()
            ->first();
        if ($account === null) {
            return;
        }

        $account->forceFill([
            'points' => max(0, $account->points - $pointsDelta),
            'completed_order_count' => max(0, $account->completed_order_count - $ordersDelta),
        ])->save();

        LoyaltyTransaction::query()->create([
            'cafe_id' => $order->cafe_id,
            'user_id' => $customer->id,
            'order_id' => $order->id,
            'actor_id' => $actorId,
            'type' => 'earned_reversed',
            'points_delta' => -$pointsDelta,
            'orders_delta' => -$ordersDelta,
            'discount_amount' => 0,
            'description' => 'Tahsilat kaydı düzeltildi; sipariş puanı geri alındı',
        ]);
    }

    public static function moneyToCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    public static function centsToMoney(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
