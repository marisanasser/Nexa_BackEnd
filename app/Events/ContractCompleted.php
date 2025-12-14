<?php

namespace App\Events;

use App\Models\ChatRoom;
use App\Models\Contract;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractCompleted implements ShouldBroadcast
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
        return 'contract_completed';
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
                'can_be_completed' => $this->contract->canBeCompleted(),
                'can_be_cancelled' => $this->contract->canBeCancelled(),
                'can_be_terminated' => $this->contract->canBeTerminated(),
                'can_be_started' => $this->contract->canBeStarted(),
                'budget' => $this->contract->formatted_budget,
                'creator_amount' => $this->contract->formatted_creator_amount,
                'platform_fee' => $this->contract->formatted_platform_fee,
                'estimated_days' => $this->contract->estimated_days,
                'started_at' => $this->contract->started_at?->format('Y-m-d H:i:s'),
                'expected_completion_at' => $this->contract->expected_completion_at?->format('Y-m-d H:i:s'),
                'completed_at' => $this->contract->completed_at?->format('Y-m-d H:i:s'),
                'days_until_completion' => $this->contract->days_until_completion,
                'progress_percentage' => $this->contract->progress_percentage,
                'is_overdue' => $this->contract->isOverdue(),
                'is_near_completion' => $this->contract->is_near_completion,
                'can_review' => true,
            ],
            'senderId' => $this->senderId,
        ];
    }
}
