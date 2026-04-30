<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Seeder para criar usuários de teste em produção
 * Execute: php artisan db:seed --class=ProductionTestUsersSeeder.
 */
class ProductionTestUsersSeeder extends Seeder
{
    public function run(): void
    {
        $passwords = [
            'admin' => $this->password('PRODUCTION_TEST_ADMIN_PASSWORD'),
            'brand' => $this->password('PRODUCTION_TEST_BRAND_PASSWORD'),
            'creatorPremium' => $this->password('PRODUCTION_TEST_CREATOR_PREMIUM_PASSWORD'),
            'creatorFree' => $this->password('PRODUCTION_TEST_CREATOR_FREE_PASSWORD'),
            'studentVerified' => $this->password('PRODUCTION_TEST_STUDENT_VERIFIED_PASSWORD'),
            'studentFree' => $this->password('PRODUCTION_TEST_STUDENT_FREE_PASSWORD'),
        ];

        // Admin User
        $admin = User::updateOrCreate(
            ['email' => 'admin@nexacreators.com.br'],
            [
                'name' => 'Admin Nexa',
                'password' => Hash::make($passwords['admin']),
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
                'password' => Hash::make($passwords['brand']),
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
                'password' => Hash::make($passwords['creatorPremium']),
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
                'password' => Hash::make($passwords['creatorFree']),
                'role' => 'creator',
                'email_verified_at' => now(),
                'has_premium' => false,
            ]
        );
        $this->command->info("Creator Free user: {$creatorFree->email}");

        // Student Verified User
        $studentVerified = User::updateOrCreate(
            ['email' => 'student.verified@nexacreators.com.br'],
            [
                'name' => 'Student Verified Teste',
                'password' => Hash::make($passwords['studentVerified']),
                'role' => 'student',
                'email_verified_at' => now(),
                'student_verified' => true,
                'student_expires_at' => now()->addYear(),
                'has_premium' => false, // Students don't need premium if verified
            ]
        );
        $this->command->info("Student Verified user: {$studentVerified->email}");

        // Student Not Verified User
        $studentFree = User::updateOrCreate(
            ['email' => 'student.free@nexacreators.com.br'],
            [
                'name' => 'Student Free Teste',
                'password' => Hash::make($passwords['studentFree']),
                'role' => 'student',
                'email_verified_at' => now(),
                'student_verified' => false,
                'has_premium' => false,
            ]
        );
        $this->command->info("Student Free user: {$studentFree->email}");

        $this->command->newLine();
        $this->command->info('=== Production Test Users Created ===');
        $this->command->table(
            ['Role', 'Email', 'Password Source', 'Premium', 'Verified'],
            [
                ['Admin', 'admin@nexacreators.com.br', 'PRODUCTION_TEST_ADMIN_PASSWORD', 'Yes', '-'],
                ['Brand', 'brand.teste@nexacreators.com.br', 'PRODUCTION_TEST_BRAND_PASSWORD', 'Yes', '-'],
                ['Creator', 'creator.premium@nexacreators.com.br', 'PRODUCTION_TEST_CREATOR_PREMIUM_PASSWORD', 'Yes', '-'],
                ['Creator', 'creator.free@nexacreators.com.br', 'PRODUCTION_TEST_CREATOR_FREE_PASSWORD', 'No', '-'],
                ['Student', 'student.verified@nexacreators.com.br', 'PRODUCTION_TEST_STUDENT_VERIFIED_PASSWORD', 'No', 'Yes'],
                ['Student', 'student.free@nexacreators.com.br', 'PRODUCTION_TEST_STUDENT_FREE_PASSWORD', 'No', 'No'],
            ]
        );
    }

    private function password(string $envKey): string
    {
        $password = env($envKey);

        if (is_string($password) && $password !== '') {
            return $password;
        }

        if (app()->environment('production')) {
            throw new RuntimeException("Environment variable {$envKey} is required in production.");
        }

        return bin2hex(random_bytes(16)) . 'A1!';
    }
}
