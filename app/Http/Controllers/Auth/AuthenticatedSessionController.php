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
        $destination = match ($user->role) {
            User::ROLE_PLATFORM_ADMIN => route('platform.dashboard'),
            User::ROLE_WAITER => route('staff.orders'),
            User::ROLE_CUSTOMER => route('home'),
            default => route('manage.dashboard'),
        };

        return redirect()->intended($destination);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
