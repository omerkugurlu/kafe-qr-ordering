<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\SessionRevocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CafeController extends Controller
{
    public function index(): View
    {
        $totalCafes = Cafe::query()->count();
        $activeCafes = Cafe::query()->where('status', Cafe::STATUS_ACTIVE)->count();
        $activeTables = DiningTable::query()
            ->where('is_active', true)
            ->whereHas('branch', fn ($query) => $query->where('is_active', true)
                ->whereHas('cafe', fn ($cafeQuery) => $cafeQuery->where('status', Cafe::STATUS_ACTIVE)))
            ->count();
        $activeCustomers = User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->where('is_active', true)
            ->whereHas('cafe', fn ($query) => $query->where('status', Cafe::STATUS_ACTIVE))
            ->count();

        return view('platform.dashboard', [
            'cafes' => Cafe::query()
                ->with(['users' => fn ($query) => $query->where('role', User::ROLE_CAFE_OWNER)])
                ->withCount(['branches', 'users'])
                ->latest()
                ->paginate(20),
            'totalCafes' => $totalCafes,
            'activeCafes' => $activeCafes,
            'activeTables' => $activeTables,
            'activeCustomers' => $activeCustomers,
        ]);
    }

    public function show(Cafe $cafe): View
    {
        $cafe->load([
            'branches' => fn ($query) => $query
                ->withCount(['diningTables', 'orders', 'tableSessions'])
                ->orderBy('name'),
            'users' => fn ($query) => $query
                ->whereIn('role', [User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER, User::ROLE_WAITER, User::ROLE_KITCHEN])
                ->orderBy('role')
                ->orderBy('name'),
        ]);

        $todayRevenue = Payment::query()
            ->whereNull('voided_at')
            ->whereDate('recorded_at', today())
            ->whereHas('tableSession.branch', fn ($query) => $query->where('cafe_id', $cafe->id))
            ->sum('amount');

        return view('platform.cafe', [
            'cafe' => $cafe,
            'owner' => $cafe->users->firstWhere('role', User::ROLE_CAFE_OWNER),
            'todayOrders' => Order::query()
                ->whereHas('branch', fn ($query) => $query->where('cafe_id', $cafe->id))
                ->whereDate('created_at', today())
                ->count(),
            'todayRevenue' => (int) round(((float) $todayRevenue) * 100),
            'customerCount' => Order::query()
                ->whereNotNull('customer_id')
                ->whereHas('branch', fn ($query) => $query->where('cafe_id', $cafe->id))
                ->distinct()
                ->count('customer_id'),
            'recentLogs' => ActivityLog::query()
                ->where('cafe_id', $cafe->id)
                ->latest()
                ->limit(8)
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:120', Rule::unique('cafes', 'slug')],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'owner_password' => ['required', 'string', 'min:12', 'max:255'],
            'initial_branch_name' => ['required', 'string', 'max:120'],
        ]);

        $cafe = DB::transaction(function () use ($validated, $request): Cafe {
            $cafe = Cafe::query()->create([
                'name' => $validated['name'],
                'slug' => strtolower($validated['slug']),
                'status' => Cafe::STATUS_ACTIVE,
                'registration_policy' => Cafe::POLICY_OPTIONAL,
            ]);

            $owner = $cafe->users()->create([
                'name' => $validated['owner_name'],
                'email' => strtolower($validated['owner_email']),
                'password' => $validated['owner_password'],
                'role' => User::ROLE_CAFE_OWNER,
                'is_active' => true,
            ]);

            $cafe->branches()->create([
                'name' => $validated['initial_branch_name'],
                'slug' => 'merkez',
                'is_active' => true,
            ]);

            ActivityLog::query()->create([
                'user_id' => $request->user()->id,
                'cafe_id' => $cafe->id,
                'action' => 'cafe.created',
                'subject_type' => Cafe::class,
                'subject_id' => $cafe->id,
                'metadata' => ['owner_user_id' => $owner->id],
                'ip_address' => $request->ip(),
            ]);

            return $cafe;
        });

        return redirect()->route('platform.dashboard')->with('status', "{$cafe->name} oluşturuldu.");
    }

    public function toggleStatus(Request $request, Cafe $cafe): RedirectResponse
    {
        $next = $cafe->status === Cafe::STATUS_ACTIVE ? Cafe::STATUS_SUSPENDED : Cafe::STATUS_ACTIVE;
        $cafe->forceFill(['status' => $next])->save();
        ActivityLog::query()->create([
            'user_id' => $request->user()->id,
            'cafe_id' => $cafe->id,
            'action' => 'cafe.status_changed',
            'subject_type' => Cafe::class,
            'subject_id' => $cafe->id,
            'metadata' => ['status' => $next],
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('platform.dashboard')->with('status', $cafe->name.' '.($next === Cafe::STATUS_ACTIVE ? 'etkinleştirildi.' : 'askıya alındı.'));
    }

    public function update(Request $request, Cafe $cafe): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'alpha_dash', 'max:120', Rule::unique('cafes', 'slug')->ignore($cafe->id)],
            'status' => ['required', Rule::in([Cafe::STATUS_ACTIVE, Cafe::STATUS_SUSPENDED])],
            'registration_policy' => ['required', Rule::in([Cafe::POLICY_GUEST, Cafe::POLICY_OPTIONAL, Cafe::POLICY_REQUIRED])],
            'brand_name' => ['nullable', 'string', 'max:120'],
            'loyalty_enabled' => ['required', 'boolean'],
            'points_per_100' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);

        DB::transaction(function () use ($cafe, $validated, $request): void {
            $lockedCafe = Cafe::query()->whereKey($cafe->id)->lockForUpdate()->firstOrFail();
            $lockedCafe->forceFill($validated)->save();

            ActivityLog::query()->create([
                'user_id' => $request->user()->id,
                'cafe_id' => $lockedCafe->id,
                'action' => 'cafe.updated',
                'subject_type' => Cafe::class,
                'subject_id' => $lockedCafe->id,
                'metadata' => [
                    'slug' => $lockedCafe->slug,
                    'status' => $lockedCafe->status,
                    'registration_policy' => $lockedCafe->registration_policy,
                    'loyalty_enabled' => $lockedCafe->loyalty_enabled,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('platform.cafes.show', $cafe)->with('status', 'Kafe bilgileri güncellendi.');
    }

    public function updateOwner(Request $request, Cafe $cafe, SessionRevocationService $sessions): RedirectResponse
    {
        $owner = $cafe->users()->where('role', User::ROLE_CAFE_OWNER)->firstOrFail();
        $validated = $request->validate([
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($owner->id)],
            'owner_is_active' => ['required', 'boolean'],
            'owner_password' => ['nullable', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        DB::transaction(function () use ($cafe, $owner, $validated, $request, $sessions): void {
            $lockedCafe = Cafe::query()->whereKey($cafe->id)->lockForUpdate()->firstOrFail();
            $lockedOwner = $lockedCafe->users()->whereKey($owner->id)->where('role', User::ROLE_CAFE_OWNER)->lockForUpdate()->firstOrFail();
            $passwordReset = filled($validated['owner_password'] ?? null);
            $attributes = [
                'name' => $validated['owner_name'],
                'email' => strtolower($validated['owner_email']),
                'is_active' => (bool) $validated['owner_is_active'],
            ];

            if ($passwordReset) {
                $attributes['password'] = $validated['owner_password'];
                $lockedOwner->setRememberToken(null);
            }

            $lockedOwner->forceFill($attributes)->save();
            if ($passwordReset) {
                $sessions->revokeDatabaseSessions($lockedOwner);
            }

            ActivityLog::query()->create([
                'user_id' => $request->user()->id,
                'cafe_id' => $lockedCafe->id,
                'action' => 'cafe.owner_credentials_updated',
                'subject_type' => User::class,
                'subject_id' => $lockedOwner->id,
                'metadata' => [
                    'email' => $lockedOwner->email,
                    'is_active' => $lockedOwner->is_active,
                    'password_reset' => $passwordReset,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('platform.cafes.show', $cafe)->with('status', 'Kafe sahibi giriş bilgileri güncellendi.');
    }
}
