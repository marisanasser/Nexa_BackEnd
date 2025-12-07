<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class BlockedStudentLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_student_cannot_login()
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
                'email' => ['Sua conta foi bloqueada. Entre em contato com o suporte para mais informações.']
            ]
        ]);
    }

    public function test_removed_student_cannot_login()
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
                'email' => ['Sua conta foi removida da plataforma. Entre em contato com o suporte para mais informações.']
            ]
        ]);
    }

    public function test_active_student_can_login()
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
            'user'
        ]);
    }

    public function test_blocked_student_cannot_access_protected_routes()
    {
        
        $user = User::factory()->create([
            'role' => 'student',
            'student_verified' => true,
            'email_verified_at' => null, 
        ]);

        
        $token = $user->createToken('test-token')->plainTextToken;

        
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/user');

        
        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Sua conta foi bloqueada. Entre em contato com o suporte para mais informações.'
        ]);
    }

    public function test_removed_student_cannot_access_protected_routes()
    {
        
        $user = User::factory()->create([
            'role' => 'student',
            'student_verified' => true,
            'email_verified_at' => now(),
        ]);

        
        $token = $user->createToken('test-token')->plainTextToken;

        
        $user->delete();

        
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/user');

        
        $response->assertStatus(403);
        $response->assertJson([
            'success' => false,
            'message' => 'Sua conta foi removida da plataforma. Entre em contato com o suporte para mais informações.'
        ]);
    }
}
