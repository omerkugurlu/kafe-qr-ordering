<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\CafeSubscriptionRequest;
use App\Models\SubscriptionPlan;
use App\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SubscriptionController extends Controller
{
    public function index(Request $request, SubscriptionService $subscriptions): View
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);
        $current = $subscriptions->current($cafe);
        $scheduled = $cafe->subscriptions()->where('status', 'active')->where('starts_at', '>', now())->where('ends_at', '>', now())->orderBy('starts_at')->first();
        $expired = $cafe->subscriptions()->where('status', 'active')->where('ends_at', '<=', now())->latest('ends_at')->first();
        $plans = SubscriptionPlan::query()->where('is_active', true)->where('annual_price_cents', '>', 0)->orderBy('sort_order')->get();
        $pending = $cafe->subscriptionRequests()->where('status', 'pending')->first();
        $tables = DB::table('dining_tables')
            ->join('branches', 'branches.id', '=', 'dining_tables.branch_id')
            ->where('branches.cafe_id', $cafe->id)
            ->whereNull('branches.deleted_at')
            ->whereNull('dining_tables.deleted_at')
            ->count('dining_tables.id');
        $branches = $cafe->branches()->whereNull('deleted_at')->count();
        $requests = $cafe->subscriptionRequests()->latest('requested_at')->limit(8)->get();

        return view('manage.subscription', compact('cafe', 'current', 'scheduled', 'expired', 'plans', 'pending', 'tables', 'branches', 'requests'));
    }

    public function requestPlan(Request $request): RedirectResponse
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);
        $validated = $request->validate(['plan_id' => ['required', 'integer', 'exists:subscription_plans,id']]);
        $plan = SubscriptionPlan::query()->whereKey($validated['plan_id'])->where('is_active', true)->where('annual_price_cents', '>', 0)->firstOrFail();

        DB::transaction(function () use ($request, $cafe, $plan): void {
            $lockedCafe = $cafe->newQuery()->whereKey($cafe->id)->lockForUpdate()->firstOrFail();
            if ($lockedCafe->subscriptionRequests()->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['plan_id' => 'İncelemede olan bir paket talebiniz var. Önce bu talebin sonuçlanmasını bekleyin.']);
            }
            if ($lockedCafe->subscriptions()->where('status', 'active')->where('starts_at', '>', now())->where('ends_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['plan_id' => 'Sırada başlayan bir paketiniz var. Yeni talep için bu dönemin başlamasını bekleyin.']);
            }

            $lockedCafe->subscriptionRequests()->create([
                'pending_cafe_id' => $lockedCafe->id,
                'subscription_plan_id' => $plan->id,
                'requested_by' => $request->user()->id,
                'plan_name_snapshot' => $plan->name,
                'annual_price_snapshot_cents' => $plan->annual_price_cents,
                'max_tables_snapshot' => $plan->max_tables,
                'max_branches_snapshot' => $plan->max_branches,
                'features_snapshot_json' => $plan->features_json,
                'status' => 'pending',
                'requested_at' => now(),
            ]);
        });

        return back()->with('status', $plan->name.' paketi talebiniz yöneticiye gönderildi. Paket, ödeme kontrolünden sonra etkinleşir.');
    }
}
