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
use App\Services\ProductOptionSelectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TableSessionController extends Controller
{
    public function show(int $sessionId): View
    {
        $session = TableSession::query()->with(['branch.cafe', 'diningTable', 'orders.items.options', 'payments.recorder'])->findOrFail($sessionId);
        Gate::authorize('view', $session->branch);

        $dueCents = $session->dueAmountCents();
        $activePayments = $session->payments->whereNull('voided_at');
        $paidByItemCents = $activePayments
            ->whereNotNull('order_item_id')
            ->groupBy('order_item_id')
            ->map(fn ($payments): int => $payments->sum(fn ($payment): int => Money::toCents($payment->amount)));
        $unallocatedPaymentCents = $activePayments
            ->whereNull('order_item_id')
            ->sum(fn ($payment): int => Money::toCents($payment->amount));
        $itemDueCents = [];
        $mappedItemDueCents = 0;

        foreach ($session->orders->whereIn('status', ['accepted', 'ready', 'served']) as $order) {
            foreach ($order->items as $item) {
                $itemDue = max(0, Money::toCents($item->line_total) - ($paidByItemCents[$item->id] ?? 0));
                $generalPayment = min($itemDue, $unallocatedPaymentCents);
                $unallocatedPaymentCents -= $generalPayment;
                $itemDue -= $generalPayment;

                $itemDue = min($itemDue, max(0, $dueCents - $mappedItemDueCents));

                if ($itemDue > 0) {
                    $itemDueCents[$item->id] = $itemDue;
                    $mappedItemDueCents += $itemDue;
                }
            }
        }

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

        return view('staff.session', compact('session', 'categories', 'dueCents', 'itemDueCents'));
    }

    public function addItems(Request $request, int $sessionId, OrderSettlementService $settlement, ProductOptionSelectionService $optionSelection): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['required', 'integer', 'min:0', 'max:20'],
            'options' => ['nullable', 'array'],
            'options.*' => ['array'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
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

        $order = DB::transaction(function () use ($session, $request, $validated, $quantities, $settlement, $optionSelection): Order {
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
                'status' => Order::STATUS_SERVED,
                'subtotal' => self::centsToMoney($subtotalCents),
                'discount_total' => '0.00',
                'total' => self::centsToMoney($subtotalCents),
                'customer_note' => $validated['note'] ?? null,
                'accepted_at' => now(),
                'served_at' => now(),
            ]);
            foreach ($snapshots as $snapshot) {
                $optionSnapshots = $snapshot['option_snapshots'];
                unset($snapshot['option_snapshots']);
                $item = $order->items()->create($snapshot);
                $item->options()->createMany($optionSnapshots);
            }
            $settlement->settleServedOrders($lockedSession);
            ActivityLog::query()->create([
                'cafe_id' => $cafe->id,
                'branch_id' => $branch->id,
                'user_id' => $request->user()->id,
                'action' => 'order.added_by_waiter',
                'subject_type' => Order::class,
                'subject_id' => $order->id,
                'metadata' => ['source' => 'waiter', 'total' => $order->total],
                'ip_address' => $request->ip(),
            ]);

            return $order;
        });

        return redirect()->route('staff.sessions.show', $sessionId)->with('status', "Ürünler adisyona eklendi. Sipariş numarası: {$order->order_number}");
    }

    public function close(Request $request, int $sessionId, OrderSettlementService $settlement): RedirectResponse
    {
        $validated = $request->validate(['closing_note' => ['nullable', 'string', 'max:160']]);
        $session = TableSession::query()->with('branch')->findOrFail($sessionId);
        Gate::authorize('view', $session->branch);

        DB::transaction(function () use ($session, $request, $validated, $settlement): void {
            $locked = TableSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === TableSession::STATUS_OPEN, 409);

            $settled = $settlement->settleServedOrders($locked);

            $unfinishedOrders = $locked->orders()->whereNotIn('status', [Order::STATUS_PAID, Order::STATUS_CANCELLED])->exists();
            if (! $settled || $unfinishedOrders || $locked->dueAmountCents() > 0) {
                throw ValidationException::withMessages(['session' => 'Masa kapanmadan önce bekleyen siparişleri tamamlayıp adisyonun kalanını tahsil edin.']);
            }

            $locked->forceFill([
                'status' => TableSession::STATUS_CLOSED,
                'closed_by' => $request->user()->id,
                'closed_at' => now(),
                'closing_note' => $validated['closing_note'] ?? null,
            ])->save();

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
