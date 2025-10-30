<?php

namespace Database\Seeders;
use Illuminate\Support\Facades\Log;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create subscription plans first
        $this->call([
            SubscriptionPlanSeeder::class,
            WithdrawalMethodSeeder::class,
            GuideSeeder::class,
            ReviewSeeder::class,
            CampaignSeeder::class,
        ]);

        // Create various types of users for testing
        \App\Models\User::factory(10)->create();

        // Create an admin user for testing
        \App\Models\User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('admin123'),
        ]);
        // Create a premium user
        \App\Models\User::factory()->premium()->create([
            'name' => 'Premium User',
            'email' => 'premium@example.com',
            'password' => bcrypt('premium123'),
            'role' => 'brand',
        ]);

        // Create a trial user
        \App\Models\User::factory()->trial()->create([
            'name' => 'Trial User',
            'email' => 'trial@example.com',
            'password' => bcrypt('trial123'),
        ]);

        // Create a verified student user
        \App\Models\User::factory()->studentVerified()->create([
            'name' => 'Student User',
            'email' => 'student@example.com',
            'password' => bcrypt('student123'),
        ]);
    }
}
