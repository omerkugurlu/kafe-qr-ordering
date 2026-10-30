<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\SessionRevocationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(Request $request): View
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);

        return view('manage.staff', [
            'cafe' => $cafe,
            'branches' => $cafe->branches()->where('is_active', true)->orderBy('name')->get(),
            'staff' => $cafe->users()->whereIn('role', [User::ROLE_CAFE_MANAGER, User::ROLE_WAITER, User::ROLE_KITCHEN])->with('branches')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'max:255'],
            'role' => ['required', Rule::in([User::ROLE_CAFE_MANAGER, User::ROLE_WAITER, User::ROLE_KITCHEN])],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('cafe_id', $cafe->id)],
        ]);
        if (in_array($validated['role'], [User::ROLE_WAITER, User::ROLE_KITCHEN], true) && empty($validated['branch_ids'])) {
            return back()->withErrors(['branch_ids' => 'Garson veya mutfak kullanıcısına en az bir şube atayın.'])->withInput();
        }

        $user = DB::transaction(function () use ($cafe, $validated, $request): User {
            $user = $cafe->users()->create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'is_active' => true,
            ]);
            $user->branches()->sync($validated['branch_ids'] ?? []);
            ActivityLog::query()->create([
                'cafe_id' => $cafe->id,
                'user_id' => $request->user()->id,
                'action' => 'staff.created',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'metadata' => ['role' => $user->role, 'branch_ids' => $validated['branch_ids'] ?? []],
                'ip_address' => $request->ip(),
            ]);

            return $user;
        });

        return redirect()->route('manage.staff.index')->with('status', $user->name.' için çalışan hesabı oluşturuldu.');
    }

    public function update(Request $request, User $user, SessionRevocationService $sessions): RedirectResponse
    {
        abort_unless($user->cafe_id === $request->user()->cafe_id && in_array($user->role, [User::ROLE_CAFE_MANAGER, User::ROLE_WAITER, User::ROLE_KITCHEN], true), 404);
        $cafe = $request->user()->cafe;
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:12', 'max:255'],
            'role' => ['required', Rule::in([User::ROLE_CAFE_MANAGER, User::ROLE_WAITER, User::ROLE_KITCHEN])],
            'is_active' => ['required', 'boolean'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('cafe_id', $cafe->id)],
        ]);
        if (in_array($validated['role'], [User::ROLE_WAITER, User::ROLE_KITCHEN], true) && (bool) $validated['is_active'] && empty($validated['branch_ids'])) {
            return back()->withErrors(['branch_ids' => 'Etkin garson veya mutfak kullanıcısına en az bir şube atayın.'])->withInput();
        }

        $email = Str::lower($validated['email']);
        $branchIds = collect($validated['branch_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        $currentBranchIds = $user->branches()->pluck('branches.id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $passwordChanged = filled($validated['password'] ?? null);
        $emailChanged = $email !== Str::lower($user->email);
        $accessChanged = $user->role !== $validated['role']
            || $user->is_active !== (bool) $validated['is_active']
            || $branchIds !== $currentBranchIds;

        DB::transaction(function () use ($user, $validated, $request, $sessions, $email, $branchIds, $passwordChanged, $emailChanged, $accessChanged): void {
            $changes = [
                'name' => trim($validated['name']),
                'email' => $email,
                'role' => $validated['role'],
                'is_active' => (bool) $validated['is_active'],
            ];
            if ($passwordChanged) {
                $changes['password'] = Hash::make($validated['password']);
            }
            if ($passwordChanged || $emailChanged || $accessChanged) {
                $changes['remember_token'] = Str::random(60);
            }

            $user->forceFill($changes)->save();
            $user->branches()->sync($branchIds);
            if ($passwordChanged || $emailChanged || $accessChanged) {
                $sessions->revokeDatabaseSessions($user);
            }

            ActivityLog::query()->create([
                'cafe_id' => $user->cafe_id,
                'user_id' => $request->user()->id,
                'action' => 'staff.updated',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'metadata' => [
                    'role' => $user->role,
                    'is_active' => $user->is_active,
                    'branch_ids' => $branchIds,
                    'email_changed' => $emailChanged,
                    'password_changed' => $passwordChanged,
                ],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('manage.staff.index')->with('status', 'Çalışan bilgileri güncellendi.');
    }
}
