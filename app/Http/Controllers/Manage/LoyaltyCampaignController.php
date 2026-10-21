<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Cafe;
use App\Models\LoyaltyCampaign;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoyaltyCampaignController extends Controller
{
    public function index(Request $request): View
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);

        return view('manage.loyalty', [
            'cafe' => $cafe,
            'campaigns' => $cafe->loyaltyCampaigns()->withCount('transactions')->latest()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $cafe = $request->user()->cafe;
        abort_unless($cafe !== null, 404);
        $validated = $request->validate($this->rules());
        $this->validateRewardValue($request, $validated);
        if ($validated['type'] === LoyaltyCampaign::TYPE_POINTS && empty($validated['points_required'])) {
            $validated['points_required'] = $validated['threshold'];
        }
        $validated['is_active'] = $request->boolean('is_active', true);
        $campaign = $cafe->loyaltyCampaigns()->create($validated);
        $this->log($request, $cafe, $campaign, 'loyalty.campaign_created');

        return redirect()->route('manage.loyalty.index')->with('status', 'Sadakat kampanyası eklendi.');
    }

    public function update(Request $request, LoyaltyCampaign $campaign): RedirectResponse
    {
        abort_unless($campaign->cafe_id === $request->user()->cafe_id, 404);
        $validated = $request->validate($this->rules());
        $this->validateRewardValue($request, $validated);
        if ($validated['type'] === LoyaltyCampaign::TYPE_POINTS && empty($validated['points_required'])) {
            $validated['points_required'] = $validated['threshold'];
        }
        $validated['is_active'] = $request->boolean('is_active');
        $campaign->fill($validated)->save();
        $this->log($request, $cafe, $campaign, 'loyalty.campaign_updated');

        return redirect()->route('manage.loyalty.index')->with('status', 'Sadakat kampanyası güncellendi.');
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in([LoyaltyCampaign::TYPE_ORDER_COUNT, LoyaltyCampaign::TYPE_POINTS])],
            'threshold' => ['required', 'integer', 'min:1', 'max:100000'],
            'points_required' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'reward_type' => ['required', Rule::in([LoyaltyCampaign::REWARD_PERCENT, LoyaltyCampaign::REWARD_FIXED])],
            'reward_value' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,2'],
            'minimum_order_total' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function validateRewardValue(Request $request, array $validated): void
    {
        if ($validated['reward_type'] === LoyaltyCampaign::REWARD_PERCENT && (float) $validated['reward_value'] > 100) {
            throw ValidationException::withMessages(['reward_value' => 'Yüzde ödül en fazla 100 olabilir.']);
        }
    }

    private function log(Request $request, Cafe $cafe, LoyaltyCampaign $campaign, string $action): void
    {
        ActivityLog::query()->create([
            'cafe_id' => $cafe->id,
            'user_id' => $request->user()->id,
            'action' => $action,
            'subject_type' => LoyaltyCampaign::class,
            'subject_id' => $campaign->id,
            'metadata' => ['name' => $campaign->name, 'active' => $campaign->is_active],
            'ip_address' => $request->ip(),
        ]);
    }
}
