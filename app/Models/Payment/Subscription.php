<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int              $id
 * @property int              $user_id
 * @property int              $subscription_plan_id
 * @property string           $status
 * @property null|Carbon      $starts_at
 * @property null|Carbon      $expires_at
 * @property float            $amount_paid
 * @property string           $payment_method
 * @property null|int         $transaction_id
 * @property bool             $auto_renew
 * @property null|Carbon      $cancelled_at
 * @property null|string      $stripe_subscription_id
 * @property null|string      $stripe_latest_invoice_id
 * @property null|string      $stripe_status
 * @property null|Carbon      $created_at
 * @property null|Carbon      $updated_at
 * @property User             $user
 * @property SubscriptionPlan $plan
 * @property null|Transaction $transaction
 * @property int              $remaining_days
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription active()
 * @method static \Illuminate\Database\Eloquent\Builder|Subscription expired()
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
class Subscription extends Model
{
    use HasFactory;

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_EXPIRED = 'expired';

    public const string STATUS_CANCELLED = 'cancelled';

    public const string STATUS_PENDING = 'pending';

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'status',
        'starts_at',
        'expires_at',
        'amount_paid',
        'payment_method',
        'transaction_id',
        'auto_renew',
        'cancelled_at',
        'stripe_subscription_id',
        'stripe_latest_invoice_id',
        'stripe_status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'amount_paid' => 'decimal:2',
        'auto_renew' => 'boolean',
        'cancelled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isActive(): bool
    {
        return self::STATUS_ACTIVE === $this->status
            && $this->expires_at?->isFuture();
    }

    public function isExpired(): bool
    {
        return self::STATUS_EXPIRED === $this->status
            || $this->expires_at?->isPast();
    }

    public function isCancelled(): bool
    {
        return self::STATUS_CANCELLED === $this->status;
    }

    public function getRemainingDaysAttribute(): int
    {
        if (!$this->expires_at) {
            return 0;
        }

        return max(0, now()->diffInDays($this->expires_at, false));
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('expires_at', '>', now())
        ;
    }

    public function scopeExpired($query)
    {
        return $query->where(function ($q): void {
            $q->where('status', self::STATUS_EXPIRED)
                ->orWhere('expires_at', '<=', now())
            ;
        });
    }
}
