<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandPaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'pagarme_customer_id',
        'pagarme_card_id',
        'stripe_customer_id',
        'stripe_payment_method_id',
        'stripe_setup_intent_id',
        'card_brand',
        'card_last4',
        'card_holder_name',
        'card_hash',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Get the user that owns this payment method
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope to get only active payment methods
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get default payment method
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Set this payment method as default and unset others
     */
    public function setAsDefault(): void
    {
        // Unset other default payment methods for this user
        static::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        // Set this one as default
        $this->update(['is_default' => true]);
    }

    /**
     * Get masked card number for display
     */
    public function getMaskedCardNumberAttribute(): string
    {
        if ($this->card_last4) {
            return '**** **** **** ' . $this->card_last4;
        }
        return '**** **** **** ****';
    }

    /**
     * Get formatted card info for display
     */
    public function getFormattedCardInfoAttribute(): string
    {
        $brand = $this->card_brand ? ucfirst($this->card_brand) : 'Card';
        return $brand . ' •••• ' . $this->card_last4;
    }
} 