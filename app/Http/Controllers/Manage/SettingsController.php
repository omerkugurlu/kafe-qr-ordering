<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Cafe;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);
        $validated = $request->validate([
            'registration_policy' => ['required', Rule::in([Cafe::POLICY_GUEST, Cafe::POLICY_OPTIONAL, Cafe::POLICY_REQUIRED])],
            'loyalty_enabled' => ['sometimes', 'boolean'],
            'points_per_100' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $cafe->fill([
            'registration_policy' => $validated['registration_policy'],
            'loyalty_enabled' => $request->boolean('loyalty_enabled'),
            'points_per_100' => $validated['points_per_100'],
        ])->save();

        ActivityLog::query()->create([
            'cafe_id' => $cafe->id,
            'user_id' => $request->user()->id,
            'action' => 'cafe.settings_updated',
            'subject_type' => Cafe::class,
            'subject_id' => $cafe->id,
            'metadata' => ['registration_policy' => $cafe->registration_policy, 'loyalty_enabled' => $cafe->loyalty_enabled],
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('manage.dashboard')->with('status', 'Kafe ayarları güncellendi.');
    }
}
