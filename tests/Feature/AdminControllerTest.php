<?php

namespace Tests\Feature;

use App\Models\Campaign\Campaign;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $creator;

    private User $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->creator = User::factory()->create([
            'name' => 'Creator User',
            'email' => 'creator@example.com',
            'role' => 'creator',
            'has_premium' => false,
        ]);

        $this->brand = User::factory()->create([
            'name' => 'Brand User',
            'email' => 'brand@example.com',
            'role' => 'brand',
            'company_name' => 'Test Brand',
            'has_premium' => true,
        ]);
    }

    public function test_admin_can_get_all_users(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'pagination' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'from',
                    'to',
                ],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertCount(3, $response->json('data'));
    }

    public function test_admin_can_get_creators(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/users/creators');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'pagination',
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_get_brands(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/users/brands');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'pagination',
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertCount(1, $response->json('data'));
    }

    public function test_admin_can_get_user_statistics(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/users/statistics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_users',
                    'creators',
                    'brands',
                    'premium_users',
                    'verified_students',
                    'active_users',
                    'pending_users',
                ],
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertEquals(3, $response->json('data.total_users'));
        $this->assertEquals(1, $response->json('data.creators'));
        $this->assertEquals(1, $response->json('data.brands'));
        $this->assertEquals(1, $response->json('data.premium_users'));
    }

    public function test_admin_can_activate_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/admin/users/{$user->id}/status", [
                'action' => 'activate',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'user',
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_admin_can_block_user(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/admin/users/{$user->id}/status", [
                'action' => 'block',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'user',
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_admin_can_remove_user(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($this->admin)
            ->patchJson("/api/admin/users/{$user->id}/status", [
                'action' => 'remove',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'user',
            ]);

        $this->assertTrue($response->json('success'));
        $this->assertSoftDeleted($user);
    }

    public function test_non_admin_cannot_access_admin_endpoints(): void
    {
        $response = $this->actingAs($this->creator)
            ->getJson('/api/admin/users');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_admin_endpoints(): void
    {
        $response = $this->getJson('/api/admin/users');

        $response->assertStatus(401);
    }

    public function test_admin_can_get_dashboard_metrics(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/dashboard-metrics');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'pendingCampaignsCount',
                    'allActiveCampaignCount',
                    'allRejectCampaignCount',
                    'allUserCount',
                ],
            ]);

        $this->assertTrue($response->json('success'));

        $userCount = $response->json('data.allUserCount');
        $this->assertEquals(2, $userCount);
    }

    public function test_admin_can_get_pending_campaigns(): void
    {

        Campaign::factory()->create([
            'brand_id' => $this->brand->id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/pending-campaigns');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'brand',
                        'type',
                        'value',
                    ],
                ],
            ]);

        $this->assertTrue($response->json('success'));
    }

    public function test_admin_can_get_recent_users(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/recent-users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'role',
                        'registeredDaysAgo',
                        'tag',
                    ],
                ],
            ]);

        $this->assertTrue($response->json('success'));

        $data = $response->json('data');
        foreach ($data as $user) {
            $this->assertNotEquals('admin', $user['role']);
        }
    }
}
