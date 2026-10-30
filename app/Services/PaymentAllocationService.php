<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use Illuminate\Support\Collection;

class PaymentAllocationService
{
    /** @return array<int, int> Remaining cents keyed by order item ID */
    public function itemBalances(TableSession $session): array
    {
        $activePayments = $session->payments()->whereNull('voided_at')->with('allocations')->get();
        $orders = $session->orders()
            ->whereIn('status', [Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_SERVED])
            ->with(['items' => fn ($query) => $query->orderBy('id')])
            ->orderBy('id')
            ->get();
        $paidByItemCents = $activePayments
            ->flatMap(function (Payment $payment): Collection {
                if ($payment->allocations->isNotEmpty()) {
                    return $payment->allocations
                        ->filter(fn ($allocation): bool => $allocation->order_item_id !== null)
                        ->map(fn ($allocation): array => [
                            'order_item_id' => (int) $allocation->order_item_id,
                            'amount_cents' => Money::toCents((string) $allocation->amount),
                        ]);
                }

                if ($payment->order_item_id === null) {
                    return collect();
                }

                return collect([[
                    'order_item_id' => (int) $payment->order_item_id,
                    'amount_cents' => Money::toCents((string) $payment->amount),
                ]]);
            })
            ->groupBy('order_item_id')
            ->map(fn (Collection $lines): int => $lines->sum('amount_cents'));
        $unallocatedPaymentCents = $activePayments
            ->filter(fn (Payment $payment): bool => $payment->order_item_id === null && $payment->allocations->isEmpty())
            ->sum(fn ($payment): int => Money::toCents((string) $payment->amount));
        $dueCents = $session->dueAmountCents();
        $itemBalances = [];
        $mappedItemDueCents = 0;

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $itemDue = max(0, Money::toCents((string) $item->line_total) - ($paidByItemCents[$item->id] ?? 0));
                $generalPayment = min($itemDue, $unallocatedPaymentCents);
                $unallocatedPaymentCents -= $generalPayment;
                $itemDue -= $generalPayment;
                $itemDue = min($itemDue, max(0, $dueCents - $mappedItemDueCents));

                if ($itemDue > 0) {
                    $itemBalances[$item->id] = $itemDue;
                    $mappedItemDueCents += $itemDue;
                }
            }
        }

        return $itemBalances;
    }
}
