<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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