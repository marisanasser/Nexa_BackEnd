<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(
            ['email' => 'admin@nexacreators.com.br'],
            [
                'name' => 'Admin Nexa',
                'password' => Hash::make('NexaAdmin@2025'),
                'role' => 'admin',
                'email_verified_at' => now(),
                'has_premium' => true,
            ]
        );

        $this->command->info("Admin user created/updated: {$admin->email}");
    }
}
