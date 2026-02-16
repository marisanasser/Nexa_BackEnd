<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Models\Contract\Contract;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * CreatorBalance model for tracking creator earnings and withdrawals.
 *
 * @property int                     $id
 * @property int                     $creator_id
 * @property float|string            $available_balance
 * @property float|string            $pending_balance
 * @property float|string            $total_earned
 * @property float|string            $total_withdrawn
 * @property null|Carbon             $created_at
 * @property null|Carbon             $updated_at
 * @property float                   $total_balance
 * @property string                  $formatted_available_balance
 * @property string                  $formatted_pending_balance
 * @property string                  $formatted_total_earned
 * @property string                  $formatted_total_withdrawn
 * @property string                  $formatted_total_balance
 * @property float                   $earnings_this_month
 * @property float                   $earnings_this_year
 * @property string                  $formatted_earnings_this_month
 * @property string                  $formatted_earnings_this_year
 * @property int                     $pending_withdrawals_count
 * @property float                   $pending_withdrawals_amount
 * @property string                  $formatted_pending_withdrawals_amount
 * @property null|User               $creator
 * @property Collection|Withdrawal[] $withdrawals
 * @property Collection|JobPayment[] $payments
 */
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

    public function formattedTotalBalance(): string
    {
        return 'R$ ' . number_format($this->total_balance, 2, ',', '.');
    }

    public function getTotalBalanceAttribute(): float
    {
        return (float) ($this->available_balance ?? 0) + (float) ($this->pending_balance ?? 0);
    }

    public function formattedAvailableBalance(): string
    {
        return 'R$ ' . number_format((float) ($this->available_balance ?? 0), 2, ',', '.');
    }

    public function getFormattedAvailableBalanceAttribute(): string
    {
        return $this->formattedAvailableBalance();
    }

    public function formattedPendingBalance(): string
    {
        return 'R$ ' . number_format((float) ($this->pending_balance ?? 0), 2, ',', '.');
    }

    public function getFormattedPendingBalanceAttribute(): string
    {
        return $this->formattedPendingBalance();
    }

    public function formattedTotalEarned(): string
    {
        return 'R$ ' . number_format((float) ($this->total_earned ?? 0), 2, ',', '.');
    }

    public function getFormattedTotalEarnedAttribute(): string
    {
        return $this->formattedTotalEarned();
    }

    public function formattedTotalWithdrawn(): string
    {
        return 'R$ ' . number_format((float) ($this->total_withdrawn ?? 0), 2, ',', '.');
    }

    public function getFormattedTotalWithdrawnAttribute(): string
    {
        return $this->formattedTotalWithdrawn();
    }

    public function getFormattedTotalBalanceAttribute(): string
    {
        return $this->formattedTotalBalance();
    }

    public function canWithdraw(float $amount): bool
    {
        return $this->available_balance >= $amount && $amount > 0;
    }

    public function getMaxWithdrawalAmount(): float
    {
        return (float) $this->available_balance;
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
        $this->refresh();

        if ($this->pending_balance >= $amount) {
            $this->decrement('pending_balance', $amount);
            $this->increment('available_balance', $amount);

            $this->refresh();

            Log::info('Moved pending to available balance', [
                'creator_id' => $this->creator_id,
                'amount' => $amount,
                'pending_balance_after' => $this->pending_balance,
                'available_balance_after' => $this->available_balance,
            ]);

            return true;
        }
        Log::warning('Failed to move pending to available balance - insufficient pending balance', [
            'creator_id' => $this->creator_id,
            'amount_requested' => $amount,
            'pending_balance' => $this->pending_balance,
            'available_balance' => $this->available_balance,
        ]);

        return false;
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

    public function getEarningsThisMonthAttribute(): float
    {
        return $this->getEarningsThisMonth();
    }

    public function getEarningsThisYearAttribute(): float
    {
        return $this->getEarningsThisYear();
    }

    public function getEarningsThisMonth(): float
    {
        return $this->payments()
            ->where('status', 'completed')
            ->whereMonth('processed_at', now()->month)
            ->whereYear('processed_at', now()->year)
            ->sum('creator_amount')
        ;
    }

    public function getEarningsThisYear(): float
    {
        return $this->payments()
            ->where('status', 'completed')
            ->whereYear('processed_at', now()->year)
            ->sum('creator_amount')
        ;
    }

    public function getFormattedEarningsThisMonthAttribute(): string
    {
        return 'R$ ' . number_format($this->earnings_this_month, 2, ',', '.');
    }

    public function getFormattedEarningsThisYearAttribute(): string
    {
        return 'R$ ' . number_format($this->earnings_this_year, 2, ',', '.');
    }

    public function getPendingWithdrawalsCountAttribute(): int
    {
        return $this->withdrawals()
            ->whereIn('status', ['pending', 'processing'])
            ->count()
        ;
    }

    public function getPendingWithdrawalsAmountAttribute(): float
    {
        return $this->withdrawals()
            ->whereIn('status', ['pending', 'processing'])
            ->sum('amount')
        ;
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
            ->get()
        ;
    }

    public function recalculateFromPayments(): void
    {
        $allPayments = $this->payments()
            ->with('contract')
            ->get();

        $completedPayments = $allPayments->where('status', 'completed');
        $pendingPayments = $allPayments->where('status', 'pending');

        $totalEarned = $completedPayments->sum('creator_amount');

        $withdrawals = $this->withdrawals()
            ->whereIn('status', ['completed', 'processing'])
            ->get();

        $totalWithdrawn = $withdrawals->sum('amount');

        $pendingBalance = $pendingPayments->sum('creator_amount');

        $availableFromCompleted = $completedPayments
            ->filter(fn($payment) => $payment->contract
                && 'payment_available' === $payment->contract->workflow_status)
            ->sum('creator_amount');

        $contractsWithAvailablePayment = Contract::where('creator_id', $this->creator_id)
            ->where('workflow_status', 'payment_available')
            ->where('status', 'completed')
            ->get();

        foreach ($contractsWithAvailablePayment as $contract) {
            $hasPaymentRecord = $allPayments->contains(fn($payment) => $payment->contract_id === $contract->id);

            if (!$hasPaymentRecord && $contract->creator_amount > 0) {
                $creatorAmount = (float) $contract->creator_amount;
                $availableFromCompleted += $creatorAmount;
                // Only modify totalEarned if it's not already counted via payments?
                // The original logic just added it. Assuming integrity check logic.
                $totalEarned += $creatorAmount;

                Log::info('Found contract with payment_available but no payment record', [
                    'contract_id' => $contract->id,
                    'creator_id' => $this->creator_id,
                    'creator_amount' => $creatorAmount,
                ]);
            }
        }

        $availableBalance = max(0, $availableFromCompleted - $totalWithdrawn);

        $this->update([
            'total_earned' => $totalEarned,
            'total_withdrawn' => $totalWithdrawn,
            'pending_balance' => $pendingBalance,
            'available_balance' => $availableBalance,
        ]);

        $this->refresh();

        Log::info('Recalculated creator balance from payments', [
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
            ->get()
        ;
    }

    public function getBalanceHistory(int $days = 30): array
    {
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
