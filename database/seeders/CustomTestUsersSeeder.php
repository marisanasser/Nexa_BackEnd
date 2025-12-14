<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CustomTestUsersSeeder extends Seeder
{
    public function run(): void
    {
        $brand = User::firstOrCreate(
            ['email' => 'brand_test@nexa.com'],
            [
                'name' => 'Brand Test',
                'password' => Hash::make('password'),
                'role' => 'brand',
                'email_verified_at' => now(),
            ]
        );

        $creator = User::firstOrCreate(
            ['email' => 'creator_test@nexa.com'],
            [
                'name' => 'Creator Test',
                'password' => Hash::make('password'),
                'role' => 'creator',
                'email_verified_at' => now(),
            ]
        );

        $creator->update([
            'has_premium' => true,
            'premium_expires_at' => now()->addMonths(12),
        ]);

        $this->command->info("User Brand: {$brand->email} created.");
        $this->command->info("User Creator: {$creator->email} created.");
    }
}
