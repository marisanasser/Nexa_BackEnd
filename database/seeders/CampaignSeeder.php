<?php

namespace Database\Seeders;

use App\Models\Campaign\Campaign;
use App\Models\User\User;
use Illuminate\Database\Seeder;

class CampaignSeeder extends Seeder
{
    public function run(): void
    {

        $brands = User::where('role', 'brand')->take(3)->get();

        if ($brands->count() < 3) {

            $neededBrands = 3 - $brands->count();
            $newBrands = User::factory($neededBrands)->create(['role' => 'brand']);
            $brands = $brands->merge($newBrands);
        }

        $brands = $brands->take(3);

        $campaigns = [
            [
                'brand_id' => $brands->get(0)->id,
                'title' => 'Campanha de Moda Verão 2024',
                'description' => 'Criamos conteúdo para nossa nova coleção de verão. Precisamos de criadores que possam mostrar nossa roupa de forma autêntica e atrativa.',
                'budget' => 5000.00,
                'location' => 'São Paulo, Rio de Janeiro',
                'requirements' => 'Criadores com foco em moda, mínimo 10k seguidores, engajamento acima de 3%',
                'target_states' => ['SP', 'RJ'],
                'category' => 'moda',
                'campaign_type' => 'instagram',
                'status' => 'approved',
                'deadline' => now()->addDays(30),
                'max_bids' => 15,
                'is_active' => true,
                'is_featured' => true,
            ],
            [
                'brand_id' => $brands->get(0)->id,
                'title' => 'Lançamento de Produto de Beleza',
                'description' => 'Novo produto de skincare que revoluciona o mercado. Queremos criadores que testem e compartilhem suas experiências.',
                'budget' => 3000.00,
                'location' => 'Brasil',
                'requirements' => 'Criadores de beleza, skincare ou lifestyle, engajamento alto',
                'target_states' => ['SP', 'RJ', 'MG', 'RS'],
                'category' => 'beleza',
                'campaign_type' => 'instagram',
                'status' => 'approved',
                'deadline' => now()->addDays(25),
                'max_bids' => 10,
                'is_active' => true,
                'is_featured' => true,
            ],
            [
                'brand_id' => $brands->get(1)->id,
                'title' => 'Campanha de Tecnologia',
                'description' => 'Novo smartphone com recursos inovadores. Precisamos de criadores tech para demonstrar as funcionalidades.',
                'budget' => 8000.00,
                'location' => 'São Paulo',
                'requirements' => 'Criadores de tecnologia, reviews de produtos, audiência tech-savvy',
                'target_states' => ['SP'],
                'category' => 'tecnologia',
                'campaign_type' => 'youtube',
                'status' => 'approved',
                'deadline' => now()->addDays(40),
                'max_bids' => 8,
                'is_active' => true,
                'is_featured' => false,
            ],
            [
                'brand_id' => $brands->get(2)->id,
                'title' => 'Campanha de Fitness',
                'description' => 'Produtos fitness para atletas e entusiastas. Queremos criadores que inspirem e motivem.',
                'budget' => 4000.00,
                'location' => 'Rio de Janeiro',
                'requirements' => 'Criadores fitness, esportes ou saúde, estilo de vida ativo',
                'target_states' => ['RJ'],
                'category' => 'esporte',
                'campaign_type' => 'tiktok',
                'status' => 'approved',
                'deadline' => now()->addDays(20),
                'max_bids' => 12,
                'is_active' => true,
                'is_featured' => false,
            ],
        ];

        foreach ($campaigns as $campaignData) {
            Campaign::create($campaignData);
        }
    }
}
