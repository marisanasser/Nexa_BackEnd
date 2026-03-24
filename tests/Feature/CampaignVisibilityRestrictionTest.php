<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign\Campaign;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignVisibilityRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_allowed_user_cannot_see_restricted_campaign(): void
    {
        $brand = User::factory()->state(['role' => 'brand'])->create();
        $restrictedCampaign = Campaign::factory()->approved()->active()->create([
            'brand_id' => $brand->id,
            'status' => 'approved',
            'is_active' => true,
        ]);

        config([
            'campaign_visibility.restricted_campaign_id' => $restrictedCampaign->id,
            'campaign_visibility.restricted_campaign_allowed_email' => 'arturcamposba99@gmail.com',
        ]);

        $otherStudent = User::factory()->state([
            'role' => 'student',
            'email' => 'other-user@example.com',
        ])->create();

        Sanctum::actingAs($otherStudent);

        $listResponse = $this->getJson('/api/campaigns');
        $listResponse->assertStatus(200);

        $listedIds = collect($listResponse->json('data'))->pluck('id');
        $this->assertFalse($listedIds->contains($restrictedCampaign->id));

        $detailsResponse = $this->getJson("/api/campaigns/{$restrictedCampaign->id}");
        $detailsResponse->assertStatus(404);
    }

    public function test_allowed_user_can_see_restricted_campaign(): void
    {
        $brand = User::factory()->state(['role' => 'brand'])->create();
        $restrictedCampaign = Campaign::factory()->approved()->active()->create([
            'brand_id' => $brand->id,
            'status' => 'approved',
            'is_active' => true,
        ]);

        config([
            'campaign_visibility.restricted_campaign_id' => $restrictedCampaign->id,
            'campaign_visibility.restricted_campaign_allowed_email' => 'arturcamposba99@gmail.com',
        ]);

        $allowedStudent = User::factory()->state([
            'role' => 'student',
            'email' => 'arturcamposba99@gmail.com',
        ])->create();

        Sanctum::actingAs($allowedStudent);

        $listResponse = $this->getJson('/api/campaigns');
        $listResponse->assertStatus(200);

        $listedIds = collect($listResponse->json('data'))->pluck('id');
        $this->assertTrue($listedIds->contains($restrictedCampaign->id));

        $detailsResponse = $this->getJson("/api/campaigns/{$restrictedCampaign->id}");
        $detailsResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $restrictedCampaign->id);
    }
}

