<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Cafe;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ServiceRequest;
use App\Models\TableSession;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Services\Money;
use App\Services\OrderPresenter;
use App\Services\OrderSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class BoardController extends Controller
{
    public function index(Request $request): View
    {
        $branches = $this->branchesFor($request->user());

        if ($branches->isEmpty()) {
            return view('staff.no-branch');
        }

        $branch = $branches->firstWhere('id', (int) $request->query('sube')) ?? $branches->first();

        return $this->board($branch, $branches);
    }

    public function branch(Request $request, Branch $branch): View
    {
        Gate::authorize('view', $branch);

        return $this->board($branch, $this->branchesFor($request->user()));
    }

    public function data(Request $request, Branch $branch, OrderPresenter $presenter): JsonResponse
    {
        Gate::authorize('view', $branch);

        $orders = $branch->orders()
            ->with(['tableSession.diningTable', 'items.options'])
            ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_ACCEPTED, Order::STATUS_READY])
            ->orderBy('created_at')
            ->get();

        $openSessions = $branch->tableSessions()
            ->with('diningTable')
            ->where('status', TableSession::STATUS_OPEN)
            ->get()
            ->keyBy('dining_table_id');

        $latestSessionIds = $branch->tableSessions()
            ->selectRaw('MAX(id) as latest_id')
            ->groupBy('dining_table_id')
            ->pluck('latest_id');
        $latestSessions = TableSession::query()
            ->whereIn('id', $latestSessionIds)
            ->get()
            ->keyBy('dining_table_id');

        $tables = $branch->diningTables()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($table) use ($openSessions, $latestSessions): array {
                $session = $openSessions->get($table->id);
                $lastSession = $latestSessions->get($table->id);

                return [
                    'id' => $table->id,
                    'name' => $table->name,
                    'is_open' => $session !== null,
                    'session_id' => $session?->id,
                    'session_url' => $session !== null ? route('staff.sessions.show', $session->id) : null,
                    'qr_ordering_enabled' => $session?->qr_ordering_enabled ?? false,
                    'last_session_url' => $lastSession !== null ? route('staff.sessions.show', $lastSession->id) : null,
                    'last_session_status' => $lastSession?->status,
                    'last_session_opened_label' => $lastSession?->opened_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i'),
                    'due_cents' => $session?->dueAmountCents() ?? 0,
                    'due' => Money::format($session?->dueAmountCents() ?? 0),
                    'active_orders' => $session?->activeOrdersCount() ?? 0,
                    'opened_label' => $session?->opened_at?->diffForHumans(),
                ];
            })
            ->values()
            ->all();

        $requests = $branch->serviceRequests()
            ->with(['diningTable', 'claimedBy'])
            ->where('status', ServiceRequest::STATUS_OPEN)
            ->latest()
            ->get()
            ->map(fn (ServiceRequest $serviceRequest): array => [
                'id' => $serviceRequest->id,
                'table' => $serviceRequest->diningTable?->name,
                'type' => $serviceRequest->type,
                'type_label' => $serviceRequest->type === ServiceRequest::TYPE_BILL ? 'Hesap istiyor' : 'Garson çağırıyor',
                'note' => $serviceRequest->note,
                'created_label' => $serviceRequest->created_at?->diffForHumans(),
                'claimed_by' => $serviceRequest->claimed_by,
                'claimed_by_name' => $serviceRequest->claimedBy?->name,
                'is_claimed_by_me' => $serviceRequest->claimed_by === auth()->id(),
                'can_manage_requests' => auth()->user()?->hasRole(User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER) ?? false,
                'can_claim_requests' => auth()->user()?->hasRole(User::ROLE_WAITER) ?? false,
            ])
            ->values()
            ->all();

        $orderPayload = $orders->map(function (Order $order) use ($presenter): array {
            $payload = $presenter->order($order);
            $payload['table_name'] = $order->tableSession?->diningTable?->name;
            $payload['session_id'] = $order->table_session_id;
            $payload['url'] = route('staff.sessions.show', $order->table_session_id);
            $payload['due_cents'] = $order->tableSession?->dueAmountCents() ?? 0;

            return $payload;
        })->values()->all();

        $requestsVersion = $branch->serviceRequests()
            ->where('status', ServiceRequest::STATUS_OPEN)
            ->max('updated_at');

        $version = max(
            $orders->max('updated_at')?->timestamp ?? 0,
            $openSessions->max('updated_at')?->timestamp ?? 0,
            $requestsVersion !== null ? (int) strtotime((string) $requestsVersion) : 0,
        );

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'version' => (string) $version,
            'summary' => [
                'pending' => $orders->where('status', Order::STATUS_PENDING)->count(),
                'accepted' => $orders->where('status', Order::STATUS_ACCEPTED)->count(),
                'ready' => $orders->where('status', Order::STATUS_READY)->count(),
                'open_tables' => $openSessions->count(),
                'requests' => count($requests),
            ],
            'orders' => $orderPayload,
            'tables' => $tables,
            'can_open_tables' => auth()->user()?->hasRole(User::ROLE_WAITER, User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER) ?? false,
            'requests' => $requests,
        ]);
    }

    public function updateStatus(
        Request $request,
        Order $order,
        OrderSettlementService $settlement,
        LoyaltyService $loyalty,
    ): JsonResponse|RedirectResponse {
        Gate::authorize('view', $order->branch);

        $validated = $request->validate([
            'status' => ['required', Rule::in([
                Order::STATUS_ACCEPTED,
                Order::STATUS_READY,
                Order::STATUS_SERVED,
                Order::STATUS_CANCELLED,
            ])],
            'reason' => ['nullable', 'string', 'min:3', 'max:160'],
        ]);

        $target = $validated['status'];

        $alreadyAtTarget = false;

        DB::transaction(function () use ($order, $target, $validated, $request, $settlement, $loyalty, &$alreadyAtTarget): void {
            $session = TableSession::query()->whereKey($order->table_session_id)->lockForUpdate()->firstOrFail();
            $lockedOrder = Order::query()->whereKey($order->id)->where('table_session_id', $session->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status === $target) {
                $alreadyAtTarget = true;

                return;
            }

            $allowed = match ($target) {
                Order::STATUS_ACCEPTED => [Order::STATUS_PENDING],
                Order::STATUS_READY => [Order::STATUS_PENDING, Order::STATUS_ACCEPTED],
                Order::STATUS_SERVED => [Order::STATUS_ACCEPTED, Order::STATUS_READY],
                Order::STATUS_CANCELLED => [Order::STATUS_PENDING, Order::STATUS_ACCEPTED, Order::STATUS_READY],
                default => [],
            };

            if (! in_array($lockedOrder->status, $allowed, true)) {
                throw ValidationException::withMessages([
                    'status' => 'Sipariş durumu değişti. Panoyu yenileyip tekrar deneyin.',
                ]);
            }

            if ($target === Order::STATUS_CANCELLED) {
                $reason = trim((string) ($validated['reason'] ?? ''));
                if (mb_strlen($reason) < 3) {
                    throw ValidationException::withMessages(['reason' => 'İptal gerekçesi en az 3 karakter olmalıdır.']);
                }

                $hasItemPayment = Payment::query()
                    ->whereIn('order_item_id', $lockedOrder->items()->select('id'))
                    ->whereNull('voided_at')
                    ->exists();
                $hasUnallocatedPayment = $session->payments()->whereNull('order_item_id')->whereNull('voided_at')->exists();

                if ($hasItemPayment || $hasUnallocatedPayment) {
                    throw ValidationException::withMessages([
                        'order' => 'Bu sipariş için ödeme kaydı var. Önce ilgili ödemeyi iptal edin.',
                    ]);
                }

                $loyalty->refundReward($lockedOrder, $request->user()->id);
                $lockedOrder->forceFill([
                    'status' => Order::STATUS_CANCELLED,
                    'cancel_reason' => $reason,
                    'cancelled_at' => now(),
                ])->save();
            } else {
                $timestamp = match ($target) {
                    Order::STATUS_ACCEPTED => 'accepted_at',
                    Order::STATUS_READY => 'ready_at',
                    Order::STATUS_SERVED => 'served_at',
                };

                $lockedOrder->forceFill(['status' => $target, $timestamp => now()])->save();

                if ($target === Order::STATUS_SERVED) {
                    $settlement->settleServedOrders($session);
                }
            }

            ActivityLog::query()->create([
                'cafe_id' => $lockedOrder->cafe_id,
                'branch_id' => $lockedOrder->branch_id,
                'user_id' => $request->user()->id,
                'action' => 'order.'.$target,
                'subject_type' => Order::class,
                'subject_id' => $lockedOrder->id,
                'metadata' => $target === Order::STATUS_CANCELLED ? ['reason' => $validated['reason']] : [],
                'ip_address' => $request->ip(),
            ]);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'idempotent' => $alreadyAtTarget,
                'message' => $alreadyAtTarget ? 'Sipariş durumu zaten güncel.' : match ($target) {
                    Order::STATUS_ACCEPTED => 'Sipariş hazırlanmaya alındı.',
                    Order::STATUS_READY => 'Sipariş hazır olarak işaretlendi.',
                    Order::STATUS_SERVED => 'Sipariş teslim edildi.',
                    Order::STATUS_CANCELLED => 'Sipariş iptal edildi.',
                },
            ]);
        }

        return back()->with('status', 'Sipariş güncellendi.');
    }

    public function resolveServiceRequest(Request $request, ServiceRequest $serviceRequest): JsonResponse|RedirectResponse
    {
        abort_unless($request->user()->hasRole(User::ROLE_WAITER, User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER), 403);
        Gate::authorize('view', $serviceRequest->branch);

        $isManager = $request->user()->hasRole(User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER);
        $resolved = DB::transaction(function () use ($request, $serviceRequest, $isManager): bool {
            $lockedRequest = ServiceRequest::query()->whereKey($serviceRequest->id)->lockForUpdate()->firstOrFail();

            if ($lockedRequest->status !== ServiceRequest::STATUS_OPEN) {
                return false;
            }

            if (! $isManager && $lockedRequest->claimed_by !== $request->user()->id) {
                abort(403, 'Önce çağrıyı üzerinize alın.');
            }

            $claimedBy = $lockedRequest->claimed_by;
            $lockedRequest->forceFill([
                'status' => ServiceRequest::STATUS_RESOLVED,
                'resolved_by' => $request->user()->id,
                'resolved_at' => now(),
                'claimed_by' => null,
                'claimed_at' => null,
            ])->save();

            ActivityLog::query()->create([
                'cafe_id' => $lockedRequest->branch->cafe_id,
                'branch_id' => $lockedRequest->branch_id,
                'user_id' => $request->user()->id,
                'action' => 'service_request.resolved',
                'subject_type' => ServiceRequest::class,
                'subject_id' => $lockedRequest->id,
                'metadata' => ['claimed_by' => $claimedBy, 'manager_override' => $isManager],
                'ip_address' => $request->ip(),
            ]);

            return true;
        });

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $resolved ? 'Çağrı tamamlandı.' : 'Çağrı zaten tamamlanmış.']);
        }

        return back()->with('status', 'Çağrı tamamlandı.');
    }

    public function claimServiceRequest(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        abort_unless($request->user()->hasRole(User::ROLE_WAITER), 403);
        Gate::authorize('view', $serviceRequest->branch);

        $claimed = DB::transaction(function () use ($request, $serviceRequest): bool {
            $lockedRequest = ServiceRequest::query()->whereKey($serviceRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedRequest->status === ServiceRequest::STATUS_OPEN, 409, 'Çağrı artık açık değil.');

            if ($lockedRequest->claimed_by !== null && $lockedRequest->claimed_by !== $request->user()->id) {
                $ownerName = $lockedRequest->claimedBy?->name ?? 'başka bir çalışan';
                abort(409, "Bu çağrıyla {$ownerName} ilgileniyor.");
            }

            if ($lockedRequest->claimed_by === $request->user()->id) {
                return false;
            }

            $lockedRequest->forceFill([
                'claimed_by' => $request->user()->id,
                'claimed_at' => now(),
            ])->save();

            ActivityLog::query()->create([
                'cafe_id' => $lockedRequest->branch->cafe_id,
                'branch_id' => $lockedRequest->branch_id,
                'user_id' => $request->user()->id,
                'action' => 'service_request.claimed',
                'subject_type' => ServiceRequest::class,
                'subject_id' => $lockedRequest->id,
                'metadata' => [],
                'ip_address' => $request->ip(),
            ]);

            return true;
        });

        return response()->json([
            'ok' => true,
            'message' => $claimed ? 'Çağrı üzerinize alındı.' : 'Bu çağrı zaten sizde.',
        ]);
    }

    public function releaseServiceRequest(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        abort_unless($request->user()->hasRole(User::ROLE_WAITER), 403);
        Gate::authorize('view', $serviceRequest->branch);

        DB::transaction(function () use ($request, $serviceRequest): void {
            $lockedRequest = ServiceRequest::query()->whereKey($serviceRequest->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedRequest->status === ServiceRequest::STATUS_OPEN, 409, 'Çağrı artık açık değil.');
            abort_unless($lockedRequest->claimed_by === $request->user()->id, 403, 'Bu çağrı başka bir çalışana ait.');

            $lockedRequest->forceFill(['claimed_by' => null, 'claimed_at' => null])->save();

            ActivityLog::query()->create([
                'cafe_id' => $lockedRequest->branch->cafe_id,
                'branch_id' => $lockedRequest->branch_id,
                'user_id' => $request->user()->id,
                'action' => 'service_request.released',
                'subject_type' => ServiceRequest::class,
                'subject_id' => $lockedRequest->id,
                'metadata' => [],
                'ip_address' => $request->ip(),
            ]);
        });

        return response()->json(['ok' => true, 'message' => 'Çağrı tekrar ekibe açıldı.']);
    }

    /** @return Collection<int, Branch> */
    private function branchesFor(?User $user): Collection
    {
        if ($user === null) {
            return collect();
        }

        $query = Branch::query()
            ->with('cafe')
            ->where('is_active', true)
            ->whereHas('cafe', fn ($cafeQuery) => $cafeQuery->where('status', Cafe::STATUS_ACTIVE))
            ->orderBy('name');

        if ($user->role === User::ROLE_PLATFORM_ADMIN) {
            return $query->get();
        }

        if ($user->hasRole(User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER)) {
            return $query->where('cafe_id', $user->cafe_id)->get();
        }

        return $query->whereIn('branches.id', $user->branches()->select('branches.id'))->get();
    }

    /** @param Collection<int, Branch> $branches */
    private function board(Branch $branch, Collection $branches): View
    {
        return view('staff.board', [
            'branch' => $branch,
            'branches' => $branches,
        ]);
    }
}
