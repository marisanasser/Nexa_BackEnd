<?php

declare(strict_types=1);

namespace App\Traits;

use App\Events\Chat\NewMessage;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\Message;
use Exception;
use Illuminate\Support\Facades\Log;

trait OfferChatMessageTrait
{
    private function createOfferChatMessage(ChatRoom $chatRoom, string $messageType, array $data = []): ?Message
    {
        try {
            $messageData = [
                'chat_room_id' => $chatRoom->id,
                'sender_id' => $data['sender_id'] ?? null,
                'message' => $data['message'] ?? '',
                'message_type' => 'offer',
                'offer_data' => json_encode($data['offer_data'] ?? []),
            ];

            $message = Message::create($messageData);

            $chatRoom->update(['last_message_at' => now()]);

            $message->load('sender');

            event(new NewMessage($message, $chatRoom, $data['offer_data'] ?? null));

            return $message;
        } catch (Exception $e) {
            Log::error('Failed to create offer chat message', [
                'chat_room_id' => $chatRoom->id,
                'message_type' => $messageType,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function createSystemMessage(ChatRoom $chatRoom, string $message, array $data = []): ?Message
    {
        try {
            $messageData = [
                'chat_room_id' => $chatRoom->id,
                'sender_id' => null,
                'message' => $message,
                'message_type' => 'system',
                'offer_data' => json_encode($data),
            ];

            $systemMessage = Message::create($messageData);

            $chatRoom->update(['last_message_at' => now()]);

            event(new NewMessage($systemMessage, $chatRoom, $data));

            return $systemMessage;
        } catch (Exception $e) {
            Log::error('Failed to create system message', [
                'chat_room_id' => $chatRoom->id,
                'message' => $message,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

}
