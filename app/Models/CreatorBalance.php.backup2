<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreatorBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'available_balance',
        'pending_balance',
        'total_earned',
        'total_withdrawn',
    ];

    protected $casts = [
        'available_balance' => 'decimal:2',
        'pending_balance' => 'decimal:2',
        'total_earned' => 'decimal:2',
        'total_withdrawn' => 'decimal:2',
    ];

    // Relationships
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class, 'creator_id', 'creator_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(JobPayment::class, 'creator_id', 'creator_id');
    }

    // Methods
    public function getTotalBalanceAttribute(): float
    {
        return $this->available_balance + $this->pending_balance;
    }

    public function getFormattedAvailableBalanceAttribute(): string
    {
        return 'R$ ' . number_format($this->available_balance, 2, ',', '.');
    }

    public function getFormattedPendingBalanceAttribute(): string
    {
        return 'R$ ' . number_format($this->pending_balance, 2, ',', '.');
    }

    public function getFormattedTotalEarnedAttribute(): string
    {
        return 'R$ ' . number_format($this->total_earned, 2, ',', '.');
    }

    public function getFormattedTotalWithdrawnAttribute(): string
    {
        return 'R$ ' . number_format($this->total_withdrawn, 2, ',', '.');
    }

    public function getFormattedTotalBalanceAttribute(): string
    {
        return 'R$ ' . number_format($this->total_balance, 2, ',', '.');
    }

    public function canWithdraw(float $amount): bool
    {
        return $this->available_balance >= $amount && $amount > 0;
    }

    public function getMaxWithdrawalAmount(): float
    {
        return $this->available_balance;
    }

    public function addEarning(float $amount): void
    {
        $this->increment('total_earned', $amount);
    }

    public function addPendingAmount(float $amount): void
    {
        $this->increment('pending_balance', $amount);
    }

    public function movePendingToAvailable(float $amount): bool
    {
        // Refresh the model to get the latest balance from database
        $this->refresh();
        
        if ($this->pending_balance >= $amount) {
            $this->decrement('pending_balance', $amount);
            $this->increment('available_balance', $amount);
            
            // Refresh again to verify the update
            $this->refresh();
            
            \Illuminate\Support\Facades\Log::info('Moved pending to available balance', [
                'creator_id' => $this->creator_id,
                'amount' => $amount,
                'pending_balance_after' => $this->pending_balance,
                'available_balance_after' => $this->available_balance,
            ]);
            
            return true;
        } else {
            \Illuminate\Support\Facades\Log::warning('Failed to move pending to available balance - insufficient pending balance', [
                'creator_id' => $this->creator_id,
                'amount_requested' => $amount,
                'pending_balance' => $this->pending_balance,
                'available_balance' => $this->available_balance,
            ]);
            
            return false;
        }
    }

    public function withdraw(float $amount): bool
    {
        if (!$this->canWithdraw($amount)) {
            return false;
        }

        $this->decrement('available_balance', $amount);
        $this->increment('total_withdrawn', $amount);

        return true;
    }

    public function getEarningsThisMonth(): float
    {
        return $this->payments()
            ->where('status', 'completed')
            ->whereMonth('processed_at', now()->month)
            ->whereYear('processed_at', now()->year)
            ->sum('creator_amount');
    }

    public function getEarningsThisYear(): float
    {
        return $this->payments()
            ->where('status', 'completed')
            ->whereYear('processed_at', now()->year)
            ->sum('creator_amount');
    }

    public function getFormattedEarningsThisMonthAttribute(): string
    {
        return 'R$ ' . number_format($this->earnings_this_month, 2, ',', '.');
    }

    public function getFormattedEarningsThisYearAttribute(): string
    {
        return 'R$ ' . number_format($this->earnings_this_year, 2, ',', '.');
    }

    public function getPendingWithdrawalsCount(): int
    {
        return $this->withdrawals()
            ->whereIn('status', ['pending', 'processing'])
            ->count();
    }

    public function getPendingWithdrawalsAmount(): float
    {
        return $this->withdrawals()
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount');
    }

    public function getFormattedPendingWithdrawalsAmountAttribute(): string
    {
        return 'R$ ' . number_format($this->pending_withdrawals_amount, 2, ',', '.');
    }

    public function getRecentTransactions(int $limit = 10)
    {
        return $this->payments()
            ->with('contract')
            ->orderBy('processed_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Recalculate balance from job payments and withdrawals
     * This is useful when balance might be out of sync
     */
    public function recalculateFromPayments(): void
    {
        // Get all job payments with contract relationship
        $allPayments = $this->payments()
            ->with('contract')
            ->get();

        // Separate payments by status
        $completedPayments = $allPayments->where('status', 'completed');
        $pendingPayments = $allPayments->where('status', 'pending');

        // Calculate total earned from completed payments
        $totalEarned = $completedPayments->sum('creator_amount');

        // Get all withdrawals
        $withdrawals = $this->withdrawals()
            ->whereIn('status', ['completed', 'processing'])
            ->get();

        $totalWithdrawn = $withdrawals->sum('amount');

        // Calculate pending balance from pending payments
        $pendingBalance = $pendingPayments->sum('creator_amount');

        // Calculate available balance
        // Available = completed payments where contract workflow_status = 'payment_available' minus withdrawn
        $availableFromCompleted = $completedPayments
            ->filter(function ($payment) {
                return $payment->contract && 
                       $payment->contract->workflow_status === 'payment_available';
            })
            ->sum('creator_amount');

        // Also check contracts directly - if a contract has workflow_status = 'payment_available'
        // but no payment record, we should still count it
        $contractsWithAvailablePayment = \App\Models\Contract::where('creator_id', $this->creator_id)
            ->where('workflow_status', 'payment_available')
            ->where('status', 'completed')
            ->get();

        // Add creator_amount from contracts that have payment_available but might not have payment records
        foreach ($contractsWithAvailablePayment as $contract) {
            $hasPaymentRecord = $allPayments->contains(function ($payment) use ($contract) {
                return $payment->contract_id === $contract->id;
            });
            
            // If contract has payment_available status but no payment record, use contract's creator_amount
            if (!$hasPaymentRecord && $contract->creator_amount > 0) {
                $availableFromCompleted += $contract->creator_amount;
                $totalEarned += $contract->creator_amount;
                
                \Illuminate\Support\Facades\Log::info('Found contract with payment_available but no payment record', [
                    'contract_id' => $contract->id,
                    'creator_id' => $this->creator_id,
                    'creator_amount' => $contract->creator_amount,
                ]);
            }
        }

        // Calculate available balance: payments available minus what's been withdrawn
        $availableBalance = max(0, $availableFromCompleted - $totalWithdrawn);

        // Update the balance
        $this->update([
            'total_earned' => $totalEarned,
            'total_withdrawn' => $totalWithdrawn,
            'pending_balance' => $pendingBalance,
            'available_balance' => $availableBalance,
        ]);

        // Refresh to get updated values
        $this->refresh();

        \Illuminate\Support\Facades\Log::info('Recalculated creator balance from payments', [
            'creator_id' => $this->creator_id,
            'total_earned' => $this->total_earned,
            'total_withdrawn' => $this->total_withdrawn,
            'pending_balance' => $this->pending_balance,
            'available_balance' => $this->available_balance,
            'available_from_completed' => $availableFromCompleted,
            'completed_payments_count' => $completedPayments->count(),
            'pending_payments_count' => $pendingPayments->count(),
        ]);
    }

    public function getWithdrawalHistory(int $limit = 10)
    {
        return $this->withdrawals()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    public function getBalanceHistory(int $days = 30)
    {
        // This would typically come from a balance history table
        // For now, we'll return basic stats
        return [
            'current_balance' => $this->total_balance,
            'available_balance' => $this->available_balance,
            'pending_balance' => $this->pending_balance,
            'total_earned' => $this->total_earned,
            'total_withdrawn' => $this->total_withdrawn,
            'earnings_this_month' => $this->earnings_this_month,
            'earnings_this_year' => $this->earnings_this_year,
        ];
    }
} 