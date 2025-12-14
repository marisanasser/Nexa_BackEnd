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

    protected $casts = [
        'price' => 'decimal:2',
        'duration_months' => 'integer',
        'is_active' => 'boolean',
        'features' => 'array',
        'sort_order' => 'integer',
    ];

    public function getMonthlyPriceAttribute(): float
    {

        return (float) $this->price;
    }

    public function getSavingsPercentageAttribute(): ?float
    {
        if ($this->duration_months <= 1) {
            return null;
        }

        $monthlyPlan = static::where('duration_months', 1)->first();
        if (! $monthlyPlan) {
            return null;
        }

        $savings = (($monthlyPlan->price - $this->price) / $monthlyPlan->price) * 100;

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
