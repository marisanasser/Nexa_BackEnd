<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        \App\Models\User\User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('admin123'),
        ]);

        \App\Models\User\User::factory()->premium()->create([
            'name' => 'Premium User',
            'email' => 'premium@example.com',
            'password' => bcrypt('premium123'),
            'role' => 'brand',
        ]);

        \App\Models\User\User::factory()->trial()->create([
            'name' => 'Trial User',
            'email' => 'trial@example.com',
            'password' => bcrypt('trial123'),
        ]);

        \App\Models\User\User::factory()->studentVerified()->create([
            'name' => 'Student User',
            'email' => 'student@example.com',
            'password' => bcrypt('student123'),
        ]);

        \App\Models\User\User::factory(10)->create();

        $this->call([
            SubscriptionPlanSeeder::class,
            WithdrawalMethodSeeder::class,
            GuideSeeder::class,
            ReviewSeeder::class,
            CampaignSeeder::class,
        ]);
    }
}
