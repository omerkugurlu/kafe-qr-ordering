<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Coupon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CouponController extends Controller
{
    public function index(Request $request): View
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);

        return view('manage.coupons', [
            'cafe' => $cafe,
            'coupons' => $cafe->coupons()->withCount('orders')->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);
        $validated = $this->validateCoupon($request, $cafe->id);

        $coupon = $cafe->coupons()->create([
            ...$validated,
            'code' => Coupon::normalizeCode($validated['code']),
            'is_active' => $request->boolean('is_active', true),
            'used_count' => 0,
        ]);
        $this->log($request, $coupon, 'coupon.created');

        return redirect()->route('manage.coupons.index')->with('status', $coupon->code.' kuponu oluşturuldu.');
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        abort_unless($coupon->cafe_id === $request->user()->cafe_id, 404);
        $validated = $this->validateCoupon($request, $coupon->cafe_id, $coupon->id);

        $coupon->fill([
            ...$validated,
            'code' => Coupon::normalizeCode($validated['code']),
            'is_active' => $request->boolean('is_active'),
        ])->save();
        $this->log($request, $coupon, 'coupon.updated');

        return redirect()->route('manage.coupons.index')->with('status', $coupon->code.' kuponu güncellendi.');
    }

    public function destroy(Request $request, Coupon $coupon): RedirectResponse
    {
        abort_unless($coupon->cafe_id === $request->user()->cafe_id, 404);
        $coupon->forceFill(['is_active' => false])->save();
        $this->log($request, $coupon, 'coupon.deactivated');

        return redirect()->route('manage.coupons.index')->with('status', $coupon->code.' kuponu satışa kapatıldı.');
    }

    /** @return array<string, mixed> */
    private function validateCoupon(Request $request, int $cafeId, ?int $ignoreId = null): array
    {
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('coupons', 'code')->where('cafe_id', $cafeId)->ignore($ignoreId),
            ],
            'type' => ['required', Rule::in([Coupon::TYPE_PERCENT, Coupon::TYPE_FIXED])],
            'value' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,2'],
            'min_subtotal' => ['nullable', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($validated['type'] === Coupon::TYPE_PERCENT && (float) $validated['value'] > 100) {
            throw ValidationException::withMessages(['value' => 'Yüzde indirim en fazla 100 olabilir.']);
        }

        $validated['min_subtotal'] = $validated['min_subtotal'] ?? '0.00';

        return $validated;
    }

    private function log(Request $request, Coupon $coupon, string $action): void
    {
        ActivityLog::query()->create([
            'cafe_id' => $coupon->cafe_id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => Coupon::class,
            'subject_id' => $coupon->id,
            'metadata' => ['code' => $coupon->code, 'active' => $coupon->is_active],
            'ip_address' => $request->ip(),
        ]);
    }
}
