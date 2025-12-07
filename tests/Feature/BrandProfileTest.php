<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class BrandProfileTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        
        $this->user = User::factory()->create([
            'role' => 'brand',
            'name' => 'Test Brand',
            'email' => 'brand@test.com',
        ]);
    }

    
    public function it_can_fetch_brand_profile()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/brand-profile');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'avatar',
                    'company_name',
                    'whatsapp_number',
                    'gender',
                    'state',
                    'role',
                    'created_at',
                    'updated_at',
                ]
            ]);
    }

    
    public function it_can_update_brand_profile()
    {
        $updateData = [
            'username' => 'Updated Brand Name',
            'email' => 'updated@test.com',
            'company_name' => 'Test Company',
            'whatsapp_number' => '+1234567890',
            'gender' => 'male',
            'state' => 'California',
        ];

        $response = $this->actingAs($this->user)
            ->putJson('/api/brand-profile', $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Profile updated successfully'
            ]);

        
        $this->user->refresh();
        $this->assertEquals('Updated Brand Name', $this->user->name);
        $this->assertEquals('updated@test.com', $this->user->email);
        $this->assertEquals('Test Company', $this->user->company_name);
        $this->assertEquals('+1234567890', $this->user->whatsapp_number);
        $this->assertEquals('male', $this->user->gender);
        $this->assertEquals('California', $this->user->state);
    }

    
    public function it_can_change_password()
    {
        $passwordData = [
            'old_password' => 'password', 
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/brand-profile/change-password', $passwordData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password changed successfully'
            ]);
    }

    
    public function it_validates_required_fields_on_update()
    {
        $response = $this->actingAs($this->user)
            ->putJson('/api/brand-profile', [
                'email' => 'invalid-email',
                'gender' => 'invalid-gender',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'gender']);
    }

    
    public function it_validates_password_change()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/brand-profile/change-password', [
                'old_password' => 'wrong-password',
                'new_password' => 'newpassword123',
                'new_password_confirmation' => 'newpassword123',
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Current password is incorrect'
            ]);
    }

    
    public function it_can_upload_avatar()
    {
        
        $imageData = base64_encode(file_get_contents(__DIR__ . '/../../public/placeholder.svg'));
        $base64Image = 'data:image/svg+xml;base64,' . $imageData;

        $response = $this->actingAs($this->user)
            ->postJson('/api/brand-profile/avatar', [
                'avatar' => $base64Image
            ]);

        
        if ($response->status() !== 200) {
            dump($response->content());
        }

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Avatar uploaded successfully'
            ])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'avatar',
                    'updated_at'
                ]
            ]);

        
        $this->user->refresh();
        $this->assertNotNull($this->user->avatar_url);
        $this->assertStringContainsString('/storage/avatars/', $this->user->avatar_url);
    }

    
    public function it_validates_avatar_format()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/brand-profile/avatar', [
                'avatar' => 'invalid-base64-data'
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid image format. Please provide a valid base64 encoded image.'
            ]);
    }

    
    public function it_can_delete_avatar()
    {
        
        $imageData = base64_encode(file_get_contents(__DIR__ . '/../../public/placeholder.svg'));
        $base64Image = 'data:image/svg+xml;base64,' . $imageData;

        $this->actingAs($this->user)
            ->postJson('/api/brand-profile/avatar', [
                'avatar' => $base64Image
            ]);

        
        $response = $this->actingAs($this->user)
            ->deleteJson('/api/brand-profile/avatar');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Avatar deleted successfully'
            ]);

        
        $this->user->refresh();
        $this->assertNull($this->user->avatar_url);
    }

    
    public function it_can_update_profile_with_avatar()
    {
        $imageData = base64_encode(file_get_contents(__DIR__ . '/../../public/placeholder.svg'));
        $base64Image = 'data:image/svg+xml;base64,' . $imageData;

        $updateData = [
            'username' => 'Updated Brand Name',
            'company_name' => 'Test Company',
            'avatar' => $base64Image
        ];

        $response = $this->actingAs($this->user)
            ->putJson('/api/brand-profile', $updateData);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Profile updated successfully'
            ]);

        
        $this->user->refresh();
        $this->assertEquals('Updated Brand Name', $this->user->name);
        $this->assertEquals('Test Company', $this->user->company_name);
        $this->assertNotNull($this->user->avatar_url);
        $this->assertStringContainsString('/storage/avatars/', $this->user->avatar_url);
    }
} 