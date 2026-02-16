<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Chat\ChatRoom;
use App\Models\Contract\Offer;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        return [
            'brand_id' => User::factory()->state(['role' => 'brand']),
            'creator_id' => User::factory()->state(['role' => 'creator']),
            'chat_room_id' => ChatRoom::factory(),
            'title' => $this->faker->sentence(3),
            'description' => $this->faker->paragraph(),
            'budget' => $this->faker->randomFloat(2, 100, 10000),
            'estimated_days' => $this->faker->numberBetween(7, 90),
            'requirements' => json_encode([
                'format' => 'video',
                'duration' => '60 seconds',
                'style' => 'modern',
            ]),
            'status' => 'pending',
            'expires_at' => now()->addDays(30),
            'accepted_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'rejected_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'expired',
            'expired_at' => now()->subDay(),
        ]);
    }
}
