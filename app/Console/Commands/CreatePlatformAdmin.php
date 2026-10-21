<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('app:create-platform-admin')]
#[Description('Creates or updates the platform administrator using interactive input.')]
class CreatePlatformAdmin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = Str::lower((string) $this->ask('Super admin email'));
        $name = (string) $this->ask('Super admin name');
        $password = (string) $this->secret('Password (at least 12 characters)');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($name) < 2 || mb_strlen($password) < 12) {
            $this->components->error('Valid email, name, and a password of at least 12 characters are required.');

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password), 'role' => User::ROLE_PLATFORM_ADMIN, 'cafe_id' => null, 'is_active' => true],
        );

        $this->components->info("Platform administrator {$user->email} is ready.");

        return self::SUCCESS;
    }
}
