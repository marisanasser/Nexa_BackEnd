<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider as SocialiteAbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * @internal
 *
 * @coversNothing
 */
class GoogleOAuthTest extends TestCase
{
    use RefreshDatabase;

    public function testGoogleRedirectReturnsUrl(): void
    {
        config([
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect' => 'https://www.nexacreators.com/auth/google/callback',
        ]);

        $response = $this->getJson('/api/google/redirect');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'redirect_url',
            ])
            ->assertJson([
                'success' => true,
            ])
        ;

        $redirectUrl = $response->json('redirect_url');
        $this->assertNotEmpty($redirectUrl);

        $parsedUrl = parse_url($redirectUrl);
        $query = [];

        if (isset($parsedUrl['query'])) {
            parse_str($parsedUrl['query'], $query);
        }

        $this->assertArrayHasKey('redirect_uri', $query);
        $this->assertNotEmpty($query['redirect_uri']);
    }

    public function testGoogleCallbackRequiresRoleSelectionForNewUser(): void
    {
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = '123456789';
        $socialiteUser->name = 'John Doe';
        $socialiteUser->email = 'john@example.com';
        $socialiteUser->avatar = 'https://example.com/avatar.jpg';
        $socialiteUser->token = 'mock_token';
        $socialiteUser->refreshToken = 'mock_refresh_token';

        $this->mockGoogleUser($socialiteUser);

        $response = $this->getJson('/api/google/callback');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'action',
                'registration_id',
                'google_user' => [
                    'name',
                    'email',
                    'avatar',
                ],
            ])
            ->assertJson([
                'success' => true,
                'action' => 'role_selection',
            ])
        ;
    }

    public function testGoogleCallbackLogsInExistingUser(): void
    {
        $user = User::factory()->create([
            'email' => 'john@example.com',
            'google_id' => '123456789',
        ]);

        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = '123456789';
        $socialiteUser->name = 'John Doe';
        $socialiteUser->email = 'john@example.com';
        $socialiteUser->avatar = 'https://example.com/avatar.jpg';
        $socialiteUser->token = 'mock_token';
        $socialiteUser->refreshToken = 'mock_refresh_token';

        $this->mockGoogleUser($socialiteUser);

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
                    'has_premium',
                ],
                'message',
            ])
            ->assertJson([
                'success' => true,
                'token_type' => 'Bearer',
                'message' => 'Login successful',
            ])
        ;
    }

    public function testGoogleAuthWithRoleCreatesUserWithSpecifiedRole(): void
    {
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = '123456789';
        $socialiteUser->name = 'Jane Doe';
        $socialiteUser->email = 'jane@example.com';
        $socialiteUser->avatar = 'https://example.com/avatar.jpg';
        $socialiteUser->token = 'mock_token';
        $socialiteUser->refreshToken = 'mock_refresh_token';

        $this->mockGoogleUser($socialiteUser);

        $response = $this->postJson('/api/google/auth', [
            'role' => 'brand',
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
                    'has_premium',
                ],
                'message',
            ])
            ->assertJson([
                'success' => true,
                'token_type' => 'Bearer',
                'message' => 'Registration successful',
            ])
        ;

        $this->assertDatabaseHas('users', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'google_id' => '123456789',
            'role' => 'brand',
        ]);
    }

    public function testGoogleAuthValidatesRole(): void
    {
        $response = $this->postJson('/api/google/auth', [
            'role' => 'invalid_role',
        ]);

        $response->assertStatus(422);
    }

    public function testGoogleCallbackWithRoleCreatesUserWithSpecifiedRole(): void
    {
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = '123456789';
        $socialiteUser->name = 'Brand User';
        $socialiteUser->email = 'brand@example.com';
        $socialiteUser->avatar = 'https://example.com/avatar.jpg';
        $socialiteUser->token = 'mock_token';
        $socialiteUser->refreshToken = 'mock_refresh_token';

        $this->mockGoogleUser($socialiteUser);

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
                    'has_premium',
                ],
                'message',
            ])
            ->assertJson([
                'success' => true,
                'token_type' => 'Bearer',
                'message' => 'Registration successful',
            ])
        ;

        $this->assertDatabaseHas('users', [
            'name' => 'Brand User',
            'email' => 'brand@example.com',
            'google_id' => '123456789',
            'role' => 'brand',
        ]);
    }

    public function testGoogleCallbackWithInvalidRoleDefaultsToCreator(): void
    {
        $socialiteUser = new SocialiteUser();
        $socialiteUser->id = '123456789';
        $socialiteUser->name = 'Test User';
        $socialiteUser->email = 'test@example.com';
        $socialiteUser->avatar = 'https://example.com/avatar.jpg';
        $socialiteUser->token = 'mock_token';
        $socialiteUser->refreshToken = 'mock_refresh_token';

        $this->mockGoogleUser($socialiteUser);

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
                    'has_premium',
                ],
                'message',
            ])
            ->assertJson([
                'success' => true,
                'token_type' => 'Bearer',
                'message' => 'Registration successful',
            ])
        ;

        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'google_id' => '123456789',
            'role' => 'creator',
        ]);
    }

    private function mockGoogleUser(SocialiteUser $socialiteUser): void
    {
        $provider = Mockery::mock(SocialiteAbstractProvider::class);
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($socialiteUser);

        Socialite::shouldReceive('buildProvider')->once()->andReturn($provider);
    }
}
