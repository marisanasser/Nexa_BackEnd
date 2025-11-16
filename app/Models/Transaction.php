<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    /**
     * Get the user that owns the transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the contract associated with this transaction.
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * Check if the transaction is paid.
     */
    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    /**
     * Check if the transaction is pending.
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if the transaction is failed.
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if the premium is still active.
     */
    public function isPremiumActive(): bool
    {
        return $this->isPaid() && $this->expires_at && $this->expires_at->isFuture();
    }

    /**
     * Get the amount in Brazilian Real format.
     */
    public function getAmountInRealAttribute(): string
    {
        return 'R$ ' . number_format($this->amount, 2, ',', '.');
    }
}
