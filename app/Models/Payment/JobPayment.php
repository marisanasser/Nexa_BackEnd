<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Domain\Notification\Services\PaymentNotificationService;
use App\Models\Contract\Contract;
use App\Models\User\User;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * JobPayment model for tracking contract payments.
 *
 * @property int           $id
 * @property int           $contract_id
 * @property int           $brand_id
 * @property int           $creator_id
 * @property float|string  $total_amount
 * @property float|string  $platform_fee
 * @property float|string  $creator_amount
 * @property null|string   $payment_method
 * @property null|string   $transaction_id
 * @property string        $status
 * @property null|Carbon   $paid_at
 * @property null|Carbon   $processed_at
 * @property null|Carbon   $refunded_at
 * @property null|Carbon   $cancelled_at
 * @property null|array    $payment_data
 * @property null|Carbon   $created_at
 * @property null|Carbon   $updated_at
 * @property string        $formatted_total_amount
 * @property string        $formatted_creator_amount
 * @property string        $formatted_platform_fee
 * @property string        $status_color
 * @property string        $status_badge_color
 * @property null|Contract $contract
 * @property null|User     $brand
 * @property null|User     $creator
 */
class JobPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id',
        'brand_id',
        'creator_id',
        'total_amount',
        'platform_fee',
        'creator_amount',
        'payment_method',
        'transaction_id',
        'status',
        'paid_at',
        'processed_at',
        'payment_data',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'creator_amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'processed_at' => 'datetime',
        'payment_data' => 'array',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeRefunded($query)
    {
        return $query->where('status', 'refunded');
    }

    public function isPending(): bool
    {
        return 'pending' === $this->status;
    }

    public function isProcessing(): bool
    {
        return 'processing' === $this->status;
    }

    public function isCompleted(): bool
    {
        return 'completed' === $this->status;
    }

    public function isFailed(): bool
    {
        return 'failed' === $this->status;
    }

    public function isRefunded(): bool
    {
        return 'refunded' === $this->status;
    }

    public function canBeProcessed(): bool
    {
        return $this->isPending();
    }

    public function process(): bool
    {
        if (!$this->canBeProcessed()) {
            return false;
        }

        $this->update([
            'status' => 'processing',
        ]);

        try {
            $this->processPayment();

            $this->update([
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            $this->updateCreatorBalance();

            PaymentNotificationService::notifyUserOfPaymentCompleted($this);

            return true;
        } catch (Exception $e) {
            $this->update([
                'status' => 'failed',
            ]);

            PaymentNotificationService::notifyUserOfPaymentFailed($this, $e->getMessage());

            return false;
        }
    }

    public function refund(?string $reason = null): bool
    {
        if (!$this->isCompleted()) {
            return false;
        }

        $this->update([
            'status' => 'refunded',
        ]);

        $balance = CreatorBalance::where('creator_id', $this->creator_id)->first();
        if ($balance) {
            $balance->decrement('available_balance', (float) $this->creator_amount);
            $balance->decrement('total_earned', (float) $this->creator_amount);
        }

        PaymentNotificationService::notifyUserOfPaymentRefunded($this, $reason);

        return true;
    }

    public function getFormattedTotalAmountAttribute(): string
    {
        return 'R$ '.number_format((float) ($this->total_amount ?? 0), 2, ',', '.');
    }

    public function getFormattedCreatorAmountAttribute(): string
    {
        return 'R$ '.number_format((float) ($this->creator_amount ?? 0), 2, ',', '.');
    }

    public function getFormattedPlatformFeeAttribute(): string
    {
        return 'R$ '.number_format((float) ($this->platform_fee ?? 0), 2, ',', '.');
    }

    public function getStatusColorAttribute(): string
    {
        switch ($this->status) {
            case 'completed':
                return 'text-green-600';

            case 'processing':
                return 'text-blue-600';

            case 'pending':
                return 'text-yellow-600';

            case 'failed':
                return 'text-red-600';

            case 'refunded':
                return 'text-gray-600';

            default:
                return 'text-gray-600';
        }
    }

    public function getStatusBadgeColorAttribute(): string
    {
        switch ($this->status) {
            case 'completed':
                return 'bg-green-100 text-green-800';

            case 'processing':
                return 'bg-blue-100 text-blue-800';

            case 'pending':
                return 'bg-yellow-100 text-yellow-800';

            case 'failed':
                return 'bg-red-100 text-red-800';

            case 'refunded':
                return 'bg-gray-100 text-gray-800';

            default:
                return 'bg-gray-100 text-gray-800';
        }
    }

    private function processPayment(): void
    {
        sleep(1);

        $this->update([
            'transaction_id' => 'TXN_'.time().'_'.$this->id,
        ]);
    }

    private function updateCreatorBalance(): void
    {
        $balance = CreatorBalance::firstOrCreate(
            ['creator_id' => $this->creator_id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_earned' => 0,
                'total_withdrawn' => 0,
            ]
        );

        $balance->decrement('pending_balance', (float) $this->creator_amount);
        $balance->increment('available_balance', (float) $this->creator_amount);
    }
}
