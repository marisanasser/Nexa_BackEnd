<?php

declare(strict_types=1);

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int         $id
 * @property string      $name
 * @property null|string $description
 * @property float       $price
 * @property null|string $stripe_price_id
 * @property null|string $stripe_product_id
 * @property int         $duration_months
 * @property bool        $is_active
 * @property null|array  $features
 * @property int         $sort_order
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property float       $monthly_price
 * @property null|float  $savings_percentage
 *
 * @method static \Illuminate\Database\Eloquent\Builder|SubscriptionPlan where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder|SubscriptionPlan active()
 * @method static \Illuminate\Database\Eloquent\Builder|SubscriptionPlan ordered()
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 */
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
        if (!$monthlyPlan) {
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

    public static function findByStripePriceId(string $priceId): ?self
    {
        return static::where('stripe_price_id', $priceId)->first();
    }
}
