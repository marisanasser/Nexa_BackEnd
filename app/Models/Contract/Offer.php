<?php

declare(strict_types=1);

namespace App\Models\Contract;

use App\Models\Campaign\Campaign;
use App\Models\Chat\ChatRoom;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $brand_id
 * @property int $creator_id
 * @property int $chat_room_id
 * @property int|null $campaign_id
 * @property string $title
 * @property string $description
 * @property float $budget
 * @property int $estimated_days
 * @property array $requirements
 * @property string $status
 * @property Carbon|null $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read string $formatted_budget
 * @property-read int $days_until_expiry
 * @property-read bool $is_expiring_soon
 * @property-read User $brand
 * @property-read User $creator
 * @property-read Contract|null $contract
 * @property-read Campaign|null $campaign
 * @property-read ChatRoom|null $chatRoom
 */
class Offer extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'creator_id',
        'chat_room_id',
        'campaign_id',
        'title',
        'description',
        'budget',
        'estimated_days',
        'requirements',
        'status',
        'expires_at',
        'accepted_at',
        'rejected_at',
        'rejection_reason',
    ];

    protected $casts = [
        'budget' => 'decimal:2',
        'requirements' => 'array',
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function chatRoom(): BelongsTo
    {
        return $this->belongsTo(ChatRoom::class);
    }

    public function getFormattedBudgetAttribute(): string
    {
        return 'R$ ' . number_format((float) $this->budget, 2, ',', '.');
    }

    public function getDaysUntilExpiryAttribute(): int
    {
        if (!$this->expires_at) {
            return 0;
        }

        return max(0, now()->diffInDays($this->expires_at, false));
    }

    public function getIsExpiringSoonAttribute(): bool
    {
        return $this->days_until_expiry <= 2 && $this->days_until_expiry > 0;
    }

    public function isExpired(): bool
    {
        return $this?->expires_at?->isPast();
    }

    public function canBeAccepted(): bool
    {
        return 'pending' === $this->status && !$this->isExpired();
    }

    public function canBeRejected(): bool
    {
        return 'pending' === $this->status;
    }

    public function canBeCancelled(): bool
    {
        return 'pending' === $this->status;
    }

    public function accept(): bool
    {
        if (!$this->canBeAccepted()) {
            return false;
        }

        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        if (!$this->contract) {
            Contract::create([
                'offer_id' => $this->id,
                'brand_id' => $this->brand_id,
                'creator_id' => $this->creator_id,
                'title' => $this->title,
                'description' => $this->description,
                'budget' => $this->budget,
                'estimated_days' => $this->estimated_days,
                'requirements' => $this->requirements,
                'status' => 'active',
                'workflow_status' => 'active',
                'created_at' => now(),
            ]);
            $this->refresh();
        }

        return true;
    }

    public function reject(?string $reason = null): bool
    {
        if (!$this->canBeRejected()) {
            return false;
        }

        $this->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return true;
    }
}
