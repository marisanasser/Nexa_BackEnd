<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChatRoomFactory extends Factory
{
    protected $model = ChatRoom::class;

    public function definition(): array
    {
        return [
            'campaign_id' => Campaign::factory(),
            'brand_id' => User::factory()->state(['role' => 'brand']),
            'creator_id' => User::factory()->state(['role' => 'creator']),
            'room_id' => $this->faker->uuid(),
            'is_active' => true,
            'last_message_at' => null,
        ];
    }
}
