<?php

declare(strict_types=1);

namespace Database\Factories;


use App\Models\Campaign\CampaignTimeline;
use App\Models\Contract\Contract;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryMaterialFactory extends Factory
{
    protected $model = DeliveryMaterial::class;

    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'creator_id' => User::factory()->state(['role' => 'creator']),
            'brand_id' => User::factory()->state(['role' => 'brand']),
            'milestone_id' => CampaignTimeline::factory(),
            'file_path' => 'delivery-materials/test-file.jpg',
            'file_name' => 'test-file.jpg',
            'file_type' => 'image/jpeg',
            'file_size' => $this->faker->numberBetween(100000, 5000000),
            'media_type' => $this->faker->randomElement(['image', 'video', 'document']),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'status' => 'pending',
            'submitted_at' => now(),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'rejection_reason' => null,
            'comment' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'reviewed_at' => now(),
            'reviewed_by' => User::factory()->state(['role' => 'brand']),
            'comment' => $this->faker->sentence(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'reviewed_at' => now(),
            'reviewed_by' => User::factory()->state(['role' => 'brand']),
            'rejection_reason' => $this->faker->sentence(),
            'comment' => $this->faker->sentence(),
        ]);
    }

    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'media_type' => 'image',
            'file_type' => 'image/jpeg',
            'file_name' => 'test-image.jpg',
        ]);
    }

    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'media_type' => 'video',
            'file_type' => 'video/mp4',
            'file_name' => 'test-video.mp4',
        ]);
    }
}
