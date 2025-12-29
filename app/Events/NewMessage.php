<?php

namespace App\Events;

use App\Models\ChatRoom;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewMessage implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public $chatRoom;

    public $offerData;

    public $contractData;

    /**
     * Create a new event instance.
     */
    public function __construct(Message $message, ChatRoom $chatRoom, ?array $offerData = null, ?array $contractData = null)
    {
        $this->message = $message;
        $this->chatRoom = $chatRoom;
        $this->offerData = $offerData;
        $this->contractData = $contractData;
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
        return 'new_message';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $sender = $this->message->sender;

        return [
            'roomId' => $this->chatRoom->room_id,
            'messageId' => $this->message->id,
            'message' => $this->message->message,
            'senderId' => $this->message->sender_id,
            'senderName' => $sender ? $sender->name : 'Unknown User',
            'senderAvatar' => $sender ? $sender->avatar_url : null,
            'messageType' => $this->message->message_type,
            'fileData' => $this->message->file_path ? [
                'file_path' => $this->message->file_path,
                'file_name' => $this->message->file_name,
                'file_size' => $this->message->file_size,
                'file_type' => $this->message->file_type,
                'file_url' => $this->message->file_url,
            ] : null,
            'offerData' => $this->offerData ?? ($this->message->offer_data ? json_decode($this->message->offer_data, true) : null),
            'contractData' => $this->contractData,
            'timestamp' => $this->message->created_at->toISOString(),
            'isSystemMessage' => $this->message->is_system_message,
        ];
    }
}
