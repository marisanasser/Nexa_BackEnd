<?php

namespace App\Models;

use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'title',
        'description',
        'budget',
        'remuneration_type',

        'final_price',
        'location',
        'requirements',
        'target_states',
        'category',
        'campaign_type',
        'image_url',
        'logo',
        'attach_file',
        'status',
        'deadline',
        'approved_at',
        'approved_by',
        'rejection_reason',
        'max_bids',
        'min_age',
        'max_age',
        'target_genders',
        'target_creator_types',
        'is_active',
        'is_featured',
    ];

    protected $casts = [
        'target_states' => 'array',
        'target_genders' => 'array',
        'target_creator_types' => 'array',
        'attach_file' => 'array',
        'deadline' => 'date',
        'approved_at' => 'datetime',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',

        'budget' => 'decimal:2',
        'final_price' => 'decimal:2',
    ];

    protected $appends = ['is_favorited'];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(CampaignApplication::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(CampaignFavorite::class);
    }

    public function isFavoritedBy($creatorId): bool
    {
        return $this->favorites()->where('creator_id', $creatorId)->exists();
    }

    public function getIsFavoritedAttribute(): bool
    {

        if (isset($this->attributes['is_favorited'])) {
            return (bool) $this->attributes['is_favorited'];
        }

        if (auth()->check() && optional(auth()->user())->user_type === 'creator') {
            return $this->isFavoritedBy(auth()->user()->id);
        }

        return false;
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForState($query, $state)
    {
        return $query->whereJsonContains('target_states', $state);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('campaign_type', $type);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }

    public function scopeForAgeRange($query, $minAge = null, $maxAge = null)
    {
        if ($minAge !== null) {
            $query->where('min_age', '<=', $minAge);
        }
        if ($maxAge !== null) {
            $query->where('max_age', '>=', $maxAge);
        }

        return $query;
    }

    public function scopeForGender($query, $genders)
    {
        if (empty($genders)) {
            return $query;
        }

        return $query->whereJsonOverlaps('target_genders', $genders);
    }

    public function scopeForCreatorType($query, $creatorTypes)
    {
        if (empty($creatorTypes)) {
            return $query;
        }

        return $query->whereJsonOverlaps('target_creator_types', $creatorTypes);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function canReceiveBids(): bool
    {
        return $this->isApproved() &&
               $this->is_active &&
               $this->deadline >= now()->toDateString() &&
               $this->bids()->count() < $this->max_bids;
    }

    public function approve($adminId): bool
    {
        $this->update([
            'status' => 'approved',
            'is_active' => true,
            'approved_at' => now(),
            'approved_by' => $adminId,
            'rejection_reason' => null,
        ]);

        NotificationService::notifyBrandOfProjectStatus($this, 'approved');

        NotificationService::notifyCreatorsOfNewProject($this);

        return true;
    }

    public function reject($adminId, ?string $reason = null): bool
    {
        $this->update([
            'status' => 'rejected',
            'approved_at' => null,
            'approved_by' => $adminId,
            'rejection_reason' => $reason,
        ]);

        NotificationService::notifyBrandOfProjectStatus($this, 'rejected', $reason);

        return true;
    }

    public function complete(): bool
    {
        $this->update([
            'status' => 'completed',
            'is_active' => false,
        ]);

        return true;
    }

    public function cancel(): bool
    {
        $this->update([
            'status' => 'cancelled',
            'is_active' => false,
        ]);

        return true;
    }

    public function getTotalBidsAttribute(): int
    {
        return $this->bids()->count();
    }

    public function getAcceptedBidAttribute()
    {
        return $this->bids()->where('status', 'accepted')->first();
    }

    public function hasAcceptedBid(): bool
    {
        return $this->bids()->where('status', 'accepted')->exists();
    }

    public function getRemunerationTypeAttribute()
    {
        return $this->attributes['remuneration_type'] ?? null;
    }

    public function getRequirementsAttribute()
    {
        return $this->attributes['requirements'] ?? null;
    }

    public function getCampaignTypeAttribute()
    {
        return $this->attributes['campaign_type'] ?? null;
    }

    public function getMinAgeAttribute()
    {
        return $this->attributes['min_age'] ?? null;
    }

    public function getMaxAgeAttribute()
    {
        return $this->attributes['max_age'] ?? null;
    }

    public function getIsActiveAttribute()
    {
        return $this->attributes['is_active'] ?? null;
    }

    public function getIsFeaturedAttribute()
    {
        return $this->attributes['is_featured'] ?? null;
    }

    public function getImageUrlAttribute()
    {
        return $this->attributes['image_url'] ?? null;
    }

    public function getLogoAttribute()
    {
        return $this->attributes['logo'] ?? null;
    }

    public function getAttachFileAttribute()
    {

        $value = $this->attributes['attach_file'] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if ($decoded === null && json_last_error() === JSON_ERROR_NONE) {
                return null;
            }

            return is_array($decoded) ? $decoded : ($decoded !== null ? [$decoded] : null);
        }

        return null;
    }

    public function getRejectionReasonAttribute()
    {
        return $this->attributes['rejection_reason'] ?? null;
    }

    public function getMaxBidsAttribute()
    {
        return $this->attributes['max_bids'] ?? null;
    }

    public function getApprovedAtAttribute()
    {
        return $this->attributes['approved_at'] ?? null;
    }

    public function getApprovedByAttribute()
    {
        return $this->attributes['approved_by'] ?? null;
    }
}
