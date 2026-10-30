<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $cafe = $request->user()?->cafe;
        abort_unless($cafe !== null, 404);
        app(SubscriptionService::class)->requireFeature($cafe, $feature);

        return $next($request);
    }
}
