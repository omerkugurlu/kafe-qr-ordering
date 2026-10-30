<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Cafe;
use App\Models\DiningTable;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

final class PlaywrightE2ESeeder extends Seeder
{
    public function run(): void
    {
        $databasePath = (string) config('database.connections.sqlite.database');
        $expectedDatabase = (string) env('LARAVEL_E2E_DB_PATH');

        if (
            ! app()->environment('testing')
            || config('database.default') !== 'sqlite'
            || $expectedDatabase === ''
            || realpath($databasePath) !== realpath($expectedDatabase)
            || dirname($expectedDatabase) !== base_path()
            || ! preg_match('/^\.local-laravel-e2e-[0-9]+-[a-f0-9]+\.sqlite$/', basename($expectedDatabase))
        ) {
            throw new RuntimeException('Playwright seeder requires the dedicated local Laravel E2E SQLite database.');
        }

        $qrToken = (string) env('LARAVEL_E2E_QR_TOKEN');
        $waiterEmail = (string) env('LARAVEL_E2E_WAITER_EMAIL');
        $waiterPassword = (string) env('LARAVEL_E2E_WAITER_PASSWORD');

        if ($qrToken === '' || $waiterEmail === '' || $waiterPassword === '') {
            throw new RuntimeException('Playwright E2E credentials and QR token must be supplied by the local test runner.');
        }

        $cafe = Cafe::query()->create([
            'name' => 'Playwright Kafe',
            'slug' => 'playwright-kafe',
            'status' => Cafe::STATUS_ACTIVE,
            'registration_policy' => Cafe::POLICY_OPTIONAL,
        ]);
        $branch = $cafe->branches()->create([
            'name' => 'Test Şube',
            'slug' => 'test-sube',
            'uses_shared_menu' => true,
            'is_active' => true,
        ]);
        $category = $cafe->categories()->create([
            'name' => 'Kahveler',
            'is_active' => true,
        ]);
        $cafe->products()->create([
            'category_id' => $category->id,
            'name' => 'E2E Filtre Kahve',
            'price' => '250.00',
            'is_available' => true,
        ]);

        $table = DiningTable::query()->create([
            'branch_id' => $branch->id,
            'name' => 'Masa E2E',
            'public_token_hash' => hash('sha256', $qrToken),
            'public_token_encrypted' => $qrToken,
            'is_active' => true,
        ]);

        $waiter = User::factory()->create([
            'name' => 'E2E Garson',
            'email' => $waiterEmail,
            'password' => $waiterPassword,
            'role' => User::ROLE_WAITER,
            'cafe_id' => $cafe->id,
            'is_active' => true,
        ]);
        $branch->staff()->attach($waiter->id);
    }
}
