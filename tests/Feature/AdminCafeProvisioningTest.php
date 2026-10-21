<?php

namespace Tests\Feature;

use App\Models\Cafe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
