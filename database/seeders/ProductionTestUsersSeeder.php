<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeder para criar usuários de teste em produção
 * Execute: php artisan db:seed --class=ProductionTestUsersSeeder
 */
class ProductionTestUsersSeeder extends Seeder
{
    public function run(): void
    {
        // Admin User
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
        $this->command->info("Admin user: {$admin->email}");

        // Brand Test User
        $brand = User::updateOrCreate(
            ['email' => 'brand.teste@nexacreators.com.br'],
            [
                'name' => 'Brand Teste Produção',
                'password' => Hash::make('BrandTeste@2025'),
                'role' => 'brand',
                'email_verified_at' => now(),
                'has_premium' => true,
                'premium_expires_at' => now()->addYear(),
            ]
        );
        $this->command->info("Brand user: {$brand->email}");

        // Creator Test User with Premium
        $creatorPremium = User::updateOrCreate(
            ['email' => 'creator.premium@nexacreators.com.br'],
            [
                'name' => 'Creator Premium Teste',
                'password' => Hash::make('CreatorPremium@2025'),
                'role' => 'creator',
                'email_verified_at' => now(),
                'has_premium' => true,
                'premium_expires_at' => now()->addYear(),
            ]
        );
        $this->command->info("Creator Premium user: {$creatorPremium->email}");

        // Creator Test User without Premium (to test premium flow)
        $creatorFree = User::updateOrCreate(
            ['email' => 'creator.free@nexacreators.com.br'],
            [
                'name' => 'Creator Free Teste',
                'password' => Hash::make('CreatorFree@2025'),
                'role' => 'creator',
                'email_verified_at' => now(),
                'has_premium' => false,
            ]
        );
        $this->command->info("Creator Free user: {$creatorFree->email}");

        $this->command->newLine();
        $this->command->info("=== Production Test Users Created ===");
        $this->command->table(
            ['Role', 'Email', 'Password', 'Premium'],
            [
                ['Admin', 'admin@nexacreators.com.br', 'NexaAdmin@2025', 'Yes'],
                ['Brand', 'brand.teste@nexacreators.com.br', 'BrandTeste@2025', 'Yes'],
                ['Creator', 'creator.premium@nexacreators.com.br', 'CreatorPremium@2025', 'Yes'],
                ['Creator', 'creator.free@nexacreators.com.br', 'CreatorFree@2025', 'No'],
            ]
        );
    }
}
