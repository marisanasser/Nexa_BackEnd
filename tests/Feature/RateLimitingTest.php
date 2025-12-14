<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_rate_limiting()
    {

        for ($i = 0; $i < 10; $i++) {
            $response = $this->postJson('/api/register', [
                'name' => 'Test User '.$i,
                'email' => 'test'.$i.'@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role' => 'creator',
                'whatsapp' => '+1234567890',
            ]);

            if ($i < 9) {

                $this->assertNotEquals(429, $response->status());
            } else {

                $this->assertEquals(429, $response->status());
                $this->assertStringContainsString('Muitas tentativas de registro', $response->json('message'));
                $this->assertArrayHasKey('retry_after', $response->json());
            }
        }
    }

    public function test_login_rate_limiting()
    {

        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        for ($i = 0; $i < 20; $i++) {
            $response = $this->postJson('/api/login', [
                'email' => 'test@example.com',
                'password' => 'wrongpassword',
            ]);

            if ($i < 19) {

                $this->assertNotEquals(429, $response->status());
            } else {

                $this->assertEquals(429, $response->status());
                $this->assertStringContainsString('Muitas tentativas de login', $response->json('message'));
                $this->assertArrayHasKey('retry_after', $response->json());
            }
        }
    }

    public function test_password_reset_rate_limiting()
    {

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/forgot-password', [
                'email' => 'test'.$i.'@example.com',
            ]);

            if ($i < 4) {

                $this->assertNotEquals(429, $response->status());
            } else {

                $this->assertEquals(429, $response->status());
                $this->assertStringContainsString('Muitas tentativas de redefinição de senha', $response->json('message'));
                $this->assertArrayHasKey('retry_after', $response->json());
            }
        }
    }

    public function test_rate_limiting_headers()
    {

        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'creator',
            'whatsapp' => '+1234567890',
        ]);

        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
    }

    public function test_rate_limiting_reset_after_success()
    {

        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => 'test@example.com',
                'password' => 'wrongpassword',
            ]);
        }

        $response = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->assertEquals(200, $response->status());

        $this->assertTrue($response->json('success'));
    }
}
