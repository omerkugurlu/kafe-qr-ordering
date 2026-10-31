<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt(['email' => strtolower($credentials['email']), 'password' => $credentials['password'], 'is_active' => true], $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'E-posta veya şifre hatalı.']);
        }

        $request->session()->regenerate();

        $user = $request->user();

        if ($user->role === User::ROLE_CUSTOMER) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Müşteri girişi yalnızca masanızdaki QR kod üzerinden yapılır.',
            ]);
        }

        $destination = match ($user->role) {
            User::ROLE_PLATFORM_ADMIN => route('platform.dashboard'),
            User::ROLE_WAITER => route('staff.orders'),
            User::ROLE_KITCHEN => route('staff.kitchen'),
            default => route('manage.dashboard'),
        };

        $intendedUrl = $request->session()->pull('url.intended');

        if ($this->canUseIntendedUrl($request, $user, $intendedUrl)) {
            return redirect()->to($intendedUrl);
        }

        return redirect()->to($destination);
    }

    private function canUseIntendedUrl(Request $request, User $user, mixed $url): bool
    {
        if (! is_string($url) || $url === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? $request->getHost()));
        if (! hash_equals(strtolower($request->getHost()), $host)) {
            return false;
        }

        $path = '/'.ltrim((string) ($parts['path'] ?? '/'), '/');
        $prefixes = match ($user->role) {
            User::ROLE_PLATFORM_ADMIN => ['/platform'],
            User::ROLE_CAFE_OWNER, User::ROLE_CAFE_MANAGER => ['/yonetim', '/garson'],
            User::ROLE_WAITER => ['/garson'],
            User::ROLE_KITCHEN => ['/mutfak', '/garson'],
            default => [],
        };

        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
