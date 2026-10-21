<?php

namespace App\Http\Middleware;

use App\Models\Cafe;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->is_active && $user->hasRole(...$roles), 403);

        if ($user->role !== User::ROLE_PLATFORM_ADMIN) {
            abort_unless($user->cafe?->status === Cafe::STATUS_ACTIVE, 403);
        }

        return $next($request);
    }
}
