<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RegisteredCustomerController extends Controller
{
    public function create(string $token): View
    {
        [$cafe] = $this->tableAndCafe($token);
        abort_if($cafe->registration_policy === Cafe::POLICY_GUEST, 404);

        return view('auth.register-customer', compact('cafe', 'token'));
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        [$cafe] = $this->tableAndCafe($token);
        abort_if($cafe->registration_policy === Cafe::POLICY_GUEST, 404);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'max:255'],
        ]);

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => Str::lower($validated['email']),
            'password' => $validated['password'],
            'role' => User::ROLE_CUSTOMER,
            'cafe_id' => null,
            'is_active' => true,
        ]);
        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('customer.menu', $token)->with('status', 'Hesabınız oluşturuldu. Siparişlerinize devam edebilirsiniz.');
    }

    /** @return array{Cafe, DiningTable} */
    private function tableAndCafe(string $token): array
    {
        $table = DiningTable::query()->where('public_token_hash', hash('sha256', $token))->with('branch.cafe')->firstOrFail();
        abort_unless($table->is_active && $table->branch->is_active && $table->branch->cafe->status === Cafe::STATUS_ACTIVE, 404);

        return [$table->branch->cafe, $table];
    }
}
