<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_new_users_can_register_with_basic_fields(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'creator',
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
                        'email_verified_at',
                        'role',
                        'whatsapp',
                        'avatar_url',
                        'bio',
                        'company_name',
                        'student_verified',
                        'student_expires_at',
                        'gender',
                        'state',
                        'language',
                        'has_premium',
                        'premium_expires_at',
                        'free_trial_expires_at',
                    ]
                ]);

        $this->assertDatabaseHas('users', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => 'creator',
            'has_premium' => false,
            'student_verified' => false,
        ]);
    }

    public function test_new_users_can_register_with_all_fields(): void
    {
        $avatarFile = UploadedFile::fake()->image('avatar.jpg', 200, 200);

        $response = $this->postJson('/api/register', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'brand',
            'whatsapp' => '+1234567890',
            'avatar_url' => $avatarFile,
            'bio' => 'This is a test bio for the user profile',
            'company_name' => 'Test Company Inc',
            'gender' => 'male',
            'state' => 'California',
            'language' => 'en',
            'has_premium' => false,
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('users', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'role' => 'brand',
            'whatsapp' => '+1234567890',
            'company_name' => 'Test Company Inc',
            'gender' => 'male',
            'state' => 'California',
            'language' => 'en',
            'has_premium' => false,
        ]);

        
        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user->avatar_url);
        Storage::disk('public')->assertExists(str_replace('/storage/', '', $user->avatar_url));
    }

    public function test_registration_requires_valid_email(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'invalid-email',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_requires_strong_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'weak',
            'password_confirmation' => 'weak',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['password']);
    }

    public function test_registration_validates_role(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'invalid_role',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['role']);
    }

    public function test_registration_validates_gender(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'gender' => 'invalid_gender',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['gender']);
    }

    public function test_registration_validates_language(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'language' => 'invalid_language',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['language']);
    }

    public function test_registration_validates_has_premium(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'has_premium' => 'invalid_value',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['has_premium']);
    }

    public function test_registration_validates_phone_number(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'whatsapp' => 'invalid-phone',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['whatsapp']);
    }

    public function test_registration_validates_avatar_file_type(): void
    {
        $invalidFile = UploadedFile::fake()->create('document.pdf', 1000);

        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'avatar_url' => $invalidFile,
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['avatar_url']);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'test@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['email']);
    }

    public function test_registration_sets_default_values(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201);

        $user = User::where('email', 'test@example.com')->first();
        $this->assertEquals('creator', $user->role);
        $this->assertEquals('en', $user->language);
        $this->assertFalse($user->has_premium);
        $this->assertFalse($user->student_verified);
        $this->assertNull($user->student_expires_at);
        $this->assertNull($user->premium_expires_at);
        $this->assertNull($user->free_trial_expires_at);
    }
}
