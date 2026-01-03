<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'stripe_price_id',
        'stripe_product_id',
        'duration_months',
        'is_active',
        'features',
        'sort_order',
    ];

    protected $appends = [
        'monthly_price',
        'savings_percentage',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'duration_months' => 'integer',
        'is_active' => 'boolean',
        'features' => 'array',
        'sort_order' => 'integer',
    ];

    public function getMonthlyPriceAttribute(): float
    {
        return (float) ($this->price / ($this->duration_months ?: 1));
    }

    public function getSavingsPercentageAttribute(): ?float
    {
        if ($this->duration_months <= 1) {
            return null;
        }

        $monthlyPlan = static::where('duration_months', 1)->active()->ordered()->first();
        if (! $monthlyPlan) {
            return null;
        }

        $monthlyBase = (float) $monthlyPlan->price;
        $thisMonthly = (float) ($this->price / $this->duration_months);

        $savings = (($monthlyBase - $thisMonthly) / $monthlyBase) * 100;

        return round($savings, 0);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    public static function getActivePlans()
    {
        return static::active()->ordered()->get();
    }
}
