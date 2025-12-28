<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Database\Seeder;

class ProductionTestCampaignSeeder extends Seeder
{
    public function run(): void
    {
        $brand = User::where('email', 'brand.teste@nexacreators.com.br')->first();

        if (!$brand) {
            $this->command->error('Brand user not found. Run ProductionTestUsersSeeder first.');
            return;
        }

        $campaign = Campaign::updateOrCreate(
            ['title' => 'Campanha Teste Produção 2025'],
            [
                'user_id' => $brand->id,
                'description' => 'Esta é uma campanha de teste para verificar o fluxo completo: aprovação admin, aplicação de creator, abertura de chat.',
                'budget' => 5000,
                'category' => 'Tecnologia',
                'deadline' => now()->addMonths(3),
                'status' => 'pending',
                'is_active' => true,
                'creator_type' => 'both',
                'target_creators' => 5,
                'skills_required' => ['Content Creation', 'Social Media'],
                'states' => ['SP', 'RJ', 'MG'],
            ]
        );

        $this->command->info("Campaign created: {$campaign->title} (ID: {$campaign->id})");
        $this->command->info("Status: {$campaign->status}");
    }
}
