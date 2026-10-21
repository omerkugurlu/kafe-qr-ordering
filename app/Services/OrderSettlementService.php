<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TableSession;

class OrderSettlementService
{
    /**
     * Finalize every remaining order once the session has no outstanding balance.
     *
     * A full payment may arrive while the kitchen is still preparing the order.
     * In that case the order is marked as served and paid so the table can be
     * closed without leaving an unfinished order behind.
     */
    public function settleServedOrders(TableSession $session): bool
    {
        if ($session->status !== TableSession::STATUS_OPEN || $session->dueAmountCents() !== 0) {
            return false;
        }

        $loyalty = app(LoyaltyService::class);
        $orders = $session->orders()
            ->whereNotIn('status', [Order::STATUS_PAID, Order::STATUS_CANCELLED])
            ->lockForUpdate()
            ->get();

        foreach ($orders as $order) {
            $now = now();
            $order->forceFill([
                'status' => Order::STATUS_PAID,
                'accepted_at' => $order->accepted_at ?? $now,
                'served_at' => $order->served_at ?? $now,
                'paid_at' => $now,
            ])->save();
            $loyalty->earnForPaidOrder($order);
        }

        return $session->orders()
            ->whereNotIn('status', [Order::STATUS_PAID, Order::STATUS_CANCELLED])
            ->doesntExist();
    }
}
