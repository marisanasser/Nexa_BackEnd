<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * BrandBalance model for tracking brand platform balance.
 *
 * @property int                      $id
 * @property int                      $brand_id
 * @property float|string             $available_balance
 * @property float|string             $total_funded
 * @property float|string             $total_spent
 * @property null|Carbon              $created_at
 * @property null|Carbon              $updated_at
 * @property string                   $formatted_available_balance
 * @property string                   $formatted_total_funded
 * @property string                   $formatted_total_spent
 * @property float                    $funding_this_month
 * @property float                    $funding_this_year
 * @property string                   $formatted_funding_this_month
 * @property string                   $formatted_funding_this_year
 * @property null|User                $brand
 * @property Collection|Transaction[] $transactions
 */
class BrandBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand_id',
        'available_balance',
        'total_funded',
        'total_spent',
    ];

    protected $casts = [
        'available_balance' => 'decimal:2',
        'total_funded' => 'decimal:2',
        'total_spent' => 'decimal:2',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'user_id', 'brand_id')
            ->whereJsonContains('payment_data->type', 'platform_funding')
        ;
    }

    public function getFormattedAvailableBalanceAttribute(): string
    {
        return 'R$ '.number_format((float) ($this->available_balance ?? 0), 2, ',', '.');
    }

    public function getFormattedTotalFundedAttribute(): string
    {
        return 'R$ '.number_format((float) ($this->total_funded ?? 0), 2, ',', '.');
    }

    public function getFormattedTotalSpentAttribute(): string
    {
        return 'R$ '.number_format((float) ($this->total_spent ?? 0), 2, ',', '.');
    }

    public function canSpend(float $amount): bool
    {
        return $this->available_balance >= $amount && $amount > 0;
    }

    public function addFunding(float $amount): void
    {
        $this->increment('available_balance', $amount);
        $this->increment('total_funded', $amount);
    }

    public function spend(float $amount): bool
    {
        if (!$this->canSpend($amount)) {
            return false;
        }

        $this->decrement('available_balance', $amount);
        $this->increment('total_spent', $amount);

        return true;
    }

    public function getFundingThisMonth(): float
    {
        return $this->transactions()
            ->where('status', 'paid')
            ->whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year)
            ->sum('amount')
        ;
    }

    public function getFundingThisYear(): float
    {
        return $this->transactions()
            ->where('status', 'paid')
            ->whereYear('paid_at', now()->year)
            ->sum('amount')
        ;
    }

    public function getFormattedFundingThisMonthAttribute(): string
    {
        return 'R$ '.number_format($this->funding_this_month, 2, ',', '.');
    }

    public function getFormattedFundingThisYearAttribute(): string
    {
        return 'R$ '.number_format($this->funding_this_year, 2, ',', '.');
    }
}
