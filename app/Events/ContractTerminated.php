<?php

namespace App\Events;

use App\Models\Contract;
use App\Models\ChatRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractTerminated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $contract;
    public $chatRoom;
    public $senderId;
    public $reason;

    /**
     * Create a new event instance.
     */
    public function __construct(Contract $contract, ?ChatRoom $chatRoom, int $senderId, ?string $reason)
    {
        $this->contract = $contract;
        $this->chatRoom = $chatRoom;
        $this->senderId = $senderId;
        $this->reason = $reason;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        if (!$this->chatRoom) {
            return [];
        }
        return [
            new PrivateChannel('chat.' . $this->chatRoom->room_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'contract_terminated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'roomId' => $this->chatRoom?->room_id,
            'contractData' => [
                'id' => $this->contract->id,
                'title' => $this->contract->title,
                'description' => $this->contract->description,
                'status' => $this->contract->status,
                'workflow_status' => $this->contract->workflow_status,
                'brand_id' => $this->contract->brand_id,
                'creator_id' => $this->contract->creator_id,
                'cancelled_at' => $this->contract->cancelled_at?->format('Y-m-d H:i:s'),
                'cancellation_reason' => $this->contract->cancellation_reason,
            ],
            'senderId' => $this->senderId,
            'terminationReason' => $this->reason,
        ];
    }
}
