<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            ->whereJsonContains('payment_data->type', 'platform_funding');
    }

    
    public function getFormattedAvailableBalanceAttribute(): string
    {
        return 'R$ ' . number_format($this->available_balance, 2, ',', '.');
    }

    public function getFormattedTotalFundedAttribute(): string
    {
        return 'R$ ' . number_format($this->total_funded, 2, ',', '.');
    }

    public function getFormattedTotalSpentAttribute(): string
    {
        return 'R$ ' . number_format($this->total_spent, 2, ',', '.');
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
            ->sum('amount');
    }

    public function getFundingThisYear(): float
    {
        return $this->transactions()
            ->where('status', 'paid')
            ->whereYear('paid_at', now()->year)
            ->sum('amount');
    }

    public function getFormattedFundingThisMonthAttribute(): string
    {
        return 'R$ ' . number_format($this->funding_this_month, 2, ',', '.');
    }

    public function getFormattedFundingThisYearAttribute(): string
    {
        return 'R$ ' . number_format($this->funding_this_year, 2, ',', '.');
    }
}
