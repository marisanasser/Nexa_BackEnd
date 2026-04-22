<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign\Campaign;
use App\Models\Chat\ChatRoom;
use App\Models\Contract\Contract;
use App\Models\Contract\Offer;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CampaignCloseTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_can_close_campaign_without_closing_chat_or_contract(): void
    {
        $brand = User::factory()->state(['role' => 'brand'])->create();
        $creator = User::factory()->state(['role' => 'creator'])->create();

        $campaign = Campaign::factory()->approved()->active()->create([
            'brand_id' => $brand->id,
            'status' => 'approved',
            'is_active' => true,
        ]);

        $chatRoom = ChatRoom::factory()->create([
            'campaign_id' => $campaign->id,
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'room_id' => ChatRoom::generateRoomId($campaign->id, $brand->id, $creator->id),
            'is_active' => true,
            'chat_status' => ChatRoom::STATUS_ACTIVE,
        ]);

        $offer = Offer::factory()->accepted()->create([
            'campaign_id' => $campaign->id,
            'chat_room_id' => $chatRoom->id,
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
        ]);

        $contract = Contract::factory()->active()->create([
            'offer_id' => $offer->id,
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($brand);

        $response = $this->patchJson("/api/campaigns/{$campaign->id}/close");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $campaign->id)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'approved',
            'is_active' => false,
        ]);

        $this->assertDatabaseHas('chat_rooms', [
            'id' => $chatRoom->id,
            'is_active' => true,
            'chat_status' => ChatRoom::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'active',
        ]);
    }

    public function test_brand_cannot_close_campaign_from_another_brand(): void
    {
        $ownerBrand = User::factory()->state(['role' => 'brand'])->create();
        $otherBrand = User::factory()->state(['role' => 'brand'])->create();

        $campaign = Campaign::factory()->approved()->active()->create([
            'brand_id' => $ownerBrand->id,
            'status' => 'approved',
            'is_active' => true,
        ]);

        Sanctum::actingAs($otherBrand);

        $response = $this->patchJson("/api/campaigns/{$campaign->id}/close");

        $response->assertStatus(403);

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'is_active' => true,
        ]);
    }

    public function test_closed_campaign_is_not_listed_for_creator(): void
    {
        $brand = User::factory()->state(['role' => 'brand'])->create();
        $creator = User::factory()->state(['role' => 'creator'])->create();

        $campaign = Campaign::factory()->approved()->active()->create([
            'brand_id' => $brand->id,
            'status' => 'approved',
            'is_active' => true,
        ]);

        Sanctum::actingAs($brand);
        $this->patchJson("/api/campaigns/{$campaign->id}/close")->assertOk();

        Sanctum::actingAs($creator);
        $listResponse = $this->getJson('/api/campaigns');

        $listResponse->assertOk();

        $listedIds = collect($listResponse->json('data'))->pluck('id');
        $this->assertFalse($listedIds->contains($campaign->id));
    }

    public function test_brand_can_reopen_closed_campaign_without_closing_chat_or_contract(): void
    {
        $brand = User::factory()->state(['role' => 'brand'])->create();
        $creator = User::factory()->state(['role' => 'creator'])->create();

        $campaign = Campaign::factory()->approved()->inactive()->create([
            'brand_id' => $brand->id,
            'status' => 'approved',
            'is_active' => false,
        ]);

        $chatRoom = ChatRoom::factory()->create([
            'campaign_id' => $campaign->id,
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'room_id' => ChatRoom::generateRoomId($campaign->id, $brand->id, $creator->id),
            'is_active' => true,
            'chat_status' => ChatRoom::STATUS_ACTIVE,
        ]);

        $offer = Offer::factory()->accepted()->create([
            'campaign_id' => $campaign->id,
            'chat_room_id' => $chatRoom->id,
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
        ]);

        $contract = Contract::factory()->active()->create([
            'offer_id' => $offer->id,
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($brand);

        $response = $this->patchJson("/api/campaigns/{$campaign->id}/reopen");

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $campaign->id)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('campaigns', [
            'id' => $campaign->id,
            'status' => 'approved',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('chat_rooms', [
            'id' => $chatRoom->id,
            'is_active' => true,
            'chat_status' => ChatRoom::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'status' => 'active',
        ]);
    }

    public function test_reopened_campaign_is_listed_for_creator_again(): void
    {
        $brand = User::factory()->state(['role' => 'brand'])->create();
        $creator = User::factory()->state(['role' => 'creator'])->create();

        $campaign = Campaign::factory()->approved()->active()->create([
            'brand_id' => $brand->id,
            'status' => 'approved',
            'is_active' => true,
        ]);

        Sanctum::actingAs($brand);
        $this->patchJson("/api/campaigns/{$campaign->id}/close")->assertOk();
        $this->patchJson("/api/campaigns/{$campaign->id}/reopen")->assertOk();

        Sanctum::actingAs($creator);
        $listResponse = $this->getJson('/api/campaigns');

        $listResponse->assertOk();

        $listedIds = collect($listResponse->json('data'))->pluck('id');
        $this->assertTrue($listedIds->contains($campaign->id));
    }
}
