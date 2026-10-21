<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Cafe;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class KitchenController extends Controller
{
    public function index(Request $request, ?Branch $branch = null): View
    {
        $branches = $this->branchesFor($request->user());

        if ($branch === null) {
            if ($branches->isEmpty()) {
                return view('staff.no-branch');
            }

            $branch = $branches->firstWhere('id', (int) $request->query('sube')) ?? $branches->first();
        }

        Gate::authorize('view', $branch);
        $isManager = $request->user()->hasRole(User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER);

        return view('staff.kitchen', [
            'branch' => $branch,
            'branches' => $branches,
            'dataEndpoint' => $isManager
                ? route('manage.orders.data', $branch)
                : route('staff.board.data', $branch),
            'statusUrlTemplate' => $isManager
                ? route('manage.orders.status', ['order' => '__ID__'])
                : route('staff.orders.status', ['order' => '__ID__']),
        ]);
    }

    /** @return Collection<int, Branch> */
    private function branchesFor(?User $user): Collection
    {
        if ($user === null) {
            return collect();
        }

        $query = Branch::query()
            ->with('cafe')
            ->where('is_active', true)
            ->whereHas('cafe', fn ($cafeQuery) => $cafeQuery->where('status', Cafe::STATUS_ACTIVE))
            ->orderBy('name');

        if ($user->role === User::ROLE_PLATFORM_ADMIN) {
            return $query->get();
        }

        if ($user->hasRole(User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER)) {
            return $query->where('cafe_id', $user->cafe_id)->get();
        }

        return $query->whereIn('branches.id', $user->branches()->select('branches.id'))->get();
    }
}
