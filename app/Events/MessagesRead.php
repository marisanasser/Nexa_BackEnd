<?php

namespace App\Events;

use App\Models\ChatRoom;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessagesRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $chatRoom;

    public $messageIds;

    public $readBy;

    public $readAt;

    /**
     * Create a new event instance.
     */
    public function __construct(ChatRoom $chatRoom, array $messageIds, int $readBy)
    {
        $this->chatRoom = $chatRoom;
        $this->messageIds = $messageIds;
        $this->readBy = $readBy;
        $this->readAt = now();
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.'.$this->chatRoom->room_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'messages_read';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'roomId' => $this->chatRoom->room_id,
            'messageIds' => $this->messageIds,
            'readBy' => $this->readBy,
            'timestamp' => $this->readAt->toISOString(),
        ];
    }
}
