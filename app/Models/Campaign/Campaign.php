<?php

declare(strict_types=1);

namespace App\Models\Campaign;

use App\Domain\Notification\Services\CampaignNotificationService;
use App\Models\User\User;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int                              $id
 * @property int                              $brand_id
 * @property string                           $title
 * @property string                           $description
 * @property float                            $budget
 * @property null|string                      $remuneration_type
 * @property null|float                       $final_price
 * @property null|string                      $location
 * @property null|string                      $requirements
 * @property null|array                       $target_states
 * @property null|string                      $category
 * @property null|string                      $campaign_type
 * @property null|string                      $platform
 * @property null|int                         $min_followers
 * @property null|string                      $image_url
 * @property null|string                      $logo
 * @property null|array                       $attach_file
 * @property string                           $status
 * @property null|Carbon                      $deadline
 * @property null|Carbon                      $approved_at
 * @property null|int                         $approved_by
 * @property null|string                      $rejection_reason
 * @property null|int                         $max_bids
 * @property null|int                         $min_age
 * @property null|int                         $max_age
 * @property null|array                       $target_genders
 * @property null|array                       $target_creator_types
 * @property bool                             $is_active
 * @property bool                             $is_featured
 * @property null|Carbon                      $created_at
 * @property null|Carbon                      $updated_at
 * @property bool                             $is_favorited
 * @property User                             $brand
 * @property null|User                        $approvedBy
 * @property Bid[]|Collection                 $bids
 * @property CampaignApplication[]|Collection $applications
 * @property CampaignFavorite[]|Collection    $favorites
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Campaign newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Campaign newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Campaign query()
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
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

        if (auth()->check() && 'creator' === optional(auth()->user())->user_type) {
            return $this->isFavoritedBy(auth()->user()->igetKe);
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
        if (null !== $minAge) {
            $query->where('min_age', '<=', $minAge);
        }
        if (null !== $maxAge) {
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
        return 'pending' === $this->status;
    }

    public function isApproved(): bool
    {
        return 'approved' === $this->status;
    }

    public function isRejected(): bool
    {
        return 'rejected' === $this->status;
    }

    public function isCompleted(): bool
    {
        return 'completed' === $this->status;
    }

    public function isCancelled(): bool
    {
        return 'cancelled' === $this->status;
    }

    public function canReceiveBids(): bool
    {
        return $this->isApproved()
               && $this->is_active
               && $this->deadline >= now()->toDateString()
               && $this->bids()->count() < $this->max_bids;
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

        CampaignNotificationService::notifyBrandOfProjectStatus($this, 'approved');

        CampaignNotificationService::notifyCreatorsOfNewProject($this);

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

        CampaignNotificationService::notifyBrandOfProjectStatus($this, 'rejected', $reason);

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
        $value = $this->attributes['image_url'] ?? null;

        if (!$value) {
            return null;
        }

        return \App\Helpers\FileUploadHelper::resolveUrl($value);
    }

    public function getLogoAttribute()
    {
        $value = $this->attributes['logo'] ?? null;

        if (!$value) {
            return null;
        }

        return \App\Helpers\FileUploadHelper::resolveUrl($value);
    }

    public function getAttachFileAttribute()
    {
        $value = $this->attributes['attach_file'] ?? null;

        if (null === $value) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (null === $decoded && JSON_ERROR_NONE === json_last_error()) {
                return null;
            }

            return is_array($decoded) ? $decoded : (null !== $decoded ? [$decoded] : null);
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

    protected static function newFactory()
    {
        return CampaignFactory::new();
    }
}
