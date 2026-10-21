<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\TableSession;

class OrderPresenter
{
    /** @return array<string, mixed> */
    public function order(Order $order): array
    {
        $order->loadMissing(['items.options']);

        return [
            'id' => $order->id,
            'number' => $order->order_number,
            'status' => $order->status,
            'status_label' => $this->statusLabel($order->status),
            'source' => $order->source,
            'subtotal_cents' => Money::toCents($order->subtotal),
            'discount_cents' => Money::toCents($order->discount_total),
            'total_cents' => Money::toCents($order->total),
            'total' => Money::formatDecimal($order->total),
            'coupon_code' => $order->coupon_code,
            'note' => $order->customer_note,
            'cancel_reason' => $order->cancel_reason,
            'created_at' => $order->created_at?->toIso8601String(),
            'created_label' => $order->created_at?->diffForHumans(),
            'accepted_at' => $order->accepted_at?->toIso8601String(),
            'ready_at' => $order->ready_at?->toIso8601String(),
            'served_at' => $order->served_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'items' => $order->items->map(fn (OrderItem $item): array => [
                'id' => $item->id,
                'name' => $item->name_snapshot,
                'quantity' => $item->quantity,
                'unit_price_cents' => Money::toCents($item->unit_price),
                'line_total_cents' => Money::toCents($item->line_total),
                'line_total' => Money::formatDecimal($item->line_total),
                'note' => $item->customer_note,
                'options' => $item->options->map(fn ($option): array => [
                    'id' => $option->id,
                    'group' => $option->group_name_snapshot,
                    'name' => $option->name_snapshot,
                    'price_delta_cents' => Money::toCents($option->price_delta),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function session(TableSession $session): array
    {
        $session->loadMissing(['orders.items.options', 'payments.recorder', 'diningTable']);

        $activeOrders = $session->orders
            ->whereNotIn('status', [Order::STATUS_CANCELLED])
            ->sortBy('created_at')
            ->values();

        $totalCents = $activeOrders->sum(fn (Order $order): int => Money::toCents($order->total));
        $paidCents = $session->payments
            ->whereNull('voided_at')
            ->sum(fn (Payment $payment): int => Money::toCents($payment->amount));

        return [
            'id' => $session->id,
            'status' => $session->status,
            'is_open' => $session->status === TableSession::STATUS_OPEN,
            'qr_ordering_enabled' => $session->qr_ordering_enabled,
            'qr_order_pending' => $activeOrders->contains(fn (Order $order): bool => $order->source === 'qr' && $order->status === Order::STATUS_PENDING),
            'table_name' => $session->diningTable?->name,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'opened_label' => $session->opened_at?->diffForHumans(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'orders' => $activeOrders->map(fn (Order $order): array => $this->order($order))->values()->all(),
            'orders_count' => $activeOrders->count(),
            'can_request_bill' => $session->status === TableSession::STATUS_OPEN
                && $activeOrders->contains(fn (Order $order): bool => in_array($order->status, Order::BILL_REQUEST_ELIGIBLE_STATUSES, true)),
            'total_cents' => $totalCents,
            'total' => Money::format($totalCents),
            'payable_cents' => $session->ordersTotalCents(),
            'paid_cents' => $paidCents,
            'paid' => Money::format($paidCents),
            'due_cents' => $session->dueAmountCents(),
            'due' => Money::format($session->dueAmountCents()),
            'payments' => $session->payments
                ->sortBy('recorded_at')
                ->map(fn (Payment $payment): array => [
                    'id' => $payment->id,
                    'method' => $payment->method,
                    'method_label' => $payment->method === Payment::METHOD_CASH ? 'Nakit' : 'Kart',
                    'amount_cents' => Money::toCents($payment->amount),
                    'amount' => Money::formatDecimal($payment->amount),
                    'person_label' => $payment->person_label,
                    'recorded_label' => $payment->recorded_at?->format('H:i'),
                    'recorded_by' => $payment->recorder?->name,
                    'voided' => $payment->voided_at !== null,
                ])->values()->all(),
        ];
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            Order::STATUS_PENDING => 'Sipariş alındı',
            Order::STATUS_ACCEPTED => 'Hazırlanıyor',
            Order::STATUS_READY => 'Hazır',
            Order::STATUS_SERVED => 'Teslim edildi',
            Order::STATUS_PAID => 'Ödendi',
            Order::STATUS_CANCELLED => 'İptal edildi',
            default => $status,
        };
    }
}
