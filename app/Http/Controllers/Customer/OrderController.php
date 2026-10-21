<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Cafe;
use App\Models\Coupon;
use App\Models\DiningTable;
use App\Models\LoyaltyCampaign;
use App\Models\MenuCategory;
use App\Models\MenuProduct;
use App\Models\Order;
use App\Models\ServiceRequest;
use App\Models\TableSession;
use App\Models\User;
use App\Services\LoyaltyService;
use App\Services\Money;
use App\Services\OrderPresenter;
use App\Services\ProductOptionSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(
        Request $request,
        string $token,
        LoyaltyService $loyalty,
        ProductOptionSelectionService $optionSelection,
    ): RedirectResponse|JsonResponse {
        $validated = $request->validate([
            'lines' => ['nullable', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required_with:lines', 'integer', 'min:1'],
            'lines.*.quantity' => ['required_with:lines', 'integer', 'min:1', 'max:20'],
            'lines.*.option_ids' => ['nullable', 'array', 'max:20'],
            'lines.*.option_ids.*' => ['integer', 'min:1'],
            'lines.*.note' => ['nullable', 'string', 'max:140'],
            'items' => ['nullable', 'array', 'min:1', 'max:500'],
            'items.*' => ['integer', 'min:0', 'max:20'],
            'options' => ['nullable', 'array'],
            'options.*' => ['array'],
            'customer_note' => ['nullable', 'string', 'max:500'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
            'loyalty_campaign_id' => ['nullable', 'integer', 'exists:loyalty_campaigns,id'],
        ]);

        $hasLines = ! empty($validated['lines']);
        $hasItems = ! empty($validated['items']);

        if ($hasLines && $hasItems) {
            throw ValidationException::withMessages(['items' => 'Sipariş tek bir formatta gönderilmelidir.']);
        }

        if (! $hasLines && ! $hasItems) {
            throw ValidationException::withMessages(['items' => 'Lütfen en az bir ürün seçin.']);
        }

        $couponCode = $validated['coupon_code'] ?? null;
        $couponCode = $couponCode !== null && trim($couponCode) !== '' ? Coupon::normalizeCode($couponCode) : null;

        if ($couponCode !== null && ! empty($validated['loyalty_campaign_id'])) {
            throw ValidationException::withMessages(['coupon_code' => 'Kupon ile sadakat ödülü aynı siparişte kullanılamaz.']);
        }

        $table = $this->tableForToken($token);
        $branch = $table->branch;
        $cafe = $branch->cafe;
        $customer = $request->user()?->role === User::ROLE_CUSTOMER ? $request->user() : null;

        if ($cafe->registration_policy === Cafe::POLICY_REQUIRED && $customer === null) {
            return redirect()->route('customer.login', $token)
                ->with('status', 'Bu kafede sipariş için müşteri hesabı gerekiyor.');
        }

        if ($customer !== null && ! $customer->is_active) {
            throw ValidationException::withMessages(['items' => 'Müşteri hesabınız aktif değil.']);
        }

        $quantities = collect($validated['items'] ?? [])
            ->mapWithKeys(fn (int $quantity, string|int $id): array => [(int) $id => $quantity])
            ->filter(fn (int $quantity): bool => $quantity > 0);

        if ($hasItems && $quantities->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'Lütfen en az bir ürün seçin.']);
        }

        if ($hasItems && $quantities->count() > 30) {
            throw ValidationException::withMessages(['items' => 'Bir siparişte en fazla 30 farklı ürün seçebilirsiniz.']);
        }

        $lines = collect($validated['lines'] ?? [])
            ->map(fn (array $line): array => [
                'product_id' => (int) $line['product_id'],
                'quantity' => (int) $line['quantity'],
                'option_ids' => array_map('intval', $line['option_ids'] ?? []),
                'note' => isset($line['note']) ? trim((string) $line['note']) : null,
            ])
            ->values();

        if ($hasLines && $lines->pluck('product_id')->unique()->count() > 30) {
            throw ValidationException::withMessages(['items' => 'Bir siparişte en fazla 30 farklı ürün seçebilirsiniz.']);
        }

        $order = DB::transaction(function () use (
            $table,
            $customer,
            $quantities,
            $lines,
            $hasLines,
            $validated,
            $couponCode,
            $request,
            $loyalty,
            $optionSelection,
        ): Order {
            $lockedTable = DiningTable::query()->whereKey($table->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedTable->is_active, 404);
            $lockedBranch = $lockedTable->branch()->lockForUpdate()->firstOrFail();
            $lockedCafe = $lockedBranch->cafe()->lockForUpdate()->firstOrFail();
            abort_unless($lockedBranch->is_active && $lockedCafe->status === Cafe::STATUS_ACTIVE, 404);

            if ($lockedCafe->registration_policy === Cafe::POLICY_REQUIRED && $customer === null) {
                throw ValidationException::withMessages(['items' => 'Sipariş vermek için müşteri hesabınızla giriş yapın.']);
            }

            $lockedCustomer = $customer === null
                ? null
                : User::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            if ($lockedCustomer !== null && (! $lockedCustomer->is_active || $lockedCustomer->role !== User::ROLE_CUSTOMER)) {
                throw ValidationException::withMessages(['items' => 'Bu müşteri hesabı bu kafede sipariş vermek için kullanılamaz.']);
            }

            $branchMenuId = $lockedBranch->uses_shared_menu ? null : $lockedBranch->id;
            $productIds = $hasLines
                ? $lines->pluck('product_id')->unique()->values()
                : $quantities->keys()->values();

            DB::table('branch_product')->where('branch_id', $lockedBranch->id)
                ->whereIn('menu_product_id', $productIds)->lockForUpdate()->get();

            $categoryIds = MenuProduct::query()
                ->where('cafe_id', $lockedCafe->id)
                ->where('branch_id', $branchMenuId)
                ->whereIn('id', $productIds)
                ->pluck('category_id')->unique();

            $activeCategoryIds = MenuCategory::query()
                ->where('cafe_id', $lockedCafe->id)
                ->where('branch_id', $branchMenuId)
                ->whereIn('id', $categoryIds)
                ->where('is_active', true)
                ->lockForUpdate()->pluck('id');

            $products = MenuProduct::query()
                ->with(['branches' => fn ($query) => $query->whereKey($lockedBranch->id)])
                ->where('cafe_id', $lockedCafe->id)
                ->where('branch_id', $branchMenuId)
                ->whereIn('category_id', $activeCategoryIds)
                ->whereIn('id', $productIds)
                ->lockForUpdate()->get()->keyBy('id');

            if ($products->count() !== $productIds->count() || $products->contains(fn (MenuProduct $product): bool => ! $product->isAvailableAt($lockedBranch))) {
                throw ValidationException::withMessages(['items' => 'Menüdeki ürünlerden biri artık siparişe açık değil. Menüyü yenileyip tekrar deneyin.']);
            }

            $session = TableSession::query()
                ->where('dining_table_id', $lockedTable->id)
                ->where('status', TableSession::STATUS_OPEN)
                ->lockForUpdate()
                ->latest('id')
                ->first();

            $session ??= TableSession::query()->create([
                'branch_id' => $lockedBranch->id,
                'dining_table_id' => $lockedTable->id,
                'status' => TableSession::STATUS_OPEN,
                'opened_at' => now(),
            ]);

            $subtotalCents = 0;
            $snapshots = [];

            if ($hasLines) {
                foreach ($lines as $line) {
                    $product = $products->get($line['product_id']);
                    $resolved = $optionSelection->resolveLine($product, $line['option_ids']);
                    $unitPriceCents = Money::toCents($product->priceFor($lockedBranch)) + $resolved['price_delta_cents'];
                    $lineCents = $unitPriceCents * $line['quantity'];
                    $subtotalCents += $lineCents;
                    $snapshots[] = [
                        'menu_product_id' => $product->id,
                        'name_snapshot' => $product->name,
                        'unit_price' => Money::toDecimal($unitPriceCents),
                        'quantity' => $line['quantity'],
                        'line_total' => Money::toDecimal($lineCents),
                        'customer_note' => $line['note'] ?: null,
                        'option_snapshots' => $resolved['snapshots'],
                    ];
                }
            } else {
                $optionSelections = $optionSelection->resolve($products, $validated['options'] ?? []);

                foreach ($products as $product) {
                    $unitPriceCents = Money::toCents($product->priceFor($lockedBranch)) + $optionSelections[$product->id]['price_delta_cents'];
                    $quantity = $quantities->get($product->id);
                    $lineCents = $unitPriceCents * $quantity;
                    $subtotalCents += $lineCents;
                    $snapshots[] = [
                        'menu_product_id' => $product->id,
                        'name_snapshot' => $product->name,
                        'unit_price' => Money::toDecimal($unitPriceCents),
                        'quantity' => $quantity,
                        'line_total' => Money::toDecimal($lineCents),
                        'option_snapshots' => $optionSelections[$product->id]['snapshots'],
                    ];
                }
            }

            $coupon = null;
            $couponDiscountCents = 0;

            if ($couponCode !== null) {
                $coupon = Coupon::query()
                    ->where('cafe_id', $lockedCafe->id)
                    ->where('code', $couponCode)
                    ->lockForUpdate()
                    ->first();

                $evaluation = Coupon::evaluate($coupon, $subtotalCents);

                if (! $evaluation['valid']) {
                    throw ValidationException::withMessages(['coupon_code' => $evaluation['message']]);
                }

                $couponDiscountCents = $evaluation['discount_cents'];
                $coupon->increment('used_count');
            }

            do {
                $orderNumber = Str::upper(Str::random(10));
            } while (Order::query()->where('branch_id', $lockedBranch->id)->where('order_number', $orderNumber)->exists());

            $order = Order::query()->create([
                'cafe_id' => $lockedCafe->id,
                'branch_id' => $lockedBranch->id,
                'table_session_id' => $session->id,
                'customer_id' => $lockedCustomer?->id,
                'loyalty_campaign_id' => $validated['loyalty_campaign_id'] ?? null,
                'coupon_id' => $coupon?->id,
                'coupon_code' => $coupon?->code,
                'order_number' => $orderNumber,
                'source' => 'qr',
                'status' => Order::STATUS_PENDING,
                'subtotal' => Money::toDecimal($subtotalCents),
                'discount_total' => Money::toDecimal($couponDiscountCents),
                'total' => Money::toDecimal(max(0, $subtotalCents - $couponDiscountCents)),
                'customer_note' => $validated['customer_note'] ?? null,
            ]);

            foreach ($snapshots as $snapshot) {
                $optionSnapshots = $snapshot['option_snapshots'];
                unset($snapshot['option_snapshots']);
                $item = $order->items()->create($snapshot);
                $item->options()->createMany($optionSnapshots);
            }

            if ($couponCode === null && ! empty($validated['loyalty_campaign_id'])) {
                if ($lockedCustomer === null || ! $lockedCafe->loyalty_enabled) {
                    throw ValidationException::withMessages(['loyalty_campaign_id' => 'Sadakat ödülü için bu kafenin müşteri hesabında oturum açın.']);
                }

                $campaign = LoyaltyCampaign::query()->whereKey($validated['loyalty_campaign_id'])->where('cafe_id', $lockedCafe->id)->firstOrFail();
                $discountCents = $loyalty->redeem($lockedCafe, $lockedCustomer, $campaign, $order, $subtotalCents, $lockedCustomer->id);
                $order->forceFill([
                    'discount_total' => Money::toDecimal($discountCents),
                    'total' => Money::toDecimal(max(0, $subtotalCents - $discountCents)),
                ])->save();
            }

            ActivityLog::query()->create([
                'cafe_id' => $lockedCafe->id,
                'branch_id' => $lockedBranch->id,
                'user_id' => $lockedCustomer?->id,
                'action' => 'order.placed',
                'subject_type' => Order::class,
                'subject_id' => $order->id,
                'metadata' => ['source' => 'qr', 'total' => $order->total, 'coupon' => $coupon?->code],
                'ip_address' => $request->ip(),
            ]);

            return $order;
        });

        $message = "Siparişiniz alındı. Sipariş numaranız: {$order->order_number}";

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'order_number' => $order->order_number,
                'redirect' => route('customer.order.track', ['token' => $token, 'orderNumber' => $order->order_number]),
                'message' => $message,
            ]);
        }

        $redirect = $hasLines
            ? route('customer.order.track', ['token' => $token, 'orderNumber' => $order->order_number])
            : route('customer.menu', $token);

        return redirect()->to($redirect)->with('status', $message);
    }

    public function track(Request $request, string $token, string $orderNumber, OrderPresenter $presenter): Response|RedirectResponse
    {
        $table = $this->tableForToken($token);

        if ($this->needsCustomerAccount($request, $table)) {
            return $this->redirectToCustomerLogin($token, 'Siparişinizi görüntülemek için müşteri hesabınızla giriş yapın.');
        }

        $order = Order::query()
            ->where('branch_id', $table->branch_id)
            ->where('order_number', $orderNumber)
            ->whereHas('tableSession', fn ($query) => $query->where('dining_table_id', $table->id))
            ->with(['items.options', 'tableSession.diningTable', 'tableSession.payments.recorder'])
            ->firstOrFail();

        return response()->view('customer.track', [
            'table' => $table,
            'branch' => $table->branch,
            'cafe' => $table->branch->cafe,
            'token' => $token,
            'order' => $presenter->order($order),
            'session' => $presenter->session($order->tableSession),
            'trackedOrderNumber' => $order->order_number,
        ])->header('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
    }

    public function bill(Request $request, string $token, OrderPresenter $presenter): Response|RedirectResponse
    {
        $table = $this->tableForToken($token);

        if ($this->needsCustomerAccount($request, $table)) {
            return $this->redirectToCustomerLogin($token, 'Adisyonunuzu görüntülemek için müşteri hesabınızla giriş yapın.');
        }

        $session = $this->currentSession($table);

        if ($session === null) {
            $session = TableSession::query()
                ->where('dining_table_id', $table->id)
                ->where('status', TableSession::STATUS_CLOSED)
                ->where('closed_at', '>=', now()->subHours(3))
                ->latest('closed_at')
                ->first();
        }

        if ($session === null) {
            return redirect()->route('customer.menu', $token)
                ->with('status', 'Masanızda açık bir adisyon bulunmuyor.');
        }

        return response()->view('customer.bill', [
            'table' => $table,
            'branch' => $table->branch,
            'cafe' => $table->branch->cafe,
            'token' => $token,
            'session' => $presenter->session($session),
        ])->header('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
    }

    public function status(Request $request, string $token, OrderPresenter $presenter): JsonResponse
    {
        $table = $this->tableForToken($token);

        if ($this->needsCustomerAccount($request, $table)) {
            return $this->customerLoginRequiredResponse();
        }

        $session = $this->currentSession($table);
        $order = null;

        $orderNumber = trim((string) $request->query('order', ''));

        if ($orderNumber !== '') {
            $order = Order::query()
                ->where('branch_id', $table->branch_id)
                ->where('order_number', $orderNumber)
                ->whereHas('tableSession', fn ($query) => $query->where('dining_table_id', $table->id))
                ->with(['items.options', 'tableSession.diningTable', 'tableSession.payments.recorder'])
                ->first();

            if ($order !== null) {
                $session = $order->tableSession;
            }
        }

        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
            'session' => $session !== null ? $presenter->session($session) : null,
            'order' => $order !== null ? $presenter->order($order) : null,
        ])->header('Cache-Control', 'private, no-store, max-age=0, must-revalidate');
    }

    public function checkCoupon(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'subtotal_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);

        $table = $this->tableForToken($token);

        if ($this->needsCustomerAccount($request, $table)) {
            return $this->customerLoginRequiredResponse();
        }

        $coupon = Coupon::query()
            ->where('cafe_id', $table->branch->cafe_id)
            ->where('code', Coupon::normalizeCode($validated['code']))
            ->first();

        $evaluation = Coupon::evaluate($coupon, (int) $validated['subtotal_cents']);

        return response()->json([
            'ok' => $evaluation['valid'],
            'message' => $evaluation['message'],
            'discount_cents' => $evaluation['discount_cents'],
            'code' => $coupon?->code,
            'min_subtotal_cents' => $coupon !== null ? Money::toCents((string) $coupon->min_subtotal) : null,
        ]);
    }

    public function callWaiter(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:waiter,bill'],
            'note' => ['nullable', 'string', 'max:160'],
        ]);

        $table = $this->tableForToken($token);

        if ($this->needsCustomerAccount($request, $table)) {
            return $this->customerLoginRequiredResponse();
        }

        $result = DB::transaction(function () use ($table, $validated): array {
            $lockedTable = DiningTable::query()
                ->whereKey($table->id)
                ->lockForUpdate()
                ->firstOrFail();
            $session = TableSession::query()
                ->where('dining_table_id', $lockedTable->id)
                ->where('status', TableSession::STATUS_OPEN)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($validated['type'] === ServiceRequest::TYPE_BILL
                && ($session === null || ! $session->orders()->whereIn('status', Order::BILL_REQUEST_ELIGIBLE_STATUSES)->exists())) {
                return ['eligible' => false, 'duplicate' => false];
            }

            $duplicate = ServiceRequest::query()
                ->where('dining_table_id', $lockedTable->id)
                ->where('type', $validated['type'])
                ->where('status', ServiceRequest::STATUS_OPEN)
                ->where('created_at', '>=', now()->subMinutes(2))
                ->exists();

            if ($duplicate) {
                return ['eligible' => true, 'duplicate' => true];
            }

            ServiceRequest::query()->create([
                'branch_id' => $lockedTable->branch_id,
                'dining_table_id' => $lockedTable->id,
                'table_session_id' => $session?->id,
                'type' => $validated['type'],
                'status' => ServiceRequest::STATUS_OPEN,
                'note' => $validated['note'] ?? null,
            ]);

            return ['eligible' => true, 'duplicate' => false];
        });

        if (! $result['eligible']) {
            return response()->json([
                'ok' => false,
                'code' => 'order_not_accepted',
                'message' => __('customer.bill_request_after_order_acceptance'),
            ], 422);
        }

        if ($result['duplicate']) {
            return response()->json([
                'ok' => true,
                'duplicate' => true,
                'message' => $validated['type'] === ServiceRequest::TYPE_BILL
                    ? 'Hesap isteğiniz garson ekranında. Kısa süre içinde gelecek.'
                    : 'Garson çağrınız zaten iletildi. Kısa süre içinde gelecek.',
            ]);
        }

        return response()->json([
            'ok' => true,
            'duplicate' => false,
            'message' => $validated['type'] === ServiceRequest::TYPE_BILL
                ? 'Hesap isteğiniz garsona iletildi.'
                : 'Garson çağrınız iletildi. Kısa süre içinde masanıza gelecek.',
        ]);
    }

    private function needsCustomerAccount(Request $request, DiningTable $table): bool
    {
        return $table->branch->cafe->registration_policy === Cafe::POLICY_REQUIRED
            && ($request->user()?->role !== User::ROLE_CUSTOMER || ! $request->user()->is_active);
    }

    private function redirectToCustomerLogin(string $token, string $message): RedirectResponse
    {
        return redirect()->route('customer.login', $token)->with('status', $message);
    }

    private function customerLoginRequiredResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Bu kafede devam etmek için müşteri hesabınızla giriş yapın.',
        ], 401);
    }

    private function tableForToken(string $token): DiningTable
    {
        $table = DiningTable::query()
            ->where('public_token_hash', hash('sha256', $token))
            ->with('branch.cafe')
            ->firstOrFail();

        abort_unless(
            $table->is_active
            && $table->branch?->is_active
            && $table->branch?->cafe?->status === Cafe::STATUS_ACTIVE,
            404,
        );

        return $table;
    }

    private function currentSession(DiningTable $table): ?TableSession
    {
        return TableSession::query()
            ->where('dining_table_id', $table->id)
            ->where('status', TableSession::STATUS_OPEN)
            ->latest('id')
            ->first();
    }
}
