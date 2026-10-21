<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\LoyaltyCampaign;
use App\Models\MenuCategory;
use App\Models\MenuProduct;
use App\Models\Order;
use App\Models\TableSession;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(Request $request, string $token, LoyaltyService $loyalty): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*' => ['required', 'integer', 'min:0', 'max:20'],
            'customer_note' => ['nullable', 'string', 'max:500'],
            'loyalty_campaign_id' => ['nullable', 'integer', 'exists:loyalty_campaigns,id'],
        ]);

        $table = DiningTable::query()->where('public_token_hash', hash('sha256', $token))->with('branch.cafe')->firstOrFail();
        $branch = $table->branch;
        $cafe = $branch->cafe;

        abort_unless($table->is_active && $branch->is_active && $cafe->status === 'active', 404);
        $customer = $request->user();

        if ($cafe->registration_policy === Cafe::POLICY_REQUIRED && $customer === null) {
            return redirect()->route('customer.register', $token)->with('status', 'Bu kafede sipariş için müşteri hesabı gerekiyor.');
        }

        if ($customer !== null && ($customer->role !== User::ROLE_CUSTOMER || $customer->cafe_id !== $cafe->id)) {
            throw ValidationException::withMessages(['items' => 'Bu müşteri hesabı bu kafede sipariş vermek için kullanılamaz.']);
        }

        $quantities = collect($validated['items'])->mapWithKeys(
            fn (int $quantity, string|int $id): array => [(int) $id => $quantity],
        )->filter(fn (int $quantity): bool => $quantity > 0);

        if ($quantities->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Lütfen en az bir ürün seçin.']);
        }
        $order = DB::transaction(function () use ($table, $branch, $cafe, $customer, $quantities, $validated, $request, $loyalty): Order {
            $lockedTable = DiningTable::query()->whereKey($table->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedTable->is_active, 404);
            $lockedBranch = $lockedTable->branch()->lockForUpdate()->firstOrFail();
            $lockedCafe = $lockedBranch->cafe()->lockForUpdate()->firstOrFail();
            abort_unless($lockedBranch->is_active && $lockedCafe->status === 'active', 404);

            if ($lockedCafe->registration_policy === Cafe::POLICY_REQUIRED && $customer === null) {
                throw ValidationException::withMessages(['items' => 'Perde yenilendi. Sipariş vermek için müşteri hesabınızla giriş yapın.']);
            }

            if ($customer !== null && ($customer->role !== User::ROLE_CUSTOMER || $customer->cafe_id !== $lockedCafe->id)) {
                throw ValidationException::withMessages(['items' => 'Bu müşteri hesabı bu kafede sipariş vermek için kullanılamaz.']);
            }

            $branchMenuId = $lockedBranch->uses_shared_menu ? null : $lockedBranch->id;
            DB::table('branch_product')->where('branch_id', $lockedBranch->id)
                ->whereIn('menu_product_id', $quantities->keys())->lockForUpdate()->get();
            $categoryIds = MenuProduct::query()->where('cafe_id', $lockedCafe->id)->where('branch_id', $branchMenuId)
                ->whereIn('id', $quantities->keys())->pluck('category_id')->unique();
            $activeCategoryIds = MenuCategory::query()->where('cafe_id', $lockedCafe->id)->where('branch_id', $branchMenuId)
                ->whereIn('id', $categoryIds)->where('is_active', true)->lockForUpdate()->pluck('id');
            $products = MenuProduct::query()
                ->with(['branches' => fn ($query) => $query->whereKey($lockedBranch->id)])
                ->where('cafe_id', $lockedCafe->id)
                ->where('branch_id', $branchMenuId)
                ->whereIn('category_id', $activeCategoryIds)
                ->whereIn('id', $quantities->keys())
                ->lockForUpdate()->get()->keyBy('id');

            if ($products->count() !== $quantities->count() || $products->contains(fn (MenuProduct $product): bool => ! $product->isAvailableAt($lockedBranch))) {
                throw ValidationException::withMessages(['items' => 'Menüdeki ürünlerden biri artık siparişe açık değil. Menüyü yenileyip tekrar deneyin.']);
            }

            $session = TableSession::query()
                ->where('dining_table_id', $lockedTable->id)
                ->where('status', TableSession::STATUS_OPEN)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $session ??= TableSession::query()->create([
                'branch_id' => $branch->id,
                'dining_table_id' => $lockedTable->id,
                'status' => TableSession::STATUS_OPEN,
                'opened_at' => now(),
            ]);

            $subtotalCents = 0;
            $snapshots = [];

            foreach ($products as $product) {
                $unitPrice = $product->priceFor($lockedBranch);
                $unitPriceCents = self::moneyToCents($unitPrice);
                $quantity = $quantities->get($product->id);
                $lineCents = $unitPriceCents * $quantity;
                $subtotalCents += $lineCents;
                $snapshots[] = [
                    'menu_product_id' => $product->id,
                    'name_snapshot' => $product->name,
                    'unit_price' => self::centsToMoney($unitPriceCents),
                    'quantity' => $quantity,
                    'line_total' => self::centsToMoney($lineCents),
                ];
            }

            do {
                $orderNumber = Str::upper(Str::random(10));
            } while (Order::query()->where('branch_id', $branch->id)->where('order_number', $orderNumber)->exists());

            $order = Order::query()->create([
                'cafe_id' => $lockedCafe->id,
                'branch_id' => $lockedBranch->id,
                'table_session_id' => $session->id,
                'customer_id' => $customer?->id,
                'loyalty_campaign_id' => $validated['loyalty_campaign_id'] ?? null,
                'order_number' => $orderNumber,
                'source' => 'qr',
                'status' => Order::STATUS_PENDING,
                'subtotal' => self::centsToMoney($subtotalCents),
                'discount_total' => '0.00',
                'total' => self::centsToMoney($subtotalCents),
                'customer_note' => $validated['customer_note'] ?? null,
            ]);
            $order->items()->createMany($snapshots);

            $discountCents = 0;
            if (! empty($validated['loyalty_campaign_id'])) {
                if ($customer === null || ! $cafe->loyalty_enabled) {
                    throw ValidationException::withMessages(['loyalty_campaign_id' => 'Sadakat ödülü için bu kafenin müşteri hesabında oturum açın.']);
                }
                $campaign = LoyaltyCampaign::query()->whereKey($validated['loyalty_campaign_id'])->where('cafe_id', $lockedCafe->id)->firstOrFail();
                $discountCents = $loyalty->redeem($lockedCafe, $customer, $campaign, $order, $subtotalCents, $customer->id);
                $order->forceFill([
                    'discount_total' => self::centsToMoney($discountCents),
                    'total' => self::centsToMoney(max(0, $subtotalCents - $discountCents)),
                ])->save();
            }

            ActivityLog::query()->create([
                'cafe_id' => $lockedCafe->id,
                'branch_id' => $lockedBranch->id,
                'user_id' => $customer?->id,
                'action' => 'order.placed',
                'subject_type' => Order::class,
                'subject_id' => $order->id,
                'metadata' => ['source' => 'qr', 'total' => $order->total],
                'ip_address' => $request->ip(),
            ]);

            return $order;
        });

        return redirect()->route('customer.menu', $token)->with('status', "Siparişiniz alındı. Sipariş numaranız: {$order->order_number}");
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
