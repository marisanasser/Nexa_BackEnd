<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class BlockedStudentLoginTest extends TestCase
{
    use RefreshDatabase;

    public function testBlockedStudentCannotLogin(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'student_verified' => true,
            'email_verified_at' => null,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $response->assertJson([
            'errors' => [
                'email' => ['Sua conta foi bloqueada. Entre em contato com o suporte para mais informações.'],
            ],
        ]);
    }

    public function testRemovedStudentCannotLogin(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'student_verified' => true,
            'email_verified_at' => now(),
        ]);

        $user->delete();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
        $response->assertJson([
            'errors' => [
                'email' => ['Sua conta foi removida da plataforma. Entre em contato com o suporte para mais informações.'],
            ],
        ]);
    }

    public function testActiveStudentCanLogin(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'student_verified' => true,
            'email_verified_at' => now(),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'token',
            'token_type',
            'user',
        ]);
    }

    public function testBlockedStudentCannotAccessProtectedRoutes(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'student_verified' => true,
            'email_verified_at' => null,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/user');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Sua conta foi bloqueada. Entre em contato com o suporte para mais informações.',
        ]);
    }

    public function testRemovedStudentCannotAccessProtectedRoutes(): void
    {
        $user = User::factory()->create([
            'role' => 'student',
            'student_verified' => true,
            'email_verified_at' => now(),
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $user->delete();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
        ])->getJson('/api/user');

        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Sua conta foi removida da plataforma. Entre em contato com o suporte para mais informações.',
        ]);
    }
}
