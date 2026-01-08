<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contract\Contract;
use App\Models\Contract\Offer;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'brand_id' => User::factory()->state(['role' => 'brand']),
            'creator_id' => User::factory()->state(['role' => 'creator']),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'budget' => $this->faker->randomFloat(2, 100, 10000),
            'estimated_days' => $this->faker->numberBetween(7, 90),
            'requirements' => json_encode([
                'format' => 'video',
                'duration' => '60 seconds',
                'style' => 'modern',
            ]),
            'status' => 'active',
            'started_at' => now(),
            'expected_completion_at' => now()->addDays(30),
            'completed_at' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'platform_fee' => 100.00,
            'creator_amount' => 900.00,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }
}
