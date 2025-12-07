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
        return $this->status === 'paid';
    }

    
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    
    public function isPremiumActive(): bool
    {
        return $this->isPaid() && $this->expires_at && $this->expires_at->isFuture();
    }

    
    public function getAmountInRealAttribute(): string
    {
        return 'R$ ' . number_format($this->amount, 2, ',', '.');
    }
}
