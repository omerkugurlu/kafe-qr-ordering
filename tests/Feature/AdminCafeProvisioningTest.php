<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\User;
use App\Services\SessionRevocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCafeProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_can_create_a_cafe_owner_and_initial_branch(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('platform.cafes.store'), [
                'name' => 'Kafe 34',
                'slug' => 'kafe-34',
                'owner_name' => 'Ayşe Yılmaz',
                'owner_email' => 'ayse@example.test',
                'owner_password' => 'Correct-Horse-42!',
                'initial_branch_name' => 'Merkez',
            ])
            ->assertRedirect(route('platform.dashboard'));

        $cafe = Cafe::query()->where('slug', 'kafe-34')->firstOrFail();
        $owner = User::query()->where('email', 'ayse@example.test')->firstOrFail();

        $this->assertSame($cafe->id, $owner->cafe_id);
        $this->assertSame(User::ROLE_CAFE_OWNER, $owner->role);
        $this->assertTrue(Hash::check('Correct-Horse-42!', $owner->password));
        $this->assertDatabaseHas('branches', [
            'cafe_id' => $cafe->id,
            'name' => 'Merkez',
        ]);
    }

    public function test_cafe_staff_cannot_access_platform_cafe_provisioning(): void
    {
        $staff = User::factory()->create([
            'role' => User::ROLE_WAITER,
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->get(route('platform.dashboard'))
            ->assertForbidden();
    }

    public function test_first_platform_administrator_is_created_interactively_with_hashed_password(): void
    {
        $this->artisan('app:create-platform-admin')
            ->expectsQuestion('Super admin email', 'root@example.test')
            ->expectsQuestion('Super admin name', 'Platform Admin')
            ->expectsQuestion('Password (at least 12 characters)', 'A-random-password-for-tests-2026!')
            ->expectsOutputToContain('Platform administrator root@example.test is ready.')
            ->assertSuccessful();

        $admin = User::query()->where('email', 'root@example.test')->firstOrFail();
        $this->assertSame(User::ROLE_PLATFORM_ADMIN, $admin->role);
        $this->assertNotSame('A-random-password-for-tests-2026!', $admin->password);
        $this->assertTrue(Hash::check('A-random-password-for-tests-2026!', $admin->password));
    }

    public function test_platform_admin_dashboard_shows_cafe_table_and_customer_totals(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => true,
        ]);
        $activeCafe = Cafe::query()->create([
            'name' => 'Aktif Kafe',
            'slug' => 'aktif-kafe',
            'status' => Cafe::STATUS_ACTIVE,
        ]);
        $suspendedCafe = Cafe::query()->create([
            'name' => 'Askıdaki Kafe',
            'slug' => 'askidaki-kafe',
            'status' => Cafe::STATUS_SUSPENDED,
        ]);
        $activeBranch = Branch::query()->create([
            'cafe_id' => $activeCafe->id,
            'name' => 'Merkez',
            'slug' => 'merkez',
            'is_active' => true,
        ]);
        $inactiveBranch = Branch::query()->create([
            'cafe_id' => $activeCafe->id,
            'name' => 'Kapalı Şube',
            'slug' => 'kapali',
            'is_active' => false,
        ]);
        $suspendedBranch = Branch::query()->create([
            'cafe_id' => $suspendedCafe->id,
            'name' => 'Askıdaki Şube',
            'slug' => 'askidaki',
            'is_active' => true,
        ]);

        DiningTable::createWithToken(['branch_id' => $activeBranch->id, 'name' => 'Masa 1', 'is_active' => true]);
        DiningTable::createWithToken(['branch_id' => $activeBranch->id, 'name' => 'Masa 2', 'is_active' => false]);
        DiningTable::createWithToken(['branch_id' => $inactiveBranch->id, 'name' => 'Masa 3', 'is_active' => true]);
        DiningTable::createWithToken(['branch_id' => $suspendedBranch->id, 'name' => 'Masa 4', 'is_active' => true]);

        User::factory()->create(['cafe_id' => $activeCafe->id, 'role' => User::ROLE_CUSTOMER, 'is_active' => true]);
        User::factory()->create(['cafe_id' => $activeCafe->id, 'role' => User::ROLE_CUSTOMER, 'is_active' => false]);
        User::factory()->create(['cafe_id' => $suspendedCafe->id, 'role' => User::ROLE_CUSTOMER, 'is_active' => true]);
        User::factory()->create(['cafe_id' => null, 'role' => User::ROLE_CUSTOMER, 'is_active' => true]);

        $this->actingAs($admin)
            ->get(route('platform.dashboard'))
            ->assertOk()
            ->assertViewHas('totalCafes', 2)
            ->assertViewHas('activeCafes', 1)
            ->assertViewHas('activeTables', 1)
            ->assertViewHas('activeCustomers', 3);
    }

    public function test_platform_admin_can_edit_cafe_details_and_owner_credentials(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => true,
        ]);
        $cafe = $this->createCafeWithOwner('kafe-34');
        $owner = $cafe->users()->where('role', User::ROLE_CAFE_OWNER)->firstOrFail();

        $this->actingAs($admin)
            ->patch(route('platform.cafes.update', $cafe), [
                'name' => 'Kafe 34 Yenilendi',
                'slug' => 'kafe-34-yeni',
                'status' => Cafe::STATUS_ACTIVE,
                'registration_policy' => Cafe::POLICY_REQUIRED,
                'brand_name' => 'K34',
                'loyalty_enabled' => true,
                'points_per_100' => 3,
            ])
            ->assertRedirect(route('platform.cafes.show', $cafe));

        $this->patch(route('platform.cafes.owner.update', $cafe), [
            'owner_name' => 'Yeni Kafe Sahibi',
            'owner_email' => 'owner-new@example.test',
            'owner_is_active' => true,
            'owner_password' => 'New-Owner-Password-2026!',
            'owner_password_confirmation' => 'New-Owner-Password-2026!',
        ])->assertRedirect(route('platform.cafes.show', $cafe));

        $cafe->refresh();
        $owner->refresh();
        $this->assertSame('Kafe 34 Yenilendi', $cafe->name);
        $this->assertSame('kafe-34-yeni', $cafe->slug);
        $this->assertSame(Cafe::POLICY_REQUIRED, $cafe->registration_policy);
        $this->assertSame(3, $cafe->points_per_100);
        $this->assertSame('Yeni Kafe Sahibi', $owner->name);
        $this->assertSame('owner-new@example.test', $owner->email);
        $this->assertTrue(Hash::check('New-Owner-Password-2026!', $owner->password));
        $this->assertDatabaseHas('activity_logs', ['action' => 'cafe.updated', 'cafe_id' => $cafe->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'cafe.owner_credentials_updated', 'cafe_id' => $cafe->id]);
    }

    public function test_platform_admin_cannot_reset_owner_password_with_a_short_password(): void
    {
        $admin = User::factory()->create([
            'role' => User::ROLE_PLATFORM_ADMIN,
            'is_active' => true,
        ]);
        $cafe = $this->createCafeWithOwner('short-password-cafe');
        $owner = $cafe->users()->where('role', User::ROLE_CAFE_OWNER)->firstOrFail();
        $originalHash = $owner->password;

        $this->actingAs($admin)
            ->from(route('platform.dashboard'))
            ->patch(route('platform.cafes.owner.update', $cafe), [
                'owner_name' => $owner->name,
                'owner_email' => $owner->email,
                'owner_is_active' => true,
                'owner_password' => 'short',
                'owner_password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('owner_password');

        $this->assertSame($originalHash, $owner->fresh()->password);
    }

    public function test_stale_owner_session_is_invalidated_after_password_hash_changes(): void
    {
        $ownerCafe = $this->createCafeWithOwner('session-reset-cafe');
        $owner = $ownerCafe->users()->where('role', User::ROLE_CAFE_OWNER)->firstOrFail();
        $ownerCafe->branches()->create(['name' => 'Merkez', 'slug' => 'merkez', 'is_active' => true]);

        $this->actingAs($owner)->get(route('manage.dashboard'))->assertOk();
        $previousPasswordHash = $owner->password;
        $owner->forceFill(['password' => 'Fresh-Owner-Password-2026!'])->save();

        $this->withSession(['password_hash_web' => $previousPasswordHash])
            ->actingAs($owner->fresh())
            ->get(route('manage.dashboard'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_disabled_owner_cannot_use_an_existing_authenticated_session(): void
    {
        $cafe = $this->createCafeWithOwner('disabled-owner-cafe');
        $cafe->branches()->create(['name' => 'Merkez', 'slug' => 'merkez', 'is_active' => true]);
        $owner = $cafe->users()->where('role', User::ROLE_CAFE_OWNER)->firstOrFail();

        $this->actingAs($owner->forceFill(['is_active' => false]))
            ->get(route('manage.dashboard'))
            ->assertForbidden();
    }

    public function test_password_reset_revokes_every_database_session_for_that_user(): void
    {
        config(['session.driver' => 'database']);
        $cafe = $this->createCafeWithOwner('session-revoke-cafe');
        $owner = $cafe->users()->where('role', User::ROLE_CAFE_OWNER)->firstOrFail();
        DB::table('sessions')->insert([
            'id' => 'stale-owner-session',
            'user_id' => $owner->id,
            'ip_address' => null,
            'user_agent' => null,
            'payload' => 'obsolete session data',
            'last_activity' => now()->timestamp,
        ]);

        app(SessionRevocationService::class)->revokeDatabaseSessions($owner);

        $this->assertDatabaseMissing('sessions', ['id' => 'stale-owner-session']);
    }

    private function createCafeWithOwner(string $slug): Cafe
    {
        $cafe = Cafe::query()->create([
            'name' => 'Test Cafe',
            'slug' => $slug,
            'status' => Cafe::STATUS_ACTIVE,
        ]);
        $cafe->users()->create([
            'name' => 'Cafe Owner',
            'email' => "{$slug}@example.test",
            'password' => 'Original-Owner-Password-2026!',
            'role' => User::ROLE_CAFE_OWNER,
            'is_active' => true,
        ]);

        return $cafe;
    }
}
