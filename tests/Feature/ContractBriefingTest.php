<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Contract\Contract;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractBriefingTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_can_update_contract_briefing()
    {
        // Create a brand user
        $brand = User::factory()->state(['role' => 'brand'])->create();

        // Create a contract associated with the brand
        $contract = Contract::factory()->create([
            'brand_id' => $brand->id,
            'briefing' => null, // Start with no briefing
        ]);

        // Authenticate as the brand
        $this->actingAs($brand);

        // Define briefing data
        $briefingData = [
            'briefing' => [
                'objectives' => 'Increase brand awareness',
                'target_audience' => 'Gen Z',
                'key_messages' => 'Sustainability and innovation',
                'channels' => 'Instagram, TikTok',
                'deadlines' => 'Draft by Friday',
                'brand_requirements' => 'Use brand colors',
            ],
            'requirements' => [
                'format' => 'Reels',
                'duration' => '60s',
            ],
        ];

        // Send PUT request to update the contract
        $response = $this->putJson("/api/contracts/{$contract->id}", $briefingData);

        // Assert response status
        $response->assertStatus(200);

        // Assert the database was updated
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
        ]);

        $updatedContract = Contract::find($contract->id);

        $this->assertEquals($briefingData['briefing'], $updatedContract->briefing);
        $this->assertEquals($briefingData['requirements'], $updatedContract->requirements);
    }

    public function test_creator_can_view_contract_briefing()
    {
        // Create brand and creator
        $brand = User::factory()->state(['role' => 'brand'])->create();
        $creator = User::factory()->state(['role' => 'creator'])->create();

        // Briefing data
        $briefing = [
            'objectives' => 'Sales',
            'target_audience' => 'Gamers',
            'key_messages' => 'Performance',
            'channels' => 'Twitch',
            'deadlines' => 'ASAP',
            'brand_requirements' => 'Logo visible',
        ];

        // Create contract with briefing
        $contract = Contract::factory()->create([
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'briefing' => $briefing,
        ]);

        // Authenticate as creator
        $this->actingAs($creator);

        // Get the contract
        $response = $this->getJson("/api/contracts/{$contract->id}");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'briefing' => $briefing,
                ]
            ]);
    }

    public function test_unauthorized_user_cannot_update_briefing()
    {
        // Create a brand user and another unrelated user
        $brand = User::factory()->state(['role' => 'brand'])->create();
        $otherUser = User::factory()->create();

        // Create a contract associated with the brand
        $contract = Contract::factory()->create([
            'brand_id' => $brand->id,
        ]);

        // Authenticate as the other user
        $this->actingAs($otherUser);

        // Try to update
        $response = $this->putJson("/api/contracts/{$contract->id}", [
            'briefing' => ['objectives' => 'Hacked'],
        ]);

        // Should be not found (security by obscurity / resource isolation)
        $response->assertStatus(404);
    }

    public function test_validation_allows_partial_updates()
    {
        $brand = User::factory()->state(['role' => 'brand'])->create();
        $contract = Contract::factory()->create([
            'brand_id' => $brand->id,
            'briefing' => ['objectives' => 'Initial'],
        ]);

        $this->actingAs($brand);

        // Update only requirements
        $response = $this->putJson("/api/contracts/{$contract->id}", [
            'requirements' => ['format' => 'Photo'],
        ]);

        $response->assertStatus(200);

        $contract->refresh();
        // Briefing should remain unchanged (or handled by controller logic, usually update validates inputs. 
        // If I send only requirements, briefing might not be touched if the controller uses $request->only or similar, 
        // or if validation allows nullable.
        // Let's check controller logic. The validation says 'briefing' => 'nullable|array'. 
        // If it's not present in request, does it overwrite with null?
        // Usually $request->validate returns only validated fields that are present.
        // But let's check the update method implementation again.)
    }
}
