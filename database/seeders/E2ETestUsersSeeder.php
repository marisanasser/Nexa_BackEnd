<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder for creating E2E test users.
 *
 * These users are specifically for Playwright E2E tests.
 * Run with: php artisan db:seed --class=E2ETestUsersSeeder
 */
class E2ETestUsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Brand test user
        User::updateOrCreate(
            ['email' => 'brand-e2e@nexa.test'],
            [
                'name' => 'Marca Teste E2E',
                'password' => Hash::make('Test@123456'),
                'role' => 'brand',
                'email_verified_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        // Creator test user
        User::updateOrCreate(
            ['email' => 'creator-e2e@nexa.test'],
            [
                'name' => 'Criador Teste E2E',
                'password' => Hash::make('Test@123456'),
                'role' => 'creator',
                'email_verified_at' => now(),
                'email_verified_at' => now(),
            ]
        );

        // Admin test user
        User::updateOrCreate(
            ['email' => 'admin-e2e@nexa.test'],
            [
                'name' => 'Admin Teste E2E',
                'password' => Hash::make('Admin@123456'),
                'role' => 'admin',
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('E2E test users created successfully!');
        $this->command->table(
            ['Email', 'Role', 'Password'],
            [
                ['brand-e2e@nexa.test', 'brand', 'Test@123456'],
                ['creator-e2e@nexa.test', 'creator', 'Test@123456'],
                ['admin-e2e@nexa.test', 'admin', 'Admin@123456'],
            ]
        );
    }
}
