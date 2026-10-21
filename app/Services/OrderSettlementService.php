<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TableSession;

class OrderSettlementService
{
    public function settleServedOrders(TableSession $session): void
    {
        if ($session->dueAmountCents() !== 0) {
            return;
        }

        $loyalty = app(LoyaltyService::class);
        $orders = $session->orders()->where('status', Order::STATUS_SERVED)->lockForUpdate()->get();

        foreach ($orders as $order) {
            $order->forceFill(['status' => Order::STATUS_PAID, 'paid_at' => now()])->save();
            $loyalty->earnForPaidOrder($order);
        }
    }
}
