<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GoogleOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_google_redirect_returns_url(): void
    {
        $response = $this->getJson('/api/google/redirect');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'redirect_url'
                ])
                ->assertJson([
                    'success' => true
                ]);

        $this->assertNotEmpty($response->json('redirect_url'));
    }

    public function test_google_callback_creates_new_user(): void
    {
        
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = '123456789';
        $socialiteUser->name = 'John Doe';
        $socialiteUser->email = 'john@example.com';
        $socialiteUser->avatar = 'https://example.com/avatar.jpg';
        $socialiteUser->token = 'mock_token';
        $socialiteUser->refreshToken = 'mock_refresh_token';

        Socialite::shouldReceive('driver->stateless->user')
                ->once()
                ->andReturn($socialiteUser);

        $response = $this->getJson('/api/google/callback');

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'token',
                    'token_type',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'role',
                        'avatar_url',
                        'student_verified',
                        'has_premium'
                    ],
                    'message'
                ])
                ->assertJson([
                    'success' => true,
                    'token_type' => 'Bearer',
                    'message' => 'Registration successful'
                ]);

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'google_id' => '123456789',
            'role' => 'creator'
        ]);
    }

    public function test_google_callback_logs_in_existing_user(): void
    {
        
        $user = User::factory()->create([
            'email' => 'john@example.com',
            'google_id' => '123456789'
        ]);

        
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = '123456789';
        $socialiteUser->name = 'John Doe';
        $socialiteUser->email = 'john@example.com';
        $socialiteUser->avatar = 'https://example.com/avatar.jpg';
        $socialiteUser->token = 'mock_token';
        $socialiteUser->refreshToken = 'mock_refresh_token';

        Socialite::shouldReceive('driver->stateless->user')
                ->once()
                ->andReturn($socialiteUser);

        $response = $this->getJson('/api/google/callback');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'token',
                    'token_type',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'role',
                        'avatar_url',
                        'student_verified',
                        'has_premium'
                    ],
                    'message'
                ])
                ->assertJson([
                    'success' => true,
                    'token_type' => 'Bearer',
                    'message' => 'Login successful'
                ]);
    }

    public function test_google_auth_with_role_creates_user_with_specified_role(): void
    {
        
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = '123456789';
        $socialiteUser->name = 'Jane Doe';
        $socialiteUser->email = 'jane@example.com';
        $socialiteUser->avatar = 'https://example.com/avatar.jpg';
        $socialiteUser->token = 'mock_token';
        $socialiteUser->refreshToken = 'mock_refresh_token';

        Socialite::shouldReceive('driver->stateless->user')
                ->once()
                ->andReturn($socialiteUser);

        $response = $this->postJson('/api/google/auth', [
            'role' => 'brand'
        ]);

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'token',
                    'token_type',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'role',
                        'avatar_url',
                        'student_verified',
                        'has_premium'
                    ],
                    'message'
                ])
                ->assertJson([
                    'success' => true,
                    'token_type' => 'Bearer',
                    'message' => 'Registration successful'
                ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'google_id' => '123456789',
            'role' => 'brand'
        ]);
    }

    public function test_google_auth_validates_role(): void
    {
        $response = $this->postJson('/api/google/auth', [
            'role' => 'invalid_role'
        ]);

        $response->assertStatus(422);
    }

    public function test_google_callback_with_role_creates_user_with_specified_role(): void
    {
        
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = '123456789';
        $socialiteUser->name = 'Brand User';
        $socialiteUser->email = 'brand@example.com';
        $socialiteUser->avatar = 'https://example.com/avatar.jpg';
        $socialiteUser->token = 'mock_token';
        $socialiteUser->refreshToken = 'mock_refresh_token';

        Socialite::shouldReceive('driver->stateless->user')
                ->once()
                ->andReturn($socialiteUser);

        $response = $this->getJson('/api/google/callback?role=brand');

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'token',
                    'token_type',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'role',
                        'avatar_url',
                        'student_verified',
                        'has_premium'
                    ],
                    'message'
                ])
                ->assertJson([
                    'success' => true,
                    'token_type' => 'Bearer',
                    'message' => 'Registration successful'
                ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Brand User',
            'email' => 'brand@example.com',
            'google_id' => '123456789',
            'role' => 'brand'
        ]);
    }

    public function test_google_callback_with_invalid_role_defaults_to_creator(): void
    {
        
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = '123456789';
        $socialiteUser->name = 'Test User';
        $socialiteUser->email = 'test@example.com';
        $socialiteUser->avatar = 'https://example.com/avatar.jpg';
        $socialiteUser->token = 'mock_token';
        $socialiteUser->refreshToken = 'mock_refresh_token';

        Socialite::shouldReceive('driver->stateless->user')
                ->once()
                ->andReturn($socialiteUser);

        $response = $this->getJson('/api/google/callback?role=invalid_role');

        $response->assertStatus(201)
                ->assertJsonStructure([
                    'success',
                    'token',
                    'token_type',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'role',
                        'avatar_url',
                        'student_verified',
                        'has_premium'
                    ],
                    'message'
                ])
                ->assertJson([
                    'success' => true,
                    'token_type' => 'Bearer',
                    'message' => 'Registration successful'
                ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'google_id' => '123456789',
            'role' => 'creator' 
        ]);
    }
} 