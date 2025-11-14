<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;

class Withdrawal extends Model
{
    use HasFactory;

    protected $fillable = [
        'creator_id',
        'amount',
        'platform_fee',
        'fixed_fee',
        'withdrawal_method',
        'withdrawal_details',
        'status',
        'transaction_id',
        'processed_at',
        'failure_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'platform_fee' => 'decimal:2',
        'fixed_fee' => 'decimal:2',
        'withdrawal_details' => 'array',
        'processed_at' => 'datetime',
    ];

    // Relationships
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', 'processing');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeRecent($query, $days = 30)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    // Methods
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function canBeProcessed(): bool
    {
        return $this->isPending();
    }

    public function canBeCancelled(): bool
    {
        return $this->isPending();
    }

    public function process(): bool
    {
        if (!$this->canBeProcessed()) {
            return false;
        }

        $this->update([
            'status' => 'processing',
        ]);

        try {
            // Process withdrawal through payment gateway
            $this->processWithdrawal();
            
            $this->update([
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            // Update creator balance
            $this->updateCreatorBalance();

            // Create transaction record for successful withdrawal
            $this->createTransactionRecord();

            // Notify creator about successful withdrawal
            self::createWithdrawalNotification('completed');

            return true;
        } catch (\Exception $e) {
            $this->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            // Refund the amount back to creator's available balance
            $this->refundToCreator();

            // Notify about withdrawal failure
            self::createWithdrawalNotification('failed', $e->getMessage());

            return false;
        }
    }

    private function createWithdrawalNotification(string $status, string $reason = null): void
    {
        try {
            if ($status === 'completed') {
                // Use the new static method for completed withdrawals with detailed information
                $withdrawalMethod = WithdrawalMethod::findByCode($this->withdrawal_method);
                $methodName = $withdrawalMethod ? $withdrawalMethod->name : $this->withdrawal_method_label;
                
                $withdrawalData = [
                    'withdrawal_id' => $this->id,
                    'method' => $this->withdrawal_method,
                    'method_name' => $methodName,
                    'transaction_id' => $this->transaction_id,
                    'processed_at' => $this->processed_at ? $this->processed_at->toDateTimeString() : null,
                ];
                
                // Add withdrawal details if available
                if ($this->withdrawal_details) {
                    $withdrawalData = array_merge($withdrawalData, $this->withdrawal_details);
                }
                
                $notification = Notification::createWithdrawalSuccess(
                    $this->creator_id,
                    $this->amount,
                    $this->net_amount,
                    $this->total_fees,
                    $withdrawalData
                );
            } else {
                // For failed/cancelled withdrawals, use the original format
                $notification = Notification::create([
                    'user_id' => $this->creator_id,
                    'type' => 'withdrawal_' . $status,
                    'title' => $status === 'failed' ? 'Falha no Saque' : 'Saque Cancelado',
                    'message' => $status === 'failed'
                        ? "Falha no processamento do saque de {$this->formatted_amount}. Motivo: {$reason}"
                        : "Seu saque de {$this->formatted_amount} foi cancelado." . ($reason ? " Motivo: {$reason}" : ''),
                    'data' => [
                        'withdrawal_id' => $this->id,
                        'amount' => $this->amount,
                        'method' => $this->withdrawal_method,
                        'status' => $status,
                        'reason' => $reason,
                    ],
                    'is_read' => false,
                ]);
            }

            // Send real-time notification
            NotificationService::sendSocketNotification($this->creator_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to create withdrawal notification', [
                'withdrawal_id' => $this->id,
                'status' => $status,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function processWithdrawal(): void
    {
        // Handle different withdrawal methods
        switch ($this->withdrawal_method) {
            case 'stripe_connect':
                $this->processStripeConnectWithdrawal();
                break;
            case 'pagarme_bank_transfer':
                $this->processPagarMeWithdrawal();
                break;
            case 'bank_transfer':
                $this->processBankTransfer();
                break;
            case 'pix':
                $this->processPixWithdrawal();
                break;
            default:
                throw new \Exception('Método de saque não suportado: ' . $this->withdrawal_method);
        }
    }

    private function processStripeConnectWithdrawal(): void
    {
        // Ensure creator has a connected account
        $creator = User::find($this->creator_id);
        if (!$creator || empty($creator->stripe_account_id)) {
            throw new \Exception('Conta Stripe Connect não configurada para este criador.');
        }

        // Ensure Stripe is configured
        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            throw new \Exception('Stripe não configurado.');
        }

        \Stripe\Stripe::setApiKey($stripeSecret);

        // Compute net amount = requested amount - fixed fee (R$5)
        $netAmount = max(0, ($this->amount - 5.00));
        $amountInCents = (int) round($netAmount * 100);
        if ($amountInCents <= 0) {
            throw new \Exception('Valor líquido inválido para transferência.');
        }

        // Create transfer to connected account
        $transfer = \Stripe\Transfer::create([
            'amount' => $amountInCents,
            'currency' => 'brl',
            'destination' => $creator->stripe_account_id,
            'metadata' => [
                'withdrawal_id' => (string) $this->id,
                'creator_id' => (string) $this->creator_id,
                'gross_amount' => (string) $this->amount,
                'fixed_fee' => '5.00',
            ],
        ]);

        // Store transfer id in transaction_id (generic field)
        $this->update([
            'transaction_id' => $transfer->id,
        ]);
    }

    private function processPagarMeWithdrawal(): void
    {
        // Process Pagar.me withdrawal
        sleep(2); // Simulate processing time
        
        $this->update([
            'transaction_id' => 'PAGARME_' . time() . '_' . $this->id,
        ]);
    }

    private function processBankTransfer(): void
    {
        // Process traditional bank transfer
        sleep(3); // Simulate longer processing time
        
        $this->update([
            'transaction_id' => 'BANK_' . time() . '_' . $this->id,
        ]);
    }

    private function processPixWithdrawal(): void
    {
        // Process PIX withdrawal
        sleep(1); // Simulate fast PIX processing
        
        $this->update([
            'transaction_id' => 'PIX_' . time() . '_' . $this->id,
        ]);
    }

    private function updateCreatorBalance(): void
    {
        $balance = CreatorBalance::where('creator_id', $this->creator_id)->first();
        if ($balance) {
            $balance->withdraw($this->amount);
        }
    }

    private function refundToCreator(): void
    {
        $balance = CreatorBalance::where('creator_id', $this->creator_id)->first();
        if ($balance) {
            $balance->increment('available_balance', $this->amount);
        }
    }

    /**
     * Create transaction record for completed withdrawal
     */
    private function createTransactionRecord(): void
    {
        try {
            // Check if transaction already exists for this withdrawal
            // Check both payment_data and metadata for withdrawal_id
            $existingTransaction = Transaction::where('user_id', $this->creator_id)
                ->where(function($query) {
                    $query->whereJsonContains('payment_data->withdrawal_id', (string)$this->id)
                          ->orWhereJsonContains('payment_data->withdrawal_id', $this->id)
                          ->orWhereJsonContains('metadata->withdrawal_id', (string)$this->id)
                          ->orWhereJsonContains('metadata->withdrawal_id', $this->id);
                })
                ->first();

            if ($existingTransaction) {
                Log::info('Transaction record already exists for withdrawal', [
                    'withdrawal_id' => $this->id,
                    'transaction_id' => $existingTransaction->id,
                ]);
                return;
            }

            // Get withdrawal method details
            $withdrawalMethod = WithdrawalMethod::findByCode($this->withdrawal_method);
            $methodName = $withdrawalMethod ? $withdrawalMethod->name : $this->withdrawal_method_label;

            // Prepare payment data
            $paymentData = [
                'withdrawal_id' => $this->id,
                'withdrawal_method' => $this->withdrawal_method,
                'method_name' => $methodName,
                'gross_amount' => $this->amount,
                'net_amount' => $this->net_amount,
                'total_fees' => $this->total_fees,
                'platform_fee' => $this->platform_fee,
                'fixed_fee' => $this->fixed_fee,
                'transaction_id' => $this->transaction_id,
                'processed_at' => $this->processed_at ? $this->processed_at->toDateTimeString() : null,
            ];

            // Add withdrawal details if available
            if ($this->withdrawal_details) {
                $paymentData = array_merge($paymentData, $this->withdrawal_details);
            }

            // Determine payment method for transaction record
            $paymentMethod = 'withdrawal';
            $stripePaymentIntentId = null;
            $stripeChargeId = null;

            if ($this->withdrawal_method === 'stripe_connect' || $this->withdrawal_method === 'stripe_card') {
                $paymentMethod = 'stripe_withdrawal';
                // For Stripe withdrawals, transaction_id might be a transfer ID
                if ($this->transaction_id && strpos($this->transaction_id, 'tr_') === 0) {
                    // This is a Stripe transfer ID
                    $stripePaymentIntentId = $this->transaction_id;
                }
            } elseif ($this->withdrawal_method === 'pagarme_bank_transfer') {
                $paymentMethod = 'pagarme_withdrawal';
            } elseif ($this->withdrawal_method === 'pix') {
                $paymentMethod = 'pix_withdrawal';
            } elseif ($this->withdrawal_method === 'bank_transfer') {
                $paymentMethod = 'bank_transfer_withdrawal';
            }

            // Get card details from withdrawal_details if available (for Stripe card withdrawals)
            $cardBrand = null;
            $cardLast4 = null;
            $cardHolderName = null;

            if ($this->withdrawal_details) {
                $cardBrand = $this->withdrawal_details['card_brand'] ?? null;
                $cardLast4 = $this->withdrawal_details['card_last4'] ?? null;
                $cardHolderName = $this->withdrawal_details['card_holder_name'] ?? null;
            }

            // Create transaction record
            $transaction = Transaction::create([
                'user_id' => $this->creator_id,
                'stripe_payment_intent_id' => $stripePaymentIntentId,
                'stripe_charge_id' => $stripeChargeId,
                'status' => 'paid',
                'amount' => $this->amount,
                'payment_method' => $paymentMethod,
                'card_brand' => $cardBrand,
                'card_last4' => $cardLast4,
                'card_holder_name' => $cardHolderName,
                'payment_data' => $paymentData,
                'paid_at' => $this->processed_at ?? now(),
                'metadata' => [
                    'withdrawal_id' => $this->id,
                    'withdrawal_method' => $this->withdrawal_method,
                    'method_name' => $methodName,
                    'net_amount' => $this->net_amount,
                    'total_fees' => $this->total_fees,
                ],
            ]);

            Log::info('Transaction record created for withdrawal', [
                'withdrawal_id' => $this->id,
                'transaction_id' => $transaction->id,
                'creator_id' => $this->creator_id,
                'amount' => $this->amount,
                'payment_method' => $paymentMethod,
            ]);

        } catch (\Exception $e) {
            // Log error but don't fail the withdrawal
            Log::error('Failed to create transaction record for withdrawal', [
                'withdrawal_id' => $this->id,
                'creator_id' => $this->creator_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function cancel(string $reason = null): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->update([
            'status' => 'cancelled',
            'failure_reason' => $reason,
        ]);

        // Refund the amount back to creator's available balance
        $this->refundToCreator();

        // Notify creator about cancellation
        self::createWithdrawalNotification('cancelled', $reason);

        return true;
    }

    public function getFormattedAmountAttribute(): string
    {
        return 'R$ ' . number_format($this->amount, 2, ',', '.');
    }

    /**
     * Calculate the percentage fee based on withdrawal method
     */
    public function getPercentageFeeAttribute(): float
    {
        $withdrawalMethod = WithdrawalMethod::findByCode($this->withdrawal_method);
        if (!$withdrawalMethod) {
            return 0;
        }
        return (float) $withdrawalMethod->fee;
    }

    /**
     * Calculate the percentage fee amount
     */
    public function getPercentageFeeAmountAttribute(): float
    {
        return ($this->amount * $this->percentage_fee) / 100;
    }

    /**
     * Calculate the platform fee amount (5% of withdrawal amount)
     */
    public function getPlatformFeeAmountAttribute(): float
    {
        return ($this->amount * $this->platform_fee) / 100;
    }

    /**
     * Calculate the total fees (percentage + platform fee + fixed fee)
     */
    public function getTotalFeesAttribute(): float
    {
        return $this->percentage_fee_amount + $this->platform_fee_amount + $this->fixed_fee;
    }

    /**
     * Calculate the net amount after all fees
     */
    public function getNetAmountAttribute(): float
    {
        return $this->amount - $this->total_fees;
    }

    /**
     * Get formatted platform fee percentage
     */
    public function getFormattedPlatformFeeAttribute(): string
    {
        return number_format($this->platform_fee, 2) . '%';
    }

    /**
     * Get formatted platform fee amount
     */
    public function getFormattedPlatformFeeAmountAttribute(): string
    {
        return 'R$ ' . number_format($this->platform_fee_amount, 2, ',', '.');
    }

    /**
     * Get formatted fixed fee amount
     */
    public function getFormattedFixedFeeAttribute(): string
    {
        return 'R$ ' . number_format($this->fixed_fee, 2, ',', '.');
    }

    /**
     * Get formatted percentage fee amount
     */
    public function getFormattedPercentageFeeAmountAttribute(): string
    {
        return 'R$ ' . number_format($this->percentage_fee_amount, 2, ',', '.');
    }

    /**
     * Get formatted total fees
     */
    public function getFormattedTotalFeesAttribute(): string
    {
        return 'R$ ' . number_format($this->total_fees, 2, ',', '.');
    }

    /**
     * Get formatted net amount
     */
    public function getFormattedNetAmountAttribute(): string
    {
        return 'R$ ' . number_format($this->net_amount, 2, ',', '.');
    }

    public function getStatusColorAttribute(): string
    {
        switch ($this->status) {
            case 'completed':
                return 'text-green-600';
            case 'processing':
                return 'text-blue-600';
            case 'pending':
                return 'text-yellow-600';
            case 'failed':
                return 'text-red-600';
            case 'cancelled':
                return 'text-gray-600';
            default:
                return 'text-gray-600';
        }
    }

    public function getStatusBadgeColorAttribute(): string
    {
        switch ($this->status) {
            case 'completed':
                return 'bg-green-100 text-green-800';
            case 'processing':
                return 'bg-blue-100 text-blue-800';
            case 'pending':
                return 'bg-yellow-100 text-yellow-800';
            case 'failed':
                return 'bg-red-100 text-red-800';
            case 'cancelled':
                return 'bg-gray-100 text-gray-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    }

    public function getWithdrawalMethodLabelAttribute(): string
    {
        switch ($this->withdrawal_method) {
            case 'bank_transfer':
                return 'Transferência Bancária';
            case 'pagarme_bank_transfer':
                return 'Transferência Bancária via Pagar.me';
            case 'pagarme_account':
                return 'Conta Pagar.me';
            case 'pix':
                return 'PIX';
            default:
                return ucfirst(str_replace('_', ' ', $this->withdrawal_method));
        }
    }

    public function getBankAccountInfoAttribute(): ?array
    {
        if ($this->withdrawal_method === 'bank_transfer' && $this->withdrawal_details) {
            return [
                'bank' => $this->withdrawal_details['bank'] ?? '',
                'agency' => $this->withdrawal_details['agency'] ?? '',
                'account' => $this->withdrawal_details['account'] ?? '',
                'account_type' => $this->withdrawal_details['account_type'] ?? '',
                'holder_name' => $this->withdrawal_details['holder_name'] ?? '',
            ];
        }
        
        return null;
    }

    public function getPixInfoAttribute(): ?array
    {
        if ($this->withdrawal_method === 'pix' && $this->withdrawal_details) {
            return [
                'pix_key' => $this->withdrawal_details['pix_key'] ?? '',
                'pix_key_type' => $this->withdrawal_details['pix_key_type'] ?? '',
                'holder_name' => $this->withdrawal_details['holder_name'] ?? '',
            ];
        }
        
        return null;
    }

    public function getDaysSinceCreatedAttribute(): int
    {
        return $this->created_at->diffInDays(now());
    }

    public function getIsRecentAttribute(): bool
    {
        return $this->days_since_created <= 7;
    }
} 