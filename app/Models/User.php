<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'token',
        'password',
        'role',
        'whatsapp',
        'avatar_url',
        'avatar',
        'bio',
        'company_name',
        'profession',
        'whatsapp_number',
        'student_verified',
        'student_expires_at',
        'gender',
        'birth_date',
        'creator_type',
        'instagram_handle',
        'tiktok_handle',
        'youtube_channel',
        'facebook_page',
        'twitter_handle',
        'industry',
        'niche',
        'state',
        'language',
        'languages',
        'has_premium',
        'premium_expires_at',
        'free_trial_expires_at',
        'google_id',
        'google_token',
        'google_refresh_token',
        'recipient_id',
        'account_id',
        'email_verified_at',
        'bank_code',
        'agencia',
        'agencia_dv',
        'conta',
        'conta_dv',
        'cvc',
        'bank_account_name',
        'suspended_until',
        'suspension_reason',
        'total_reviews',
        'average_rating',
        'stripe_account_id',
        'stripe_payment_method_id',
        'stripe_verification_status',
        'stripe_customer_id'
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array
     */
    protected $attributes = [
        'gender' => 'other',
        'student_verified' => false,
        'has_premium' => false,
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'student_verified' => 'boolean',
        'student_expires_at' => 'datetime',
        'has_premium' => 'boolean',
        'premium_expires_at' => 'datetime',
        'free_trial_expires_at' => 'datetime',
        'suspended_until' => 'datetime',
        'birth_date' => 'date',
        'languages' => 'array',
    ];

    // Relationships
    public function emailTokens(){ return $this->hasMany(EmailToken::class); }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'brand_id');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class);
    }

    public function approvedCampaigns(): HasMany
    {
        return $this->hasMany(Campaign::class, 'approved_by');
    }

    public function campaignApplications(): HasMany
    {
        return $this->hasMany(CampaignApplication::class, 'creator_id');
    }

    public function reviewedApplications(): HasMany
    {
        return $this->hasMany(CampaignApplication::class, 'reviewed_by');
    }

    public function onlineStatus(): HasOne
    {
        return $this->hasOne(UserOnlineStatus::class);
    }

    public function sentMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function brandChatRooms(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'brand_id');
    }

    public function creatorChatRooms(): HasMany
    {
        return $this->hasMany(ChatRoom::class, 'creator_id');
    }

    public function brandDirectChatRooms(): HasMany
    {
        return $this->hasMany(DirectChatRoom::class, 'brand_id');
    }

    public function creatorDirectChatRooms(): HasMany
    {
        return $this->hasMany(DirectChatRoom::class, 'creator_id');
    }

    public function sentConnectionRequests(): HasMany
    {
        return $this->hasMany(ConnectionRequest::class, 'sender_id');
    }

    public function receivedConnectionRequests(): HasMany
    {
        return $this->hasMany(ConnectionRequest::class, 'receiver_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(\App\Models\Notification::class);
    }

    public function unreadNotifications(): HasMany
    {
        return $this->hasMany(\App\Models\Notification::class)->unread();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function portfolio(): HasOne
    {
        return $this->hasOne(Portfolio::class);
    }

    // New relationships for hiring system
    public function sentOffers(): HasMany
    {
        return $this->hasMany(Offer::class, 'brand_id');
    }

    public function receivedOffers(): HasMany
    {
        return $this->hasMany(Offer::class, 'creator_id');
    }

    public function brandContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'brand_id');
    }

    public function creatorContracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'creator_id');
    }

    public function givenReviews(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewer_id');
    }

    public function receivedReviews(): HasMany
    {
        return $this->hasMany(Review::class, 'reviewed_id');
    }

    /**
     * Update user's review statistics
     */
    public function updateReviewStats(): void
    {
        $reviews = $this->receivedReviews()->where('is_public', true);
        
        $this->total_reviews = $reviews->count();
        
        if ($this->total_reviews > 0) {
            $this->average_rating = $reviews->avg('rating');
        } else {
            $this->average_rating = null;
        }
        
        $this->save();
    }

    /**
     * Get formatted average rating
     */
    public function getFormattedAverageRatingAttribute(): string
    {
        if (!$this->average_rating) {
            return '0.0';
        }
        
        return number_format($this->average_rating, 1);
    }

    /**
     * Get rating stars for display
     */
    public function getRatingStarsAttribute(): string
    {
        if (!$this->average_rating) {
            return '☆☆☆☆☆';
        }
        
        $fullStars = floor($this->average_rating);
        $halfStar = $this->average_rating - $fullStars >= 0.5;
        $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);
        
        return str_repeat('★', $fullStars) . 
               ($halfStar ? '☆' : '') . 
               str_repeat('☆', $emptyStars);
    }

    /**
     * Get user's age based on birth date
     */
    public function getAgeAttribute(): ?int
    {
        if (!$this->birth_date) {
            return null;
        }
        
        return $this->birth_date->diffInYears(now());
    }

    public function brandPayments(): HasMany
    {
        return $this->hasMany(JobPayment::class, 'brand_id');
    }

    public function creatorPayments(): HasMany
    {
        return $this->hasMany(JobPayment::class, 'creator_id');
    }

    public function creatorBalance(): HasOne
    {
        return $this->hasOne(CreatorBalance::class, 'creator_id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(CampaignFavorite::class, 'creator_id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class, 'creator_id');
    }

    /**
     * Get the user's active subscription
     */
    public function activeSubscription(): HasOne
    {
        return $this->hasOne(\App\Models\Subscription::class)->where('status', 'active');
    }

    /**
     * Get all user subscriptions
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(\App\Models\Subscription::class);
    }

    /**
     * Check if the user has premium status.
     */
    public function isPremium(): bool
    {
        return $this->has_premium && 
               ($this->premium_expires_at === null || $this->premium_expires_at->isFuture());
    }

    /**
     * Check if the user is in free trial.
     */
    public function isOnTrial(): bool
    {
        // If user has premium access, they are not on trial
        if ($this->isPremium()) {
            return false;
        }
        
        return !$this->has_premium && 
               ($this->free_trial_expires_at !== null && $this->free_trial_expires_at->isFuture());
    }

    /**
     * Check if the user has bought premium (regardless of expiration).
     */
    public function hasBoughtPremium(): bool
    {
        return $this->has_premium;
    }

    /**
     * Check if the user has premium access (either premium or trial for students only).
     */
    public function hasPremiumAccess(): bool
    {
        // For students, prioritize premium over trial access
        if ($this->isStudent()) {
            return $this->isPremium() || $this->isOnTrial();
        }
        
        // For creators and brands, only allow premium access (no trial)
        return $this->isPremium();
    }

    /**
     * Check if the user is a verified student.
     */
    public function isVerifiedStudent(): bool
    {
        return $this->student_verified && 
               ($this->student_expires_at === null || $this->student_expires_at->isFuture());
    }

    /**
     * Check if the user is an admin.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if the user is a creator.
     */
    public function isCreator(): bool
    {
        return $this->role === 'creator';
    }

    /**
     * Check if the user is a brand.
     */
    public function isBrand(): bool
    {
        return $this->role === 'brand';
    }

    /**
     * Check if the user is a student.
     */
    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    /**
     * Get the user's display name (includes company name for brands).
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->isBrand() && $this->company_name) {
            return $this->name . ' (' . $this->company_name . ')';
        }
        return $this->name;
    }

    public function bankAccount()
    {
        return $this->hasOne(BankAccount::class);
    }

    public function brandPaymentMethods(): HasMany
    {
        return $this->hasMany(BrandPaymentMethod::class, 'user_id');
    }

    public function defaultPaymentMethod(): HasOne
    {
        return $this->hasOne(BrandPaymentMethod::class, 'user_id')->where('is_default', true);
    }

    /**
     * Check if the brand has any active payment methods
     */
    public function hasActivePaymentMethods(): bool
    {
        return $this->brandPaymentMethods()->active()->exists();
    }

    /**
     * Check if the brand has a default payment method
     */
    public function hasDefaultPaymentMethod(): bool
    {
        return $this->defaultPaymentMethod()->exists();
    }

    /**
     * Get the brand's default payment method
     */
    public function getDefaultPaymentMethod()
    {
        return $this->defaultPaymentMethod()->first();
    }

    /**
     * Check if the brand can send offers (has payment method)
     */
    public function canSendOffers(): bool
    {
        return $this->isBrand() && $this->hasActivePaymentMethods();
    }

    /**
     * Check if user is suspended
     */
    public function isSuspended(): bool
    {
        return $this->suspended_until && $this->suspended_until->isFuture();
    }

    /**
     * Get suspension status
     */
    public function getSuspensionStatus(): array
    {
        if (!$this->suspended_until) {
            return [
                'suspended' => false,
                'until' => null,
                'reason' => null,
                'remaining_days' => 0,
            ];
        }

        $remainingDays = max(0, now()->diffInDays($this->suspended_until, false));

        return [
            'suspended' => $this->suspended_until->isFuture(),
            'until' => $this->suspended_until,
            'reason' => $this->suspension_reason,
            'remaining_days' => $remainingDays,
        ];
    }

    /**
     * Suspend user
     */
    public function suspend(int $days, string $reason = null): bool
    {
        $this->update([
            'suspended_until' => now()->addDays($days),
            'suspension_reason' => $reason,
        ]);

        return true;
    }

    /**
     * Unsuspend user
     */
    public function unsuspend(): bool
    {
        $this->update([
            'suspended_until' => null,
            'suspension_reason' => null,
        ]);

        return true;
    }
}
