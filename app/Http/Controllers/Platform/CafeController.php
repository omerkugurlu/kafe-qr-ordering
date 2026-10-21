<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Cafe;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CafeController extends Controller
{
    public function index(): View
    {
        return view('platform.dashboard', [
            'cafes' => Cafe::query()->withCount(['branches', 'users'])->latest()->paginate(20),
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
}
