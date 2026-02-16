<?php

declare(strict_types=1);

namespace App\Events\Contract;

use App\Models\Chat\ChatRoom;
use App\Models\Contract\Contract;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ContractUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public $contract;

    public $chatRoom;

    public $senderId;

    /**
     * @var array<string, mixed>
     */
    public $metadata;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(Contract $contract, ?ChatRoom $chatRoom, int $senderId, array $metadata = [])
    {
        $this->contract = $contract;
        $this->chatRoom = $chatRoom;
        $this->senderId = $senderId;
        $this->metadata = $metadata;
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        if (!$this->chatRoom) {
            return [];
        }

        return [
            new PrivateChannel('chat.'.$this->chatRoom->room_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'contract_updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'roomId' => $this->chatRoom?->room_id,
            'contractData' => [
                'id' => $this->contract->id,
                'status' => $this->contract->status,
                'workflow_status' => $this->contract->workflow_status,
                'tracking_code' => $this->contract->tracking_code,
                'updated_at' => $this->contract->updated_at?->format('Y-m-d H:i:s'),
            ],
            'metadata' => $this->metadata,
            'senderId' => $this->senderId,
        ];
    }
}
