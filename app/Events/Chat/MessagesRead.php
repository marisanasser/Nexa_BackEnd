<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Models\Chat\ChatRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessagesRead implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

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
     * @return array<int, Channel>
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
