<?php

declare(strict_types=1);

namespace App\Models\Chat;

use App\Models\Campaign\Campaign;
use App\Models\Contract\Offer;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int                  $id
 * @property int                  $campaign_id
 * @property int                  $brand_id
 * @property int                  $creator_id
 * @property string               $room_id
 * @property bool                 $is_active
 * @property null|Carbon          $last_message_at
 * @property null|Carbon          $created_at
 * @property null|Carbon          $updated_at
 * @property Campaign             $campaign
 * @property User                 $brand
 * @property User                 $creator
 * @property Collection|Message[] $messages
 * @property Collection|Offer[]   $offers
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class ChatRoom extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'brand_id',
        'creator_id',
        'room_id',
        'is_active',
        'last_message_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_message_at' => 'datetime',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'chat_room_id', 'id');
    }

    public function lastMessage(): HasMany
    {
        return $this->hasMany(Message::class)->latest('created_at');
    }

    public function updateLastMessageTimestamp(): void
    {
        $this->update(['last_message_at' => now()]);
    }

    public static function generateRoomId(int $campaignId, int $brandId, int $creatorId): string
    {
        return "room_{$campaignId}_{$brandId}_{$creatorId}";
    }

    public static function findOrCreateRoom(int $campaignId, int $brandId, int $creatorId): self
    {
        $roomId = self::generateRoomId($campaignId, $brandId, $creatorId);

        return self::firstOrCreate(
            [
                'campaign_id' => $campaignId,
                'brand_id' => $brandId,
                'creator_id' => $creatorId,
            ],
            [
                'room_id' => $roomId,
                'is_active' => true,
                'last_message_at' => now(),
            ]
        );
    }
}
