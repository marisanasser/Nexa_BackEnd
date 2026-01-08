<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\Contract\Contract;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Transaction model for payment records.
 *
 * @property int           $id
 * @property int           $user_id
 * @property null|int      $contract_id
 * @property null|string   $pagarme_transaction_id
 * @property null|string   $stripe_payment_intent_id
 * @property null|string   $stripe_charge_id
 * @property null|string   $refund_id
 * @property string        $status
 * @property float|string  $amount
 * @property null|string   $payment_method
 * @property null|string   $card_brand
 * @property null|string   $card_last4
 * @property null|string   $card_holder_name
 * @property null|array    $payment_data
 * @property null|Carbon   $paid_at
 * @property null|Carbon   $expires_at
 * @property null|Carbon   $refunded_at
 * @property null|array    $metadata
 * @property null|string   $type
 * @property null|Carbon   $created_at
 * @property null|Carbon   $updated_at
 * @property string        $amount_in_real
 * @property null|User     $user
 * @property null|Contract $contract
 *
 * @method        \Illuminate\Pagination\LengthAwarePaginator through(callable $callback)
 * @method        \Illuminate\Support\Collection              getCollection()
 * @method static \Illuminate\Pagination\LengthAwarePaginator paginate(int $perPage = null, array $columns = ['*'], string $pageName = 'page', int $page = null)
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @mixin \Eloquent
 */
class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pagarme_transaction_id',
        'stripe_payment_intent_id',
        'stripe_charge_id',
        'status',
        'amount',
        'payment_method',
        'card_brand',
        'card_last4',
        'card_holder_name',
        'payment_data',
        'paid_at',
        'expires_at',
        'metadata',
        'contract_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_data' => 'array',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function isPaid(): bool
    {
        return 'paid' === $this->status;
    }

    public function isPending(): bool
    {
        return 'pending' === $this->status;
    }

    public function isFailed(): bool
    {
        return 'failed' === $this->status;
    }

    public function isPremiumActive(): bool
    {
        return $this->isPaid() && $this->expires_at?->isFuture();
    }

    public function getAmountInRealAttribute(): string
    {
        return 'R$ '.number_format((float) ($this->amount ?? 0), 2, ',', '.');
    }
}
