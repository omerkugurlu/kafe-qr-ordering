<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use App\Services\OrderSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function store(Request $request, int $sessionId, OrderSettlementService $settlement): RedirectResponse
    {
        $validated = $request->validate([
            'method' => ['required', Rule::in([Payment::METHOD_CASH, Payment::METHOD_CARD])],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,2'],
            'person_label' => ['nullable', 'string', 'max:80'],
            'order_item_id' => ['nullable', 'integer'],
        ]);
        $session = TableSession::query()->with('branch')->findOrFail($sessionId);
        Gate::authorize('view', $session->branch);
        $amountCents = self::moneyToCents((string) $validated['amount']);

        DB::transaction(function () use ($session, $request, $validated, $amountCents, $settlement): void {
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === TableSession::STATUS_OPEN, 409);
            $dueCents = $locked->dueAmountCents();

            if ($dueCents === 0 || $amountCents > $dueCents) {
                throw ValidationException::withMessages(['amount' => 'Tahsilat tutarı kalan adisyon tutarını aşamaz.']);
            }

            $orderItemId = $validated['order_item_id'] ?? null;
            if ($orderItemId !== null) {
                $item = $locked->orders()->whereIn('status', [Order::STATUS_ACCEPTED, Order::STATUS_SERVED])
                    ->whereHas('items', fn ($query) => $query->whereKey($orderItemId))
                    ->with(['items' => fn ($query) => $query->whereKey($orderItemId)])
                    ->firstOrFail()->items->first();
                $alreadyPaid = Payment::query()->where('order_item_id', $orderItemId)->whereNull('voided_at')->sum('amount');
                $itemDueCents = max(0, self::moneyToCents((string) $item->line_total) - self::moneyToCents((string) $alreadyPaid));

                if ($amountCents > $itemDueCents) {
                    throw ValidationException::withMessages(['amount' => 'Bu kalem için kalan tutardan fazla ödeme kaydedilemez.']);
                }
            }

            $payment = $locked->payments()->create([
                'recorded_by' => $request->user()->id,
                'order_item_id' => $orderItemId,
                'method' => $validated['method'],
                'amount' => intdiv($amountCents, 100).'.'.str_pad((string) ($amountCents % 100), 2, '0', STR_PAD_LEFT),
                'person_label' => $validated['person_label'] ?? null,
                'recorded_at' => now(),
            ]);

            $settlement->settleServedOrders($locked);

            ActivityLog::query()->create([
                'cafe_id' => $locked->branch->cafe_id,
                'branch_id' => $locked->branch_id,
                'user_id' => $request->user()->id,
                'action' => 'payment.recorded',
                'subject_type' => Payment::class,
                'subject_id' => $payment->id,
                'metadata' => ['method' => $payment->method, 'amount' => $payment->amount, 'person_label' => $payment->person_label],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('staff.sessions.show', $sessionId)->with('status', 'Ödeme kaydedildi. Kart ödemesi fiziksel POS üzerinden alınmış olmalıdır.');
    }

    public function void(Request $request, int $sessionId, int $paymentId): RedirectResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:160']]);
        $session = TableSession::query()->with('branch')->findOrFail($sessionId);
        Gate::authorize('view', $session->branch);

        DB::transaction(function () use ($session, $paymentId, $request, $validated): void {
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === TableSession::STATUS_OPEN, 409);
            $payment = $locked->payments()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->voided_at !== null) {
                throw ValidationException::withMessages(['payment' => 'Bu ödeme kaydı daha önce iptal edilmiş.']);
            }

            if ($locked->orders()->where('status', Order::STATUS_PAID)->exists()) {
                $relatedOrder = $payment->orderItem?->order;
                if ($relatedOrder?->status === Order::STATUS_PAID || $payment->order_item_id === null) {
                    throw ValidationException::withMessages(['payment' => 'Ödeme kapanmış bir siparişe ait veya adisyon geneline kaydedilmiş. Bu kaydı buradan iptal edemezsiniz.']);
                }
            }

            $payment->forceFill(['voided_at' => now(), 'void_reason' => trim($validated['reason'])])->save();
            ActivityLog::query()->create([
                'cafe_id' => $locked->branch->cafe_id,
                'branch_id' => $locked->branch_id,
                'user_id' => $request->user()->id,
                'action' => 'payment.voided',
                'subject_type' => Payment::class,
                'subject_id' => $payment->id,
                'metadata' => ['reason' => $payment->void_reason, 'amount' => $payment->amount],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('staff.sessions.show', $sessionId)->with('status', 'Ödeme kaydı iptal edildi ve geçmişte tutuldu.');
    }

    private static function moneyToCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }
}
