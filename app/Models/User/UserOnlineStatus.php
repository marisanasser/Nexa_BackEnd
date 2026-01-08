<?php

declare(strict_types=1);

namespace App\Models\User;

use App\Events\User\UserStatusUpdated;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

use function in_array;

class UserOnlineStatus extends Model
{
    use HasFactory;

    protected $table = 'user_online_status';

    protected $fillable = [
        'user_id',
        'is_online',
        'last_seen_at',
        'socket_id',
        'typing_in_rooms',
    ];

    protected $casts = [
        'is_online' => 'boolean',
        'last_seen_at' => 'datetime',
        'typing_in_rooms' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updateOnlineStatus(bool $isOnline, ?string $socketId = null): void
    {
        $this->update([
            'is_online' => $isOnline,
            'socket_id' => $socketId,
            'last_seen_at' => now(),
        ]);

        // Broadcast the status update
        event(new UserStatusUpdated($this->user_id, $isOnline));
    }

    public function setTypingInRoom(string $roomId, bool $isTyping): void
    {
        $typingRooms = $this->typing_in_rooms ?? [];

        if ($isTyping) {
            if (!in_array($roomId, $typingRooms)) {
                $typingRooms[] = $roomId;
            }
        } else {
            $typingRooms = array_filter($typingRooms, fn ($room) => $room !== $roomId);
        }

        $this->update(['typing_in_rooms' => $typingRooms]);
    }

    public function isTypingInRoom(string $roomId): bool
    {
        $typingRooms = $this->typing_in_rooms ?? [];

        return in_array($roomId, $typingRooms);
    }
}
