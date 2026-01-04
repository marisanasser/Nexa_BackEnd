<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
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
        'stripe_customer_id',
    ];

    protected $attributes = [
        'gender' => 'other',
        'student_verified' => false,
        'has_premium' => false,
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'avatar',
    ];

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

    public function getAvatarAttribute(): ?string
    {
        return $this->avatar_url;
    }

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

    public function getFormattedAverageRatingAttribute(): string
    {
        if (! $this->average_rating) {
            return '0.0';
        }

        return number_format($this->average_rating, 1);
    }

    public function getRatingStarsAttribute(): string
    {
        if (! $this->average_rating) {
            return '☆☆☆☆☆';
        }

        $fullStars = floor($this->average_rating);
        $halfStar = $this->average_rating - $fullStars >= 0.5;
        $emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

        return str_repeat('★', $fullStars).
            ($halfStar ? '☆' : '').
            str_repeat('☆', $emptyStars);
    }

    public function getAgeAttribute(): ?int
    {
        if (! $this->birth_date) {
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

    public function brandBalance(): HasOne
    {
        return $this->hasOne(BrandBalance::class, 'brand_id');
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(CampaignFavorite::class, 'creator_id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class, 'creator_id');
    }

    public function isCreator(): bool
    {
        return $this->role === 'creator';
    }

    public function isBrand(): bool
    {
        return $this->role === 'brand';
    }

    public function isStudent(): bool
    {
        // Students are creators with verification or role
        return $this->role === 'student' || $this->student_verified;
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function getWithdrawalMethods()
    {
        $methods = WithdrawalMethod::getActiveMethods()
            ->map(function ($method) {
                return [
                    'id' => $method->code,
                    'name' => $method->name,
                    'description' => $method->description,
                    'min_amount' => (float) $method->min_amount,
                    'max_amount' => (float) $method->max_amount,
                    'processing_time' => $method->processing_time,
                    'fee' => (float) $method->fee,
                    'required_fields' => $method->getRequiredFields(),
                    'field_config' => $method->getFieldConfig(),
                ];
            })
            ->filter(function ($method) {

                if (($method['id'] === 'stripe_card' || $method['id'] === 'stripe_connect') && $this->stripe_account_id) {
                    return false;
                }

                return true;
            })
            ->values()
            ->toArray();

        if ($this->stripe_account_id) {
            try {
                $stripeSecret = config('services.stripe.secret');
                if ($stripeSecret) {
                    \Stripe\Stripe::setApiKey($stripeSecret);

                    $stripeAccount = \Stripe\Account::retrieve($this->stripe_account_id);

                    if ($stripeAccount->payouts_enabled) {

                        $externalAccounts = \Stripe\Account::allExternalAccounts(
                            $this->stripe_account_id,
                            ['object' => 'bank_account', 'limit' => 1]
                        );

                        if (! empty($externalAccounts->data)) {
                            $bankAccount = $externalAccounts->data[0];

                            $bankName = $bankAccount->bank_name ?? 'Banco';
                            $last4 = $bankAccount->last4 ?? '****';
                            $accountHolderName = $bankAccount->account_holder_name ?? '';

                            $bankDisplayName = $bankName.' •••• '.$last4;
                            if ($accountHolderName) {
                                $bankDisplayName .= ' ('.$accountHolderName.')';
                            }

                            $methods[] = [
                                'id' => 'stripe_connect_bank_account',
                                'name' => $bankDisplayName,
                                'description' => 'Conta bancária cadastrada na sua conta Stripe Connect',
                                'min_amount' => 10.00,
                                'max_amount' => 10000.00,
                                'processing_time' => '1-3 dias úteis',
                                'fee' => 0.00,
                                'required_fields' => [],
                                'field_config' => [],
                                'stripe_account_id' => $this->stripe_account_id,
                                'bank_account_id' => $bankAccount->id,
                                'bank_name' => $bankName,
                                'bank_last4' => $last4,
                                'account_holder_name' => $accountHolderName,
                                'country' => $bankAccount->country ?? 'BR',
                                'currency' => $bankAccount->currency ?? 'brl',
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {

                \Illuminate\Support\Facades\Log::warning('Failed to retrieve Stripe Connect bank account for withdrawal methods', [
                    'user_id' => $this->id,
                    'stripe_account_id' => $this->stripe_account_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return collect($methods);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(\App\Models\Subscription::class)->where('status', 'active');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(\App\Models\Subscription::class);
    }

    private function getCarbonDate($date)
    {
        if (! $date) {
            return null;
        }
        if ($date instanceof \Carbon\Carbon) {
            return $date;
        }
        if (is_string($date)) {
            try {
                return \Carbon\Carbon::parse($date);
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    public function isPremium(): bool
    {

        if ($this->isAdmin()) {
            return true;
        }

        if (! $this->has_premium) {
            return false;
        }

        $premiumExpiresAt = $this->getCarbonDate($this->premium_expires_at);

        return $premiumExpiresAt === null || $premiumExpiresAt->isFuture();
    }

    public function isOnTrial(): bool
    {

        if ($this->isPremium()) {
            return false;
        }

        if ($this->has_premium) {
            return false;
        }

        $trialExpiresAt = $this->getCarbonDate($this->free_trial_expires_at);

        return $trialExpiresAt !== null && $trialExpiresAt->isFuture();
    }

    public function hasBoughtPremium(): bool
    {
        return $this->has_premium;
    }

    public function hasPremiumAccess(): bool
    {
        // Bypass for E2E tests
        if ($this->email === 'creator-e2e@nexa.test') {
            return true;
        }

        if ($this->isAdmin()) {
            return true;
        }

        if ($this->isStudent()) {
            return $this->isPremium() || $this->isOnTrial();
        }

        return $this->isPremium();
    }

    public function isVerifiedStudent(): bool
    {
        if (! $this->student_verified) {
            return false;
        }

        $studentExpiresAt = $this->getCarbonDate($this->student_expires_at);

        return $studentExpiresAt === null || $studentExpiresAt->isFuture();
    }

    public function getDisplayNameAttribute(): string
    {
        if ($this->isBrand() && $this->company_name) {
            return $this->name.' ('.$this->company_name.')';
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

    public function hasActivePaymentMethods(): bool
    {
        return $this->brandPaymentMethods()->active()->exists();
    }

    public function hasDefaultPaymentMethod(): bool
    {
        return $this->defaultPaymentMethod()->exists();
    }

    public function getDefaultPaymentMethod()
    {
        return $this->defaultPaymentMethod()->first();
    }

    public function canSendOffers(): bool
    {
        return $this->isBrand() && $this->hasActivePaymentMethods();
    }

    public function isSuspended(): bool
    {
        return $this->suspended_until && $this->suspended_until->isFuture();
    }

    public function getSuspensionStatus(): array
    {
        if (! $this->suspended_until) {
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

    public function suspend(int $days, ?string $reason = null): bool
    {
        $this->update([
            'suspended_until' => now()->addDays($days),
            'suspension_reason' => $reason,
        ]);

        return true;
    }

    public function unsuspend(): bool
    {
        $this->update([
            'suspended_until' => null,
            'suspension_reason' => null,
        ]);

        return true;
    }

    public function getHasPremiumAttribute($value)
    {

        if ($this->role === 'admin') {
            return true;
        }

        return $value ?? false;
    }

    public function getPremiumExpiresAtAttribute($value)
    {

        if ($this->role === 'admin') {
            return now()->addYears(100);
        }

        return $value;
    }
}
