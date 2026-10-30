<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cafe;
use App\Models\CafeSubscription;
use App\Models\CafeSubscriptionRequest;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SubscriptionManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_request_a_yearly_plan_and_admin_can_activate_after_payment_confirmation(): void
    {
        [$cafe, $owner] = $this->createCafeAndOwner();
        $plan = SubscriptionPlan::query()->where('code', 'orta')->firstOrFail();

        $this->actingAs($owner)
            ->get(route('manage.subscription.index'))
            ->assertOk()
            ->assertSee('İşletmene uygun planı seç');

        $this->actingAs($owner)
            ->post(route('manage.subscription.request'), ['plan_id' => $plan->id])
            ->assertSessionHasNoErrors();

        $request = CafeSubscriptionRequest::query()->where('cafe_id', $cafe->id)->firstOrFail();
        $this->assertSame('pending', $request->status);
        $this->assertSame($plan->name, $request->plan_name_snapshot);

        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN, 'cafe_id' => null]);
        $this->actingAs($admin)
            ->post(route('platform.subscriptions.requests.approve', $request), ['payment_confirmed' => '1'])
            ->assertSessionHasNoErrors();

        $request->refresh();
        $subscription = CafeSubscription::query()->where('cafe_id', $cafe->id)->firstOrFail();
        $this->assertSame('approved', $request->status);
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->ends_at->isFuture());
        $this->assertTrue(app(SubscriptionService::class)->featureEnabled($cafe, 'loyalty'));
    }

    public function test_platform_admin_can_edit_plan_price_limits_and_features(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_PLATFORM_ADMIN, 'cafe_id' => null]);
        $plan = SubscriptionPlan::query()->where('code', 'giris')->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('platform.subscriptions.plans.update', $plan), [
                'name' => 'Başlangıç',
                'annual_price_tl' => '12500.50',
                'max_tables' => 12,
                'max_branches' => 3,
                'is_active' => '1',
                'features' => ['coupons' => '1', 'reports' => '1'],
            ])
            ->assertSessionHasNoErrors();

        $plan->refresh();
        $this->assertSame('Başlangıç', $plan->name);
        $this->assertSame(1250050, $plan->annual_price_cents);
        $this->assertSame(12, $plan->max_tables);
        $this->assertSame(3, $plan->max_branches);
        $this->assertTrue($plan->features_json['coupons']);
        $this->assertFalse($plan->features_json['customer_accounts']);
    }

    public function test_unassigned_new_cafes_start_with_entry_plan_limits_and_no_paid_features(): void
    {
        $cafe = Cafe::query()->forceCreate([
            'name' => 'Yeni Kafe',
            'slug' => 'yeni-kafe',
            'subscription_legacy_unrestricted' => false,
        ]);
        $subscriptions = app(SubscriptionService::class);

        $this->assertTrue($subscriptions->serviceEnabled($cafe));
        $this->assertFalse($subscriptions->featureEnabled($cafe, 'coupons'));

        Branch::query()->create([
            'cafe_id' => $cafe->id,
            'name' => 'Şube 1',
            'slug' => 'sube-1',
            'uses_shared_menu' => true,
            'is_active' => true,
        ]);
        Branch::query()->create([
            'cafe_id' => $cafe->id,
            'name' => 'Şube 2',
            'slug' => 'sube-2',
            'uses_shared_menu' => true,
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);
        $subscriptions->requireCapacity($cafe, 'branches');
    }

    /** @return array{Cafe, User} */
    private function createCafeAndOwner(): array
    {
        $cafe = Cafe::query()->create(['name' => 'Kıyı Kahve', 'slug' => 'kiyi-kahve']);
        Branch::query()->create([
            'cafe_id' => $cafe->id,
            'name' => 'Merkez',
            'slug' => 'merkez',
            'uses_shared_menu' => true,
            'is_active' => true,
        ]);
        $owner = User::factory()->create([
            'cafe_id' => $cafe->id,
            'role' => User::ROLE_CAFE_OWNER,
            'is_active' => true,
        ]);

        return [$cafe, $owner];
    }
}
