<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign\Campaign;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\Message;
use App\Models\User\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_receives_guide_messages_on_first_open(): void
    {
        ['brand' => $brand, 'room' => $room] = $this->createChatContext();

        $response = $this->actingAs($brand)->getJson("/api/chat/rooms/{$room->room_id}/messages");

        $response->assertOk()->assertJsonPath('success', true);

        $messageTypes = collect($response->json('data.messages'))->pluck('message_type');

        $this->assertTrue($messageTypes->contains('system_brand'));
        $this->assertTrue($messageTypes->contains('system'));
        $this->assertFalse($messageTypes->contains('system_creator'));

        $this->assertDatabaseHas('messages', [
            'chat_room_id' => $room->id,
            'sender_id' => $brand->id,
            'message_type' => 'system_brand',
            'is_system_message' => true,
        ]);

        $this->assertDatabaseHas('messages', [
            'chat_room_id' => $room->id,
            'message_type' => 'system',
            'is_system_message' => true,
        ]);
    }

    public function test_creator_receives_creator_guide_message_on_first_open(): void
    {
        ['creator' => $creator, 'room' => $room] = $this->createChatContext();

        $response = $this->actingAs($creator)->getJson("/api/chat/rooms/{$room->room_id}/messages");

        $response->assertOk()->assertJsonPath('success', true);

        $messageTypes = collect($response->json('data.messages'))->pluck('message_type');

        $this->assertTrue($messageTypes->contains('system_creator'));
        $this->assertTrue($messageTypes->contains('system'));
        $this->assertFalse($messageTypes->contains('system_brand'));

        $this->assertDatabaseHas('messages', [
            'chat_room_id' => $room->id,
            'sender_id' => $creator->id,
            'message_type' => 'system_creator',
            'is_system_message' => true,
        ]);
    }

    public function test_google_drive_links_are_allowed_but_other_external_links_are_blocked(): void
    {
        ['brand' => $brand, 'room' => $room] = $this->createChatContext();

        $driveLink = 'https://drive.google.com/file/d/abc123/view?usp=sharing';
        $allowedResponse = $this->actingAs($brand)->postJson('/api/chat/messages', [
            'room_id' => $room->room_id,
            'message' => $driveLink,
        ]);

        $allowedResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message', $driveLink);

        $blockedResponse = $this->actingAs($brand)->postJson('/api/chat/messages', [
            'room_id' => $room->room_id,
            'message' => 'https://example.com/private-contact',
        ]);

        $blockedResponse
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.message', '[LINK REMOVIDO - MANTENHA A NEGOCIACAO AQUI]');
    }

    /**
     * @return array{brand: User, creator: User, room: ChatRoom}
     */
    private function createChatContext(): array
    {
        $brand = User::factory()->create([
            'role' => 'brand',
        ]);

        $creator = User::factory()->create([
            'role' => 'creator',
        ]);

        $campaign = Campaign::factory()->create([
            'brand_id' => $brand->id,
            'status' => 'approved',
            'deadline' => now()->addDays(14),
        ]);

        $room = ChatRoom::create([
            'campaign_id' => $campaign->id,
            'brand_id' => $brand->id,
            'creator_id' => $creator->id,
            'room_id' => ChatRoom::generateRoomId($campaign->id, $brand->id, $creator->id),
            'is_active' => true,
            'chat_status' => ChatRoom::STATUS_ACTIVE,
            'last_message_at' => now(),
        ]);

        Message::create([
            'chat_room_id' => $room->id,
            'sender_id' => $creator->id,
            'message' => 'Mensagem inicial',
            'message_type' => 'text',
        ]);

        return [
            'brand' => $brand,
            'creator' => $creator,
            'room' => $room,
        ];
    }
}
