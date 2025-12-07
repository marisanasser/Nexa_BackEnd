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

    
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    
    public function setAsDefault(): void
    {
        
        static::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        
        $this->update(['is_default' => true]);
    }

    
    public function getMaskedCardNumberAttribute(): string
    {
        if ($this->card_last4) {
            return '**** **** **** ' . $this->card_last4;
        }
        return '**** **** **** ****';
    }

    
    public function getFormattedCardInfoAttribute(): string
    {
        $brand = $this->card_brand ? ucfirst($this->card_brand) : 'Card';
        return $brand . ' •••• ' . $this->card_last4;
    }
} 