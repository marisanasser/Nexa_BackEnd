<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User\User;
use Tests\TestCase;

/**
 * @internal
 *
 * @covers \App\Models\User\User
 */
class UserModelTest extends TestCase
{
    public function testStudentAccessUsesStudentExpirationWhenLegacyTrialIsMissing(): void
    {
        $user = new User([
            'role' => 'student',
            'student_verified' => true,
            'has_premium' => false,
            'student_expires_at' => now()->addMonth(),
            'free_trial_expires_at' => null,
        ]);

        $this->assertTrue($user->isOnTrial());
        $this->assertTrue($user->hasPremiumAccess());
        $this->assertTrue($user->isVerifiedStudent());
        $this->assertNotNull($user->getStudentAccessExpiresAt());
    }

    public function testExpiredStudentLosesPremiumAccessWhenStudentExpirationPasses(): void
    {
        $user = new User([
            'role' => 'student',
            'student_verified' => true,
            'has_premium' => false,
            'student_expires_at' => now()->subDay(),
            'free_trial_expires_at' => now()->addMonth(),
        ]);

        $this->assertFalse($user->isOnTrial());
        $this->assertFalse($user->hasPremiumAccess());
        $this->assertFalse($user->isVerifiedStudent());
    }

    public function testStudentFallsBackToLegacyTrialDateWhenStudentExpirationIsMissing(): void
    {
        $user = new User([
            'role' => 'student',
            'student_verified' => true,
            'has_premium' => false,
            'student_expires_at' => null,
            'free_trial_expires_at' => now()->addWeek(),
        ]);

        $this->assertTrue($user->isOnTrial());
        $this->assertTrue($user->hasPremiumAccess());
        $this->assertNotNull($user->getStudentAccessExpiresAt());
    }
}
