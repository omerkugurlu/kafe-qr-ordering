<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\Cafe;
use App\Models\User;

class BranchPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole(User::ROLE_PLATFORM_ADMIN, User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER, User::ROLE_WAITER);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Branch $branch): bool
    {
        return $this->belongsToCafeOrAssigned($user, $branch);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasRole(User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER)
            && $user->cafe?->status === Cafe::STATUS_ACTIVE;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Branch $branch): bool
    {
        return $user->hasRole(User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER)
            && $user->cafe_id === $branch->cafe_id
            && $branch->cafe?->status === Cafe::STATUS_ACTIVE;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Branch $branch): bool
    {
        return $user->hasRole(User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER)
            && $user->cafe_id === $branch->cafe_id
            && $branch->cafe?->status === Cafe::STATUS_ACTIVE;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Branch $branch): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Branch $branch): bool
    {
        return false;
    }

    private function belongsToCafeOrAssigned(User $user, Branch $branch): bool
    {
        if ($user->role === User::ROLE_PLATFORM_ADMIN) {
            return $user->isPlatformAdmin();
        }

        if ($user->cafe_id !== $branch->cafe_id || $branch->cafe?->status !== Cafe::STATUS_ACTIVE) {
            return false;
        }

        if ($user->hasRole(User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER)) {
            return true;
        }

        return $user->hasRole(User::ROLE_WAITER) && $user->branches()->whereKey($branch->id)->exists();
    }
}
