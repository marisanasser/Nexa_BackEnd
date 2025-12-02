<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'sort_order'
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'duration_months' => 'integer',
        'is_active' => 'boolean',
        'features' => 'array',
        'sort_order' => 'integer'
    ];

    /**
     * Get the monthly price
     * Note: The price in the database is already the monthly price for all plans
     */
    public function getMonthlyPriceAttribute(): float
    {
        // The price is already monthly, so return it directly
        return (float) $this->price;
    }

    /**
     * Get the savings percentage compared to monthly plan
     */
    public function getSavingsPercentageAttribute(): ?float
    {
        if ($this->duration_months <= 1) {
            return null;
        }
        
        $monthlyPlan = static::where('duration_months', 1)->first();
        if (!$monthlyPlan) {
            return null;
        }
        
        // Both prices are already monthly, so compare directly
        $savings = (($monthlyPlan->price - $this->price) / $monthlyPlan->price) * 100;
        
        return round($savings, 0);
    }

    /**
     * Scope to get only active plans
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to order by sort order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }

    /**
     * Get all active plans ordered by sort order
     */
    public static function getActivePlans()
    {
        return static::active()->ordered()->get();
    }
} 