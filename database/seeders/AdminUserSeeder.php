<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $password = env('ADMIN_USER_PASSWORD');
        if (! is_string($password) || $password === '') {
            if (app()->environment('production')) {
                throw new RuntimeException('Environment variable ADMIN_USER_PASSWORD is required in production.');
            }

            $password = bin2hex(random_bytes(16)) . 'A1!';
        }

        $admin = User::updateOrCreate(
            ['email' => 'admin@nexacreators.com.br'],
            [
                'name' => 'Admin Nexa',
                'password' => Hash::make($password),
                'role' => 'admin',
                'email_verified_at' => now(),
                'has_premium' => true,
            ]
        );

        $this->command->info("Admin user created/updated: {$admin->email}");
    }
}
