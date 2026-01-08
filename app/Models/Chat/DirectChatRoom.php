<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Models\Common\ConnectionRequest;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * DirectChatRoom model for direct messaging between users.
 *
 * @property int                        $id
 * @property int                        $brand_id
 * @property int                        $creator_id
 * @property string                     $room_id
 * @property bool                       $is_active
 * @property null|Carbon                $last_message_at
 * @property null|int                   $connection_request_id
 * @property null|Carbon                $created_at
 * @property null|Carbon                $updated_at
 * @property null|User                  $brand
 * @property null|User                  $creator
 * @property null|ConnectionRequest     $connectionRequest
 * @property Collection|DirectMessage[] $messages
 */
class DirectChatRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'creator_id',
        'room_id',
        'is_active',
        'last_message_at',
        'connection_request_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function connectionRequest(): BelongsTo
    {
        return $this->belongsTo(ConnectionRequest::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DirectMessage::class)->orderBy('created_at', 'asc');
    }

    public function lastMessage(): HasMany
    {
        return $this->hasMany(DirectMessage::class)->latest();
    }

    public static function generateRoomId(int $brandId, int $creatorId): string
    {
        return "direct_room_{$brandId}_{$creatorId}";
    }

    public static function findOrCreateRoom(int $brandId, int $creatorId, ?int $connectionRequestId = null): self
    {
        $roomId = self::generateRoomId($brandId, $creatorId);

        return self::firstOrCreate(
            [
                'brand_id' => $brandId,
                'creator_id' => $creatorId,
            ],
            [
                'room_id' => $roomId,
                'is_active' => true,
                'connection_request_id' => $connectionRequestId,
                'last_message_at' => now(),
            ]
        );
    }

    public function getOtherUser($currentUserId)
    {
        if ($currentUserId == $this->brand_id) {
            return $this->creator;
        }

        return $this->brand;
    }

    public function isParticipant($userId): bool
    {
        return $userId == $this->brand_id || $userId == $this->creator_id;
    }
}
