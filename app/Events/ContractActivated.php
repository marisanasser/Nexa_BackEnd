<?php

namespace App\Events;

use App\Models\ChatRoom;
use App\Models\Contract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractActivated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $contract;

    public $chatRoom;

    public $senderId;

    /**
     * Create a new event instance.
     */
    public function __construct(Contract $contract, ?ChatRoom $chatRoom, int $senderId)
    {
        $this->contract = $contract;
        $this->chatRoom = $chatRoom;
        $this->senderId = $senderId;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        if (! $this->chatRoom) {
            return [];
        }

        return [
            new PrivateChannel('chat.'.$this->chatRoom->room_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'contract_activated';
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
                'activated_at' => $this->contract->activated_at?->format('Y-m-d H:i:s'),
            ],
            'senderId' => $this->senderId,
        ];
    }
}
