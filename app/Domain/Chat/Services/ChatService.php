<?php

declare(strict_types=1);

namespace App\Domain\Chat\Services;

use App\Models\Chat\DirectChatRoom;
use App\Models\Chat\DirectMessage;
use App\Models\User\User;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * ChatService handles messaging operations.
 *
 * Responsibilities:
 * - Creating and managing conversations
 * - Sending and receiving messages
 * - Managing read status
 * - Handling message notifications
 */
class ChatService
{
    /**
     * Get or create a chat room between brand and creator.
     */
    public function getOrCreateRoom(User $brand, User $creator, ?int $connectionRequestId = null): DirectChatRoom
    {
        return DirectChatRoom::findOrCreateRoom($brand->id, $creator->id, $connectionRequestId);
    }

    /**
     * Send a message in a chat room.
     */
    public function sendMessage(
        DirectChatRoom $room,
        User $sender,
        string $content,
        ?array $offerData = null,
        ?string $messageType = 'text'
    ): DirectMessage {
        // Verify sender is part of room
        if (!$room->isParticipant($sender->id)) {
            throw new Exception('User is not a participant of this chat room');
        }

        $message = DirectMessage::create([
            'direct_chat_room_id' => $room->id,
            'sender_id' => $sender->id,
            'message' => $content,
            'message_type' => $messageType,
            'offer_data' => $offerData,
        ]);

        // Update room last message time
        $room->update(['last_message_at' => now()]);

        Log::info('Message sent', [
            'room_id' => $room->id,
            'message_id' => $message->id,
            'sender_id' => $sender->id,
        ]);

        return $message;
    }

    /**
     * Get messages for a chat room.
     */
    public function getMessages(
        DirectChatRoom $room,
        int $limit = 50,
        ?int $beforeId = null
    ): Collection {
        $query = DirectMessage::where('direct_chat_room_id', $room->id)
            ->with('sender')
            ->orderBy('created_at', 'desc')
        ;

        if ($beforeId) {
            $query->where('id', '<', $beforeId);
        }

        return $query->limit($limit)->get()->reverse()->values();
    }

    /**
     * Get chat rooms for a user.
     */
    public function getRoomsForUser(User $user, int $limit = 20): Collection
    {
        return DirectChatRoom::where('brand_id', $user->id)
            ->orWhere('creator_id', $user->id)
            ->with(['brand', 'creator'])
            ->orderBy('last_message_at', 'desc')
            ->limit($limit)
            ->get()
            ->map(function ($room) use ($user) {
                $room->other_user = $room->getOtherUser($user->id);
                $room->unread_count = $this->getUnreadCount($room, $user);

                return $room;
            })
        ;
    }

    /**
     * Mark messages as read.
     */
    public function markAsRead(DirectChatRoom $room, User $user): int
    {
        $count = DirectMessage::where('direct_chat_room_id', $room->id)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()])
        ;

        if ($count > 0) {
            Log::info('Messages marked as read', [
                'room_id' => $room->id,
                'user_id' => $user->id,
                'count' => $count,
            ]);
        }

        return $count;
    }

    /**
     * Get unread count for a chat room.
     */
    public function getUnreadCount(DirectChatRoom $room, User $user): int
    {
        return DirectMessage::query()
            ->where('direct_chat_room_id', $room->id)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count()
        ;
    }

    /**
     * Get total unread messages for a user.
     */
    public function getTotalUnreadCount(User $user): int
    {
        $roomIds = DirectChatRoom::where('brand_id', $user->id)
            ->orWhere('creator_id', $user->id)
            ->pluck('id')
        ;

        return DirectMessage::whereIn('direct_chat_room_id', $roomIds)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count()
        ;
    }

    /**
     * Find chat room by ID.
     */
    public function findRoom(int $roomId): ?DirectChatRoom
    {
        return DirectChatRoom::find($roomId);
    }

    /**
     * Find chat room between two users.
     */
    public function findRoomBetweenUsers(int $brandId, int $creatorId): ?DirectChatRoom
    {
        return DirectChatRoom::where('brand_id', $brandId)
            ->where('creator_id', $creatorId)
            ->first()
        ;
    }

    /**
     * Get latest message from room.
     */
    public function getLatestMessage(DirectChatRoom $room): ?DirectMessage
    {
        return DirectMessage::where('direct_chat_room_id', $room->id)
            ->latest()
            ->first()
        ;
    }
}
