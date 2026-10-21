<?php

namespace App\Livewire\Staff;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSession;
use App\Services\LoyaltyService;
use App\Services\OrderSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

class OrderBoard extends Component
{
    use WithPagination;

    #[Locked]
    public int $branchId;

    public function mount(int $branchId): void
    {
        $this->branchId = $branchId;
        Gate::authorize('view', $this->branch());
    }

    public function accept(int $orderId): void
    {
        $this->changeStatus($orderId, Order::STATUS_PENDING, Order::STATUS_ACCEPTED, 'accepted_at');
    }

    public function serve(int $orderId, OrderSettlementService $settlement): void
    {
        $this->changeStatus($orderId, Order::STATUS_ACCEPTED, Order::STATUS_SERVED, 'served_at', $settlement);
    }

    public function cancel(int $orderId, string $reason, LoyaltyService $loyalty): void
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 160) {
            throw ValidationException::withMessages(['cancelReason' => 'İptal gerekçesi 3-160 karakter olmalıdır.']);
        }

        $branch = $this->branch();
        Gate::authorize('view', $branch);
        DB::transaction(function () use ($orderId, $branch, $reason, $loyalty): void {
            $candidate = $branch->orders()->whereKey($orderId)->firstOrFail();
            $session = TableSession::query()->whereKey($candidate->table_session_id)->lockForUpdate()->firstOrFail();
            $order = $branch->orders()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_ACCEPTED], true)) {
                throw ValidationException::withMessages(['order' => 'Bu sipariş artık iptal edilemez.']);
            }

            $hasItemPayment = Payment::query()->whereIn('order_item_id', $order->items()->select('id'))->whereNull('voided_at')->exists();
            $hasUnallocatedPayment = $session->payments()->whereNull('order_item_id')->whereNull('voided_at')->exists();
            if ($hasItemPayment || $hasUnallocatedPayment) {
                throw ValidationException::withMessages(['order' => 'Bu sipariş için ödeme kaydı var. Önce ilgili ödemeyi iptal edin.']);
            }

            $loyalty->refundReward($order, auth()->id());
            $order->forceFill(['status' => Order::STATUS_CANCELLED, 'cancel_reason' => $reason, 'cancelled_at' => now()])->save();
            $this->log($order, 'order.cancelled', ['reason' => $reason]);
        });
    }

    public function render(): View
    {
        $branch = $this->branch();
        Gate::authorize('view', $branch);

        return view('livewire.staff.order-board', [
            'branch' => $branch,
            'orders' => $branch->orders()
                ->with(['tableSession.diningTable', 'items.options'])
                ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_ACCEPTED])
                ->latest()
                ->paginate(12),
        ]);
    }

    private function changeStatus(int $orderId, string $from, string $to, string $timestampColumn, ?OrderSettlementService $settlement = null): void
    {
        $branch = $this->branch();
        Gate::authorize('view', $branch);
        DB::transaction(function () use ($orderId, $branch, $from, $to, $timestampColumn, $settlement): void {
            $candidate = $branch->orders()->whereKey($orderId)->firstOrFail();
            $session = TableSession::query()->whereKey($candidate->table_session_id)->lockForUpdate()->firstOrFail();
            $order = $branch->orders()->whereKey($orderId)->lockForUpdate()->firstOrFail();

            if ($order->status !== $from) {
                throw ValidationException::withMessages(['order' => 'Sipariş durumu değişti. Listeyi yenileyip tekrar deneyin.']);
            }

            $order->forceFill(['status' => $to, $timestampColumn => now()])->save();
            if ($to === Order::STATUS_SERVED) {
                $settlement?->settleServedOrders($session);
            }
            $this->log($order, 'order.'.$to);
        });
    }

    private function branch(): Branch
    {
        return Branch::query()->whereKey($this->branchId)->where('is_active', true)->firstOrFail();
    }

    private function log(Order $order, string $action, array $metadata = []): void
    {
        ActivityLog::query()->create([
            'cafe_id' => $order->cafe_id,
            'branch_id' => $order->branch_id,
            'user_id' => auth()->id(),
            'action' => $action,
            'subject_type' => Order::class,
            'subject_id' => $order->id,
            'metadata' => $metadata,
        ]);
    }
}
