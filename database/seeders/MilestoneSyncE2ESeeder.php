<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Campaign\Campaign;
use App\Models\Campaign\CampaignApplication;
use App\Models\User\User;
use Illuminate\Database\Seeder;

class MilestoneSyncE2ESeeder extends Seeder
{
    public function run(): void
    {
        $brand = User::where('email', 'brand.teste@nexacreators.com.br')->first();
        $creator = User::where('email', 'creator.premium@nexacreators.com.br')->first();

        if (!$brand || !$creator) {
            $this->command?->error('Users not found. Run ProductionTestUsersSeeder first.');

            return;
        }

        $campaign = Campaign::updateOrCreate(
            [
                'brand_id' => $brand->id,
                'title' => 'E2E Milestone Sync Campaign',
            ],
            [
                'description' => 'Campanha para validar fluxo completo de milestones e sincronismo de botões/modais.',
                'budget' => 100.00,
                'location' => 'São Paulo',
                'requirements' => 'Roteiro e vídeo final',
                'target_states' => ['SP'],
                'category' => 'Tecnologia',
                'campaign_type' => 'instagram',
                'status' => 'approved',
                'is_active' => true,
                'deadline' => now()->addDays(20),
                'max_bids' => 10,
            ]
        );

        CampaignApplication::updateOrCreate(
            [
                'campaign_id' => $campaign->id,
                'creator_id' => $creator->id,
            ],
            [
                'status' => 'pending',
                'workflow_status' => 'first_contact_pending',
                'proposal' => 'Proposta E2E para validar milestones após aprovação.',
                'portfolio_links' => [],
                'estimated_delivery_days' => 7,
                'proposed_budget' => 100.00,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'approved_at' => null,
                'rejection_reason' => null,
            ]
        );

        $this->command?->info("Campaign ready: {$campaign->id} - {$campaign->title}");
    }
}
