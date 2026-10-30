<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Cafe;
use App\Models\CafeSubscription;
use App\Models\CafeSubscriptionRequest;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use App\Services\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(SubscriptionService $subscriptions): View
    {
        $cafes = Cafe::query()->with(['users' => fn ($query) => $query->where('role', 'cafe_owner'), 'branches', 'subscriptions'])
            ->orderBy('name')->get();

        return view('platform.subscriptions', [
            'plans' => SubscriptionPlan::query()->orderBy('sort_order')->orderBy('id')->get(),
            'requests' => CafeSubscriptionRequest::query()->with(['cafe', 'plan', 'requester'])
                ->where('status', 'pending')->latest('requested_at')->get(),
            'cafes' => $cafes->map(function (Cafe $cafe) use ($subscriptions): array {
                $current = $subscriptions->current($cafe);
                $pending = $cafe->subscriptionRequests()->where('status', 'pending')->with('plan')->first();

                return [
                    'cafe' => $cafe,
                    'current' => $current,
                    'pending' => $pending,
                    'owner' => $cafe->users->firstWhere('role', 'cafe_owner'),
                    'tables' => $cafe->branches->sum(fn ($branch): int => $branch->diningTables()->count()),
                    'branches' => $cafe->branches->count(),
                ];
            }),
            'features' => SubscriptionService::FEATURES,
        ]);
    }

    public function updatePlan(Request $request, SubscriptionPlan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'annual_price_tl' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'max_tables' => ['required', 'integer', 'min:1', 'max:5000'],
            'max_branches' => ['nullable', 'integer', 'min:1', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['sometimes', 'boolean'],
        ]);
        $features = array_fill_keys(array_keys(SubscriptionService::FEATURES), false);
        foreach ($validated['features'] ?? [] as $key => $enabled) {
            if (array_key_exists($key, $features)) {
                $features[$key] = (bool) $enabled;
            }
        }
        $active = $request->boolean('is_active');
        $priceCents = Money::toCents((string) $validated['annual_price_tl']);
        if ($active && $priceCents < 1) {
            throw ValidationException::withMessages(['annual_price_tl' => 'Satışa açık paket için yıllık fiyat sıfırdan büyük olmalıdır.']);
        }

        $plan->forceFill([
            'name' => $validated['name'],
            'annual_price_cents' => $priceCents,
            'max_tables' => $validated['max_tables'],
            'max_branches' => $validated['max_branches'] ?? null,
            'features_json' => $features,
            'is_active' => $active,
        ])->save();

        $this->log($request, null, 'subscription_plan.updated', SubscriptionPlan::class, $plan->id, ['plan' => $plan->name]);

        return back()->with('status', $plan->name.' paketi kaydedildi. Eski abonelik kayıtlarının özellikleri korunuyor.');
    }

    public function approve(Request $request, CafeSubscriptionRequest $subscriptionRequest): RedirectResponse
    {
        $request->validate(['payment_confirmed' => ['required', 'accepted']]);

        DB::transaction(function () use ($request, $subscriptionRequest): void {
            $item = CafeSubscriptionRequest::query()->whereKey($subscriptionRequest->id)->lockForUpdate()->firstOrFail();
            if ($item->status !== 'pending') {
                throw ValidationException::withMessages(['request' => 'Bu paket talebi daha önce sonuçlandırılmış.']);
            }
            $cafe = Cafe::query()->whereKey($item->cafe_id)->lockForUpdate()->firstOrFail();
            app(SubscriptionService::class)->ensureFits($cafe, $item->max_tables_snapshot, $item->max_branches_snapshot);
            $lastEnd = $cafe->subscriptions()->where('status', 'active')->where('ends_at', '>', now())->max('ends_at');
            $startsAt = $lastEnd ? Carbon::parse($lastEnd) : now();
            $startsAt = $startsAt->copy();
            $endsAt = $startsAt->copy()->addYear();
            $subscription = $cafe->subscriptions()->create([
                'subscription_plan_id' => $item->subscription_plan_id,
                'activated_by' => $request->user()->id,
                'plan_name_snapshot' => $item->plan_name_snapshot,
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'annual_price_snapshot_cents' => $item->annual_price_snapshot_cents,
                'max_tables_snapshot' => $item->max_tables_snapshot,
                'max_branches_snapshot' => $item->max_branches_snapshot,
                'features_snapshot_json' => $item->features_snapshot_json,
            ]);
            $item->forceFill([
                'status' => 'approved',
                'pending_cafe_id' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'cafe_subscription_id' => $subscription->id,
            ])->save();
            $this->log($request, $cafe->id, 'subscription_request.approved', CafeSubscriptionRequest::class, $item->id, ['subscription_id' => $subscription->id]);
        });

        return back()->with('status', 'Ödeme onaylandı ve paket etkinleştirildi.');
    }

    public function reject(Request $request, CafeSubscriptionRequest $subscriptionRequest): RedirectResponse
    {
        $validated = $request->validate(['review_note' => ['required', 'string', 'min:3', 'max:250']]);
        DB::transaction(function () use ($request, $subscriptionRequest, $validated): void {
            $item = CafeSubscriptionRequest::query()
                ->whereKey($subscriptionRequest->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();
            $item->forceFill([
                'status' => 'rejected',
                'pending_cafe_id' => null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $validated['review_note'],
            ])->save();
            $this->log($request, $item->cafe_id, 'subscription_request.rejected', CafeSubscriptionRequest::class, $item->id, ['note' => $validated['review_note']]);
        });

        return back()->with('status', 'Paket talebi reddedildi.');
    }

    public function assign(Request $request, Cafe $cafe): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
            'ends_on' => ['required', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'min:3', 'max:250'],
        ]);
        $plan = SubscriptionPlan::query()->findOrFail($validated['plan_id']);
        $subscriptions = app(SubscriptionService::class);
        $subscriptions->ensureFits($cafe, $plan->max_tables, $plan->max_branches);
        $pending = $cafe->subscriptionRequests()->where('status', 'pending')->exists();
        if ($pending) {
            throw ValidationException::withMessages(['subscription' => 'Önce bu kafenin bekleyen paket talebini sonuçlandırın.']);
        }

        DB::transaction(function () use ($request, $cafe, $plan, $validated): void {
            $cafe->subscriptions()->where('status', 'active')->where('ends_at', '>', now())->update(['status' => 'replaced', 'updated_at' => now()]);
            $cafe->subscriptions()->create([
                'subscription_plan_id' => $plan->id,
                'activated_by' => $request->user()->id,
                'plan_name_snapshot' => $plan->name,
                'status' => 'active',
                'starts_at' => now(),
                'ends_at' => Carbon::parse($validated['ends_on'])->endOfDay(),
                'annual_price_snapshot_cents' => $plan->annual_price_cents,
                'max_tables_snapshot' => $plan->max_tables,
                'max_branches_snapshot' => $plan->max_branches,
                'features_snapshot_json' => $plan->features_json,
            ]);
            $this->log($request, $cafe->id, 'subscription.admin_overridden', CafeSubscription::class, $plan->id, ['reason' => $validated['reason'], 'plan' => $plan->name]);
        });

        return back()->with('status', $cafe->name.' için paket ve bitiş tarihi güncellendi.');
    }

    private function log(Request $request, ?int $cafeId, string $action, string $subjectType, int $subjectId, array $metadata): void
    {
        ActivityLog::query()->create([
            'user_id' => $request->user()->id,
            'cafe_id' => $cafeId,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => $metadata,
            'ip_address' => $request->ip(),
        ]);
    }
}
