<?php

namespace Database\Factories;

use App\Models\CampaignTimeline;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignTimelineFactory extends Factory
{
    protected $model = CampaignTimeline::class;

    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'milestone_type' => $this->faker->randomElement(['script_submission', 'script_approval', 'video_submission', 'final_approval']),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'deadline' => now()->addDays(30),
            'completed_at' => null,
            'status' => 'pending',
            'comment' => null,
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'file_type' => null,
            'justification' => null,
            'is_delayed' => false,
            'delay_notified_at' => null,
            'extension_days' => 0,
            'extension_reason' => null,
            'extended_at' => null,
            'extended_by' => null,
        ];
    }

    public function videoSubmission(): static
    {
        return $this->state(fn (array $attributes) => [
            'milestone_type' => 'video_submission',
            'title' => 'Envio de Imagem e Vídeo',
            'description' => 'Enviar o conteúdo final de imagem e vídeo',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
        ]);
    }
}
