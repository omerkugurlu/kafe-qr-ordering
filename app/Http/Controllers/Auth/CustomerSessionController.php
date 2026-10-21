<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerSessionController extends Controller
{
    public function create(string $token): View
    {
        [$cafe] = $this->tableAndCafe($token);
        abort_if($cafe->registration_policy === Cafe::POLICY_GUEST, 404);

        return view('auth.customer-login', compact('cafe', 'token'));
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        [$cafe] = $this->tableAndCafe($token);
        abort_if($cafe->registration_policy === Cafe::POLICY_GUEST, 404);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $authenticated = Auth::attempt([
            'email' => Str::lower($credentials['email']),
            'password' => $credentials['password'],
            'role' => User::ROLE_CUSTOMER,
            'is_active' => true,
        ], $request->boolean('remember'));

        if (! $authenticated || $request->user()?->role !== User::ROLE_CUSTOMER) {
            throw ValidationException::withMessages([
                'email' => 'E-posta veya şifre hatalı. Müşteri hesabınız yoksa hesap oluşturabilirsiniz.',
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('customer.menu', $token));
    }

    /** @return array{Cafe, DiningTable} */
    private function tableAndCafe(string $token): array
    {
        $table = DiningTable::query()
            ->where('public_token_hash', hash('sha256', $token))
            ->with('branch.cafe')
            ->firstOrFail();

        abort_unless(
            $table->is_active
            && $table->branch?->is_active
            && $table->branch?->cafe?->status === Cafe::STATUS_ACTIVE,
            404,
        );

        return [$table->branch->cafe, $table];
    }
}
