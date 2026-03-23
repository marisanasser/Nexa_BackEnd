<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign\CampaignTimeline;
use App\Models\Contract\Contract;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractWorkflowPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_upload_script_submission_file(): void
    {
        Storage::fake('public');

        $brand = User::factory()->state(['role' => 'brand'])->create();
        $student = User::factory()->state(['role' => 'student'])->create();

        $contract = Contract::factory()->create([
            'brand_id' => $brand->id,
            'creator_id' => $student->id,
            'status' => 'active',
        ]);

        $milestone = CampaignTimeline::factory()->create([
            'contract_id' => $contract->id,
            'milestone_type' => 'script_submission',
            'status' => 'pending',
            'file_path' => null,
        ]);

        Sanctum::actingAs($student);

        $response = $this->post('/api/campaign-timeline/upload-file', [
            'milestone_id' => $milestone->id,
            'file' => UploadedFile::fake()->create('roteiro.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $milestone->refresh();
        $this->assertNotNull($milestone->file_path);
    }

    public function test_creator_cannot_override_tracking_code_with_non_shipping_status(): void
    {
        $brand = User::factory()->state(['role' => 'brand'])->create();
        $creator = User::factory()->state(['role' => 'creator'])->create();

        $contract = Contract::factory()->create([
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'status' => 'active',
            'tracking_code' => 'OLD123',
            'requirements' => [
                '_tracking_code' => 'OLD123',
            ],
        ]);

        Sanctum::actingAs($creator);

        $response = $this->postJson("/api/contracts/{$contract->id}/workflow-status", [
            'workflow_status' => 'alignment_preparation',
            'tracking_code' => 'NEW999',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);

        $contract->refresh();
        $this->assertSame('OLD123', $contract->tracking_code);
        $this->assertSame('OLD123', $contract->requirements['_tracking_code'] ?? null);
    }

    public function test_brand_can_update_tracking_code_on_shipping_status(): void
    {
        $brand = User::factory()->state(['role' => 'brand'])->create();
        $creator = User::factory()->state(['role' => 'creator'])->create();

        $contract = Contract::factory()->create([
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($brand);

        $response = $this->postJson("/api/contracts/{$contract->id}/workflow-status", [
            'workflow_status' => 'material_sent',
            'tracking_code' => 'TRACK123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tracking_code', 'TRACK123')
            ->assertJsonPath('data.workflow_status', 'material_sent');

        $contract->refresh();
        $this->assertSame('TRACK123', $contract->tracking_code);
        $this->assertSame('TRACK123', $contract->requirements['_tracking_code'] ?? null);
    }
}

