<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\TableSession;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Services\Money;
use App\Services\OrderSettlementService;
use App\Services\PaymentAllocationService;
use App\Services\ServiceRequestLifecycleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function store(Request $request, int $sessionId, OrderSettlementService $settlement, ServiceRequestLifecycleService $serviceRequests, PaymentAllocationService $paymentAllocation): RedirectResponse
    {
        $validated = $request->validate([
            'method' => ['required', Rule::in([Payment::METHOD_CASH, Payment::METHOD_CARD])],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'integer', 'min:1', 'max:65535'],
            'cash_received' => ['required_if:method,cash', 'nullable', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,2'],
            'person_label' => ['nullable', 'string', 'max:80'],
        ]);
        $session = TableSession::query()->with('branch')->findOrFail($sessionId);
        Gate::authorize('view', $session->branch);
        $closed = false;
        $amountCents = 0;
        $changeCents = 0;

        DB::transaction(function () use ($session, $request, $validated, $settlement, $serviceRequests, $paymentAllocation, &$closed, &$amountCents, &$changeCents): void {
            $locked = TableSession::query()->with('branch')->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === TableSession::STATUS_OPEN, 409);
            $dueCents = $locked->dueAmountCents();

            if ($dueCents === 0) {
                throw ValidationException::withMessages(['items' => 'Bu adisyonda tahsil edilecek tutar kalmadı.']);
            }

            $allocations = collect($validated['items'])
                ->mapWithKeys(fn ($quantity, $id): array => [(int) $id => (int) $quantity])
                ->all();
            $items = OrderItem::query()
                ->whereIn('id', array_keys($allocations))
                ->whereHas('order', fn ($query) => $query
                    ->where('table_session_id', $locked->id)
                    ->whereIn('status', [Order::STATUS_ACCEPTED, Order::STATUS_READY, Order::STATUS_SERVED]))
                ->get()
                ->keyBy('id');

            if ($items->count() !== count($allocations)) {
                throw ValidationException::withMessages(['items' => 'Seçilen ürünlerden biri bu adisyonda bulunmuyor. Sayfayı yenileyip tekrar deneyin.']);
            }

            $itemBalances = $paymentAllocation->itemBalances($locked);
            $paymentLines = [];
            foreach ($allocations as $itemId => $quantity) {
                $item = $items->get($itemId);
                $itemDueCents = $itemBalances[$itemId] ?? 0;
                $unitCents = Money::toCents((string) $item->unit_price);
                $availableQuantity = $unitCents > 0
                    ? min($item->quantity, intdiv($itemDueCents + $unitCents - 1, $unitCents))
                    : 0;

                if ($quantity > $availableQuantity) {
                    throw ValidationException::withMessages(['items' => 'Seçilen ürün adedi kalan adetten fazla. Sayfayı yenileyip tekrar deneyin.']);
                }

                $lineCents = min($itemDueCents, $unitCents * $quantity);
                if ($lineCents <= 0) {
                    throw ValidationException::withMessages(['items' => 'Ödenecek tutarı olan ürünleri seçin.']);
                }

                $paymentLines[] = ['item' => $item, 'quantity' => $quantity, 'amount_cents' => $lineCents];
                $amountCents += $lineCents;
            }

            if ($amountCents <= 0 || $amountCents > $dueCents) {
                throw ValidationException::withMessages(['items' => 'Seçilen ürünlerin tutarı kalan adisyon tutarını aşamaz.']);
            }
            if ($amountCents > 100_000_000) {
                throw ValidationException::withMessages(['items' => 'Tek tahsilat 1.000.000 TL’yi geçemez.']);
            }

            if ($validated['method'] === Payment::METHOD_CASH) {
                $cashReceivedCents = Money::toCents((string) $validated['cash_received']);
                if ($cashReceivedCents < $amountCents) {
                    throw ValidationException::withMessages(['cash_received' => 'Alınan nakit, seçilen ürünlerin toplamından az olamaz.']);
                }
                $changeCents = $cashReceivedCents - $amountCents;
            } else {
                $cashReceivedCents = null;
            }

            $recordedAt = now();
            $payment = $locked->payments()->create([
                'recorded_by' => $request->user()->id,
                'order_item_id' => null,
                'method' => $validated['method'],
                'amount' => Money::toDecimal($amountCents),
                'cash_received' => $cashReceivedCents !== null ? Money::toDecimal($cashReceivedCents) : null,
                'change_amount' => Money::toDecimal($changeCents),
                'person_label' => $validated['person_label'] ?? null,
                'recorded_at' => $recordedAt,
            ]);
            $payment->allocations()->createMany(collect($paymentLines)->map(fn (array $line): array => [
                'order_item_id' => $line['item']->id,
                'quantity' => $line['quantity'],
                'amount' => Money::toDecimal($line['amount_cents']),
            ])->all());

            $settled = $settlement->settleServedOrders($locked);

            if ($settled) {
                $locked->forceFill([
                    'status' => TableSession::STATUS_CLOSED,
                    'qr_ordering_enabled' => false,
                    'closed_by' => $request->user()->id,
                    'closed_at' => now(),
                    'closing_note' => 'Tam ödeme ile otomatik kapatıldı.',
                ])->save();
                $closed = true;

                $serviceRequests->resolveForClosedSession($locked, $request->user(), $request->ip());

                ActivityLog::query()->create([
                    'cafe_id' => $locked->branch->cafe_id,
                    'branch_id' => $locked->branch_id,
                    'user_id' => $request->user()->id,
                    'action' => 'table_session.closed',
                    'subject_type' => TableSession::class,
                    'subject_id' => $locked->id,
                    'metadata' => ['note' => 'Tam ödeme ile otomatik kapatıldı.', 'payment_id' => $payment->id],
                    'ip_address' => $request->ip(),
                ]);
            }

            ActivityLog::query()->create([
                'cafe_id' => $locked->branch->cafe_id,
                'branch_id' => $locked->branch_id,
                'user_id' => $request->user()->id,
                'action' => 'payment.recorded',
                'subject_type' => Payment::class,
                'subject_id' => $payment->id,
                'metadata' => [
                    'method' => $payment->method,
                    'amount' => $payment->amount,
                    'person_label' => $payment->person_label,
                    'cash_received' => $payment->cash_received,
                    'change_given' => $payment->change_amount,
                    'allocations' => collect($paymentLines)->map(fn (array $line): array => [
                        'order_item_id' => $line['item']->id,
                        'name' => $line['item']->name_snapshot,
                        'quantity' => $line['quantity'],
                        'amount' => Money::toDecimal($line['amount_cents']),
                    ])->all(),
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        if ($closed) {
            $message = Money::format($amountCents).' ödeme alındı';
            if ($validated['method'] === Payment::METHOD_CASH) {
                $message .= '. Para üstü: '.Money::format($changeCents);
            }

            return $this->redirectAfterClosure($request, $session)->with('status', $message.' Adisyon kapatıldı.');
        }

        $message = Money::format($amountCents).' ödeme kaydedildi.';
        if ($validated['method'] === Payment::METHOD_CASH) {
            $message .= ' Para üstü: '.Money::format($changeCents).'.';
        } else {
            $message .= ' Kart ödemesi fiziksel POS üzerinden alınmış olmalıdır.';
        }
        $message .= ' Kalan ürünler sonraki ödeme için açık.';

        return redirect()->route('staff.sessions.show', $sessionId)->with('status', $message);
    }

    private function redirectAfterClosure(Request $request, TableSession $session): RedirectResponse
    {
        if ($request->user()?->hasRole(User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER)) {
            return redirect()->route('manage.orders.index', ['sube' => $session->branch_id]);
        }

        return redirect()->route('staff.branch', $session->branch_id);
    }

    public function void(Request $request, int $sessionId, int $paymentId, LoyaltyService $loyalty): RedirectResponse
    {
        abort_unless($request->user()?->role === User::ROLE_CAFE_OWNER, 403, 'Ödeme kaydını yalnızca kafe sahibi düzeltebilir.');
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:160']]);
        $session = TableSession::query()->with('branch')->findOrFail($sessionId);
        Gate::authorize('view', $session->branch);

        DB::transaction(function () use ($session, $paymentId, $request, $validated, $loyalty): void {
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $wasClosed = $locked->status === TableSession::STATUS_CLOSED;
            $payment = $locked->payments()
                ->with(['orderItem.order', 'allocations.orderItem.order'])
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(in_array($locked->status, [TableSession::STATUS_OPEN, TableSession::STATUS_CLOSED], true), 409);

            if ($payment->voided_at !== null) {
                throw ValidationException::withMessages(['payment' => 'Bu ödeme kaydı daha önce iptal edilmiş.']);
            }

            if ($locked->status === TableSession::STATUS_OPEN && $locked->orders()->where('status', Order::STATUS_PAID)->exists()) {
                $touchesPaidOrder = $payment->orderItem?->order?->status === Order::STATUS_PAID
                    || $payment->allocations->contains(fn ($allocation): bool => $allocation->orderItem?->order?->status === Order::STATUS_PAID);
                $isLegacyUnallocatedPayment = $payment->order_item_id === null && $payment->allocations->isEmpty();

                if ($touchesPaidOrder || $isLegacyUnallocatedPayment) {
                    throw ValidationException::withMessages(['payment' => 'Ödeme kapanmış bir siparişe ait veya adisyon geneline kaydedilmiş. Bu kaydı buradan iptal edemezsiniz.']);
                }
            }

            $payment->forceFill(['voided_at' => now(), 'void_reason' => trim($validated['reason'])])->save();

            if ($locked->status === TableSession::STATUS_CLOSED && $locked->dueAmountCents() > 0) {
                $affectedOrders = $locked->orders()->where('status', Order::STATUS_PAID)->lockForUpdate()->get();

                foreach ($affectedOrders as $order) {
                    $loyalty->reverseEarningsForPaidOrder($order, $request->user()->id);
                    $order->forceFill([
                        'status' => Order::STATUS_SERVED,
                        'paid_at' => null,
                    ])->save();
                }

                $locked->forceFill([
                    'status' => TableSession::STATUS_OPEN,
                    'qr_ordering_enabled' => false,
                    'closed_by' => null,
                    'closed_at' => null,
                    'closing_note' => null,
                ])->save();

                ActivityLog::query()->create([
                    'cafe_id' => $locked->branch->cafe_id,
                    'branch_id' => $locked->branch_id,
                    'user_id' => $request->user()->id,
                    'action' => 'table_session.reopened_for_payment_correction',
                    'subject_type' => TableSession::class,
                    'subject_id' => $locked->id,
                    'metadata' => [
                        'reason' => trim($validated['reason']),
                        'payment_id' => $payment->id,
                        'affected_orders' => $affectedOrders->modelKeys(),
                    ],
                    'ip_address' => $request->ip(),
                ]);
            }

            ActivityLog::query()->create([
                'cafe_id' => $locked->branch->cafe_id,
                'branch_id' => $locked->branch_id,
                'user_id' => $request->user()->id,
                'action' => 'payment.voided',
                'subject_type' => Payment::class,
                'subject_id' => $payment->id,
                'metadata' => [
                    'reason' => $payment->void_reason,
                    'amount' => $payment->amount,
                    'cash_received' => $payment->cash_received,
                    'change_given' => $payment->change_amount,
                    'allocations' => $payment->allocations->map(fn ($allocation): array => [
                        'order_item_id' => $allocation->order_item_id,
                        'quantity' => $allocation->quantity,
                        'amount' => $allocation->amount,
                    ])->all(),
                    'session_reopened' => $wasClosed && $locked->status === TableSession::STATUS_OPEN,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('staff.sessions.show', $sessionId)->with('status', 'Ödeme kaydı iptal edildi ve geçmişte tutuldu.');
    }

}
