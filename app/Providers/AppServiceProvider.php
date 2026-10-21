<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('qr-menu', fn (Request $request) => Limit::perMinute(90)->by($request->ip()));
        RateLimiter::for('qr-order', fn (Request $request) => Limit::perMinute(8)->by($request->route('token').'|'.$request->ip()));
        RateLimiter::for('admin-write', fn (Request $request) => Limit::perMinute(20)->by((string) $request->user()?->getAuthIdentifier()));
        RateLimiter::for('customer-poll', fn (Request $request) => Limit::perMinute(120)->by((string) $request->route('token').'|'.$request->ip()));
        RateLimiter::for('staff-poll', fn (Request $request) => Limit::perMinute(240)->by((string) $request->user()?->getAuthIdentifier()));
        RateLimiter::for('service-request', fn (Request $request) => Limit::perMinute(3)->by((string) $request->route('token').'|'.$request->ip()));
        RateLimiter::for('coupon-check', fn (Request $request) => Limit::perMinute(20)->by((string) $request->route('token').'|'.$request->ip()));
    }
}
