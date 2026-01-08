<?php

declare(strict_types=1);

namespace App\Events\Chat;

use App\Models\User\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserTyping implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public $roomId;

    public $userId;

    public $userName;

    public $isTyping;

    /**
     * Create a new event instance.
     */
    public function __construct(string $roomId, User $user, bool $isTyping)
    {
        $this->roomId = $roomId;
        $this->userId = $user->id;
        $this->userName = $user->name;
        $this->isTyping = $isTyping;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.'.$this->roomId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'user_typing';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'roomId' => $this->roomId,
            'userId' => $this->userId,
            'userName' => $this->userName,
            'isTyping' => $this->isTyping,
        ];
    }
}
