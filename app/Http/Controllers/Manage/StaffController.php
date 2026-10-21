<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
            'staff' => $cafe->users()->whereIn('role', [User::ROLE_CAFE_MANAGER, User::ROLE_WAITER])->with('branches')->orderBy('name')->get(),
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
            'role' => ['required', Rule::in([User::ROLE_CAFE_MANAGER, User::ROLE_WAITER])],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('cafe_id', $cafe->id)],
        ]);
        if ($validated['role'] === User::ROLE_WAITER && empty($validated['branch_ids'])) {
            return back()->withErrors(['branch_ids' => 'Garsona en az bir şube atayın.'])->withInput();
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

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->cafe_id === $request->user()->cafe_id && in_array($user->role, [User::ROLE_CAFE_MANAGER, User::ROLE_WAITER], true), 404);
        $cafe = $request->user()->cafe;
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
            'branch_ids' => ['nullable', 'array'],
            'branch_ids.*' => ['integer', Rule::exists('branches', 'id')->where('cafe_id', $cafe->id)],
        ]);
        if ($user->role === User::ROLE_WAITER && $request->boolean('is_active') && empty($validated['branch_ids'])) {
            return back()->withErrors(['branch_ids' => 'Etkin garsona en az bir şube atayın.']);
        }

        DB::transaction(function () use ($user, $validated, $request): void {
            $user->forceFill(['is_active' => (bool) $validated['is_active']])->save();
            $user->branches()->sync($validated['branch_ids'] ?? []);
            ActivityLog::query()->create([
                'cafe_id' => $user->cafe_id,
                'user_id' => $request->user()->id,
                'action' => 'staff.updated',
                'subject_type' => User::class,
                'subject_id' => $user->id,
                'metadata' => ['is_active' => $user->is_active, 'branch_ids' => $validated['branch_ids'] ?? []],
                'ip_address' => $request->ip(),
            ]);
        });

        return redirect()->route('manage.staff.index')->with('status', 'Çalışan bilgileri güncellendi.');
    }
}
