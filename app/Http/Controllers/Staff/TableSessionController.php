<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\MenuCategory;
use App\Models\MenuProduct;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\Money;
use App\Services\OrderSettlementService;
use App\Services\PaymentAllocationService;
use App\Services\ProductOptionSelectionService;
use App\Services\ServiceRequestLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TableSessionController extends Controller
{
    public function openTable(Request $request, Branch $branch, DiningTable $table): JsonResponse|RedirectResponse
    {
        Gate::authorize('view', $branch);

        $result = DB::transaction(function () use ($request, $branch, $table): array {
            $lockedTable = DiningTable::query()->whereKey($table->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedTable->branch_id === $branch->id && $lockedTable->is_active, 404);
            $lockedBranch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $cafe = Cafe::query()->whereKey($lockedBranch->cafe_id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedBranch->is_active && $cafe->status === Cafe::STATUS_ACTIVE, 404);

            $session = TableSession::query()
                ->where('dining_table_id', $lockedTable->id)
                ->where('status', TableSession::STATUS_OPEN)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($session !== null) {
                return ['session' => $session, 'created' => false];
            }

            $session = TableSession::query()->create([
                'branch_id' => $lockedBranch->id,
                'dining_table_id' => $lockedTable->id,
                'status' => TableSession::STATUS_OPEN,
                'qr_ordering_enabled' => true,
                'opened_by' => $request->user()->id,
                'opened_at' => now(),
            ]);

            ActivityLog::query()->create([
                'cafe_id' => $cafe->id,
                'branch_id' => $lockedBranch->id,
                'user_id' => $request->user()->id,
                'action' => 'table_session.opened',
                'subject_type' => TableSession::class,
                'subject_id' => $session->id,
                'metadata' => ['table_id' => $lockedTable->id],
                'ip_address' => $request->ip(),
            ]);

            return ['session' => $session, 'created' => true];
        });

        $url = route('staff.sessions.show', $result['session']->id);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'created' => $result['created'], 'url' => $url]);
        }

        return redirect()->to($url)->with('status', $result['created'] ? 'Masa açıldı. QR ve manuel sipariş alabilirsiniz.' : 'Masanın adisyonu zaten açık.');
    }

    public function show(int $sessionId, PaymentAllocationService $paymentAllocation): View
    {
        $session = TableSession::query()->with(['branch.cafe', 'diningTable', 'orders.items.options', 'payments.recorder'])->findOrFail($sessionId);
        Gate::authorize('view', $session->branch);

        $dueCents = $session->dueAmountCents();
        $activePayments = $session->payments->whereNull('voided_at');
        $itemDueCents = $paymentAllocation->itemBalances($session);

        $branch = $session->branch;
        $branchMenuId = $branch->uses_shared_menu ? null : $branch->id;
        $categories = $branch->cafe->categories()->where('branch_id', $branchMenuId)->where('is_active', true)
            ->with(['products' => fn ($query) => $query->where('branch_id', $branchMenuId)
                ->with([
                    'branches' => fn ($branches) => $branches->whereKey($branch->id),
                    'optionGroups' => fn ($groups) => $groups->where('is_active', true)->with(['options' => fn ($options) => $options->where('is_available', true)]),
                ])->orderBy('name')])
            ->orderBy('sort_order')->get();
        $categories->each(fn ($category) => $category->setRelation(
            'products',
            $category->products->filter(fn ($product) => $product->isAvailableAt($branch))->values(),
        ));

        return view('staff.session', compact('session', 'categories', 'dueCents', 'itemDueCents', 'activePayments'));
    }

    public function setQrOrdering(Request $request, int $sessionId): RedirectResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $session = TableSession::query()->with('branch')->findOrFail($sessionId);
        Gate::authorize('view', $session->branch);
        $enabled = (bool) $validated['enabled'];

        DB::transaction(function () use ($session, $request, $enabled): void {
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === TableSession::STATUS_OPEN, 409);

            if ($locked->qr_ordering_enabled === $enabled) {
                return;
            }

            $locked->forceFill(['qr_ordering_enabled' => $enabled])->save();
            ActivityLog::query()->create([
                'cafe_id' => $locked->branch->cafe_id,
                'branch_id' => $locked->branch_id,
                'user_id' => $request->user()->id,
                'action' => $enabled ? 'table_session.qr_ordering_enabled' : 'table_session.qr_ordering_disabled',
                'subject_type' => TableSession::class,
                'subject_id' => $locked->id,
                'metadata' => [],
                'ip_address' => $request->ip(),
            ]);
        });

        return back()->with('status', $enabled ? 'Masada QR siparişi açıldı.' : 'Masada QR siparişi kapatıldı. Adisyon açık kalır.');
    }

    public function addItems(Request $request, int $sessionId, OrderSettlementService $settlement, ProductOptionSelectionService $optionSelection): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['required', 'integer', 'min:0', 'max:20'],
            'options' => ['nullable', 'array'],
            'options.*' => ['array'],
            'note' => ['nullable', 'string', 'max:500'],
            'mode' => ['nullable', 'in:send,served'],
        ]);
        $mode = $validated['mode'] ?? 'send';
        $session = TableSession::query()->with('branch.cafe')->findOrFail($sessionId);
        Gate::authorize('view', $session->branch);
        $quantities = collect($validated['items'])->mapWithKeys(
            fn (int $quantity, string|int $id): array => [(int) $id => $quantity],
        )->filter(fn (int $quantity): bool => $quantity > 0);
        if ($quantities->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Adisyona eklemek için en az bir ürün seçin.']);
        }

        if ($quantities->count() > 30) {
            throw ValidationException::withMessages(['items' => 'Bir adisyon işleminde en fazla 30 farklı ürün seçebilirsiniz.']);
        }

        $order = DB::transaction(function () use ($session, $request, $validated, $quantities, $mode, $settlement, $optionSelection): Order {
            $table = DiningTable::query()->whereKey($session->dining_table_id)->lockForUpdate()->firstOrFail();
            $branch = Branch::query()->whereKey($table->branch_id)->lockForUpdate()->firstOrFail();
            $cafe = Cafe::query()->whereKey($branch->cafe_id)->lockForUpdate()->firstOrFail();
            abort_unless($branch->is_active && $cafe->status === Cafe::STATUS_ACTIVE, 404);
            $lockedSession = TableSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless(
                $lockedSession->status === TableSession::STATUS_OPEN
                && $lockedSession->dining_table_id === $table->id
                && $lockedSession->branch_id === $branch->id,
                409,
            );
            $branchMenuId = $branch->uses_shared_menu ? null : $branch->id;

            DB::table('branch_product')->where('branch_id', $branch->id)
                ->whereIn('menu_product_id', $quantities->keys())->lockForUpdate()->get();
            $categoryIds = MenuProduct::query()->where('cafe_id', $cafe->id)->where('branch_id', $branchMenuId)
                ->whereIn('id', $quantities->keys())->pluck('category_id')->unique();
            $activeCategoryIds = MenuCategory::query()->where('cafe_id', $cafe->id)->where('branch_id', $branchMenuId)
                ->whereIn('id', $categoryIds)->where('is_active', true)->lockForUpdate()->pluck('id');
            $products = MenuProduct::query()
                ->with(['branches' => fn ($query) => $query->whereKey($branch->id)])
                ->where('cafe_id', $cafe->id)
                ->where('branch_id', $branchMenuId)
                ->whereIn('category_id', $activeCategoryIds)
                ->whereIn('id', $quantities->keys())
                ->lockForUpdate()->get()->keyBy('id');

            if ($products->count() !== $quantities->count() || $products->contains(fn (MenuProduct $product): bool => ! $product->isAvailableAt($branch))) {
                throw ValidationException::withMessages(['items' => 'Seçilen ürünlerden biri bu şubede satışa açık değil. Menüyü yenileyip tekrar deneyin.']);
            }
            $optionSelections = $optionSelection->resolve($products, $validated['options'] ?? []);

            $subtotalCents = 0;
            $snapshots = [];
            foreach ($products as $product) {
                $unitPriceCents = self::moneyToCents($product->priceFor($branch)) + $optionSelections[$product->id]['price_delta_cents'];
                $quantity = $quantities->get($product->id);
                $lineCents = $unitPriceCents * $quantity;
                $subtotalCents += $lineCents;
                $snapshots[] = [
                    'menu_product_id' => $product->id,
                    'name_snapshot' => $product->name,
                    'unit_price' => self::centsToMoney($unitPriceCents),
                    'quantity' => $quantity,
                    'line_total' => self::centsToMoney($lineCents),
                    'option_snapshots' => $optionSelections[$product->id]['snapshots'],
                ];
            }

            do {
                $orderNumber = Str::upper(Str::random(10));
            } while (Order::query()->where('branch_id', $branch->id)->where('order_number', $orderNumber)->exists());

            $order = $lockedSession->orders()->create([
                'cafe_id' => $cafe->id,
                'branch_id' => $branch->id,
                'created_by' => $request->user()->id,
                'order_number' => $orderNumber,
                'source' => 'waiter',
                'status' => $mode === 'served' ? Order::STATUS_SERVED : Order::STATUS_PENDING,
                'subtotal' => self::centsToMoney($subtotalCents),
                'discount_total' => '0.00',
                'total' => self::centsToMoney($subtotalCents),
                'customer_note' => $validated['note'] ?? null,
                'accepted_at' => $mode === 'served' ? now() : null,
                'served_at' => $mode === 'served' ? now() : null,
            ]);
            foreach ($snapshots as $snapshot) {
                $optionSnapshots = $snapshot['option_snapshots'];
                unset($snapshot['option_snapshots']);
                $item = $order->items()->create($snapshot);
                $item->options()->createMany($optionSnapshots);
            }
            if ($mode === 'served') {
                $settlement->settleServedOrders($lockedSession);
            }
            ActivityLog::query()->create([
                'cafe_id' => $cafe->id,
                'branch_id' => $branch->id,
                'user_id' => $request->user()->id,
                'action' => 'order.added_by_waiter',
                'subject_type' => Order::class,
                'subject_id' => $order->id,
                'metadata' => ['source' => 'waiter', 'mode' => $mode, 'total' => $order->total],
                'ip_address' => $request->ip(),
            ]);

            return $order;
        });

        $message = $mode === 'served'
            ? "Servis edilmiş ürünler adisyona eklendi. Sipariş numarası: {$order->order_number}"
            : "Manuel sipariş mutfağa gönderildi. Sipariş numarası: {$order->order_number}";

        return redirect()->route('staff.sessions.show', $sessionId)->with('status', $message);
    }

    public function correctOrder(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'reason' => ['required', 'string', 'min:3', 'max:160'],
            'customer_note' => ['nullable', 'string', 'max:500'],
        ]);

        Gate::authorize('view', $order->branch);

        DB::transaction(function () use ($request, $order, $validated): void {
            $session = TableSession::query()->whereKey($order->table_session_id)->lockForUpdate()->firstOrFail();
            $lockedOrder = Order::query()->whereKey($order->id)->where('table_session_id', $session->id)->lockForUpdate()->firstOrFail();
            abort_unless($session->status === TableSession::STATUS_OPEN, 409);

            if (! in_array($lockedOrder->status, [
                Order::STATUS_PENDING,
                Order::STATUS_ACCEPTED,
                Order::STATUS_READY,
                Order::STATUS_SERVED,
            ], true)) {
                throw ValidationException::withMessages(['order' => 'Ödenmiş veya iptal edilmiş sipariş değiştirilemez.']);
            }

            if ((float) $lockedOrder->discount_total > 0 || $lockedOrder->coupon_id !== null || $lockedOrder->loyalty_campaign_id !== null) {
                throw ValidationException::withMessages(['order' => 'İndirimli siparişler geçmişi korumak için bu ekrandan değiştirilemez.']);
            }

            if ($session->payments()->whereNull('voided_at')->exists()) {
                throw ValidationException::withMessages(['order' => 'Adisyonda ödeme kaydı varken sipariş değiştirilemez. Önce ödeme kaydını düzeltin.']);
            }

            $items = $lockedOrder->items()->lockForUpdate()->get()->keyBy('id');
            $submittedIds = collect(array_keys($validated['items']))->map(fn ($id): int => (int) $id)->sort()->values();
            $existingIds = $items->keys()->map(fn ($id): int => (int) $id)->sort()->values();

            if ($submittedIds->all() !== $existingIds->all()) {
                throw ValidationException::withMessages(['items' => 'Sipariş satırları yenilendi. Sayfayı yenileyip tekrar deneyin.']);
            }

            $oldTotalCents = Money::toCents((string) $lockedOrder->total);
            $subtotalCents = 0;
            $changes = [];

            foreach ($validated['items'] as $itemId => $values) {
                $item = $items->get((int) $itemId);
                $quantity = (int) $values['quantity'];
                $unitPriceCents = Money::toCents((string) $item->unit_price);
                $lineTotalCents = $unitPriceCents * $quantity;
                $subtotalCents += $lineTotalCents;

                if ($item->quantity !== $quantity) {
                    $changes[] = [
                        'item_id' => $item->id,
                        'name' => $item->name_snapshot,
                        'old_quantity' => $item->quantity,
                        'new_quantity' => $quantity,
                    ];
                }

                $item->forceFill([
                    'quantity' => $quantity,
                    'line_total' => Money::toDecimal($lineTotalCents),
                ])->save();
            }

            if ($changes === [] && ($validated['customer_note'] ?? null) === $lockedOrder->customer_note) {
                throw ValidationException::withMessages(['order' => 'Siparişte kaydedilecek bir değişiklik yok.']);
            }

            $lockedOrder->forceFill([
                'subtotal' => Money::toDecimal($subtotalCents),
                'total' => Money::toDecimal($subtotalCents),
                'customer_note' => $validated['customer_note'] ?? null,
            ])->save();

            ActivityLog::query()->create([
                'cafe_id' => $lockedOrder->cafe_id,
                'branch_id' => $lockedOrder->branch_id,
                'user_id' => $request->user()->id,
                'action' => 'order.corrected',
                'subject_type' => Order::class,
                'subject_id' => $lockedOrder->id,
                'metadata' => [
                    'reason' => trim($validated['reason']),
                    'old_total' => Money::toDecimal($oldTotalCents),
                    'new_total' => Money::toDecimal($subtotalCents),
                    'items' => $changes,
                    'customer_note' => $validated['customer_note'] ?? null,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('staff.sessions.show', $order->table_session_id)->with('status', 'Sipariş düzeltildi; ürün fiyatlarının sipariş anındaki kaydı korundu.');
    }

    public function close(Request $request, int $sessionId, OrderSettlementService $settlement, ServiceRequestLifecycleService $serviceRequests): RedirectResponse
    {
        $validated = $request->validate(['closing_note' => ['nullable', 'string', 'max:160']]);
        $session = TableSession::query()->with('branch')->findOrFail($sessionId);
        Gate::authorize('view', $session->branch);

        DB::transaction(function () use ($session, $request, $validated, $settlement, $serviceRequests): void {
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === TableSession::STATUS_OPEN, 409);

            $settled = $settlement->settleServedOrders($locked);

            $unfinishedOrders = $locked->orders()->whereNotIn('status', [Order::STATUS_PAID, Order::STATUS_CANCELLED])->exists();
            if (! $settled || $unfinishedOrders || $locked->dueAmountCents() > 0) {
                throw ValidationException::withMessages(['session' => 'Masa kapanmadan önce bekleyen siparişleri tamamlayıp adisyonun kalanını tahsil edin.']);
            }

            $locked->forceFill([
                'status' => TableSession::STATUS_CLOSED,
                'qr_ordering_enabled' => false,
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
                'closing_note' => $validated['closing_note'] ?? null,
            ])->save();

            $serviceRequests->resolveForClosedSession($locked, $request->user(), $request->ip());

            ActivityLog::query()->create([
                'cafe_id' => $locked->branch->cafe_id,
                'branch_id' => $locked->branch_id,
                'user_id' => $request->user()->id,
                'action' => 'table_session.closed',
                'subject_type' => TableSession::class,
                'subject_id' => $locked->id,
                'metadata' => ['note' => $validated['closing_note'] ?? null],
                'ip_address' => $request->ip(),
            ]);
        });

        return $this->redirectAfterClosure($request, $session)->with('status', 'Masa adisyonu kapatıldı.');
    }

    private function redirectAfterClosure(Request $request, TableSession $session): RedirectResponse
    {
        if ($request->user()?->hasRole(User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER)) {
            return redirect()->route('manage.orders.index', ['sube' => $session->branch_id]);
        }

        return redirect()->route('staff.branch', $session->branch_id);
    }

    private static function moneyToCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '0');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private static function centsToMoney(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
