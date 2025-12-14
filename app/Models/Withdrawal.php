<?php

namespace App\Models;

use App\Services\NotificationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

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
        if (! $this->canBeProcessed()) {
            return false;
        }

        $this->update([
            'status' => 'processing',
        ]);

        try {

            $this->processWithdrawal();

            $this->update([
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            $this->updateCreatorBalance();

            $this->createTransactionRecord();

            self::createWithdrawalNotification('completed');

            return true;
        } catch (\Exception $e) {
            $this->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            $this->refundToCreator();

            self::createWithdrawalNotification('failed', $e->getMessage());

            return false;
        }
    }

    private function createWithdrawalNotification(string $status, ?string $reason = null): void
    {
        try {
            if ($status === 'completed') {

                $withdrawalMethod = WithdrawalMethod::findByCode($this->withdrawal_method);
                $methodName = $withdrawalMethod ? $withdrawalMethod->name : $this->withdrawal_method_label;

                $withdrawalData = [
                    'withdrawal_id' => $this->id,
                    'method' => $this->withdrawal_method,
                    'method_name' => $methodName,
                    'transaction_id' => $this->transaction_id,
                    'processed_at' => $this->processed_at ? $this->processed_at->toDateTimeString() : null,
                ];

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

                $notification = Notification::create([
                    'user_id' => $this->creator_id,
                    'type' => 'withdrawal_'.$status,
                    'title' => $status === 'failed' ? 'Falha no Saque' : 'Saque Cancelado',
                    'message' => $status === 'failed'
                        ? "Falha no processamento do saque de {$this->formatted_amount}. Motivo: {$reason}"
                        : "Seu saque de {$this->formatted_amount} foi cancelado.".($reason ? " Motivo: {$reason}" : ''),
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

            NotificationService::sendSocketNotification($this->creator_id, $notification);
        } catch (\Exception $e) {
            Log::error('Failed to create withdrawal notification', [
                'withdrawal_id' => $this->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function processWithdrawal(): void
    {

        switch ($this->withdrawal_method) {
            case 'stripe_connect':
            case 'stripe_connect_bank_account':
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
                throw new \Exception('Método de saque não suportado: '.$this->withdrawal_method);
        }
    }

    private function findSourceChargeForCreator(int $creatorId): ?string
    {

        $jobPayment = \App\Models\JobPayment::where('creator_id', $creatorId)
            ->whereNotNull('transaction_id')
            ->orderBy('created_at', 'desc')
            ->get()
            ->first(function ($jobPayment) {

                return is_numeric($jobPayment->transaction_id);
            });

        if ($jobPayment && is_numeric($jobPayment->transaction_id)) {

            $jobPayment->load('transaction');

            if ($jobPayment->transaction) {
                $transaction = $jobPayment->transaction;

                if ($transaction->stripe_charge_id) {
                    Log::info('Found source charge from JobPayment transaction', [
                        'withdrawal_id' => $this->id,
                        'creator_id' => $creatorId,
                        'job_payment_id' => $jobPayment->id,
                        'transaction_id' => $transaction->id,
                        'stripe_charge_id' => $transaction->stripe_charge_id,
                    ]);

                    return $transaction->stripe_charge_id;
                }
            }
        }

        if (Schema::hasColumn('transactions', 'contract_id')) {
            $contractTransaction = \App\Models\Transaction::whereHas('contract', function ($query) use ($creatorId) {
                $query->where('creator_id', $creatorId);
            })
                ->whereNotNull('stripe_charge_id')
                ->orderBy('created_at', 'desc')
                ->first();

            if ($contractTransaction && $contractTransaction->stripe_charge_id) {
                Log::info('Found source charge from contract transaction', [
                    'withdrawal_id' => $this->id,
                    'creator_id' => $creatorId,
                    'transaction_id' => $contractTransaction->id,
                    'stripe_charge_id' => $contractTransaction->stripe_charge_id,
                ]);

                return $contractTransaction->stripe_charge_id;
            }
        }

        $anyTransaction = \App\Models\Transaction::where('user_id', $creatorId)
            ->whereNotNull('stripe_charge_id')
            ->orderBy('created_at', 'desc')
            ->first();

        if ($anyTransaction && $anyTransaction->stripe_charge_id) {
            Log::info('Found source charge from any creator transaction', [
                'withdrawal_id' => $this->id,
                'creator_id' => $creatorId,
                'transaction_id' => $anyTransaction->id,
                'stripe_charge_id' => $anyTransaction->stripe_charge_id,
            ]);

            return $anyTransaction->stripe_charge_id;
        }

        if (app()->environment(['local', 'testing'])) {
            try {
                $stripeSecret = config('services.stripe.secret');
                if ($stripeSecret) {
                    \Stripe\Stripe::setApiKey($stripeSecret);

                    $requiredAmount = (int) round(($this->amount - 5.00) * 100);

                    $charges = \Stripe\Charge::all([
                        'limit' => 10,
                        'expand' => ['data.balance_transaction'],
                    ]);

                    foreach ($charges->data as $charge) {
                        if ($charge->amount < $requiredAmount) {
                            continue;
                        }

                        $transfers = \Stripe\Transfer::all([
                            'limit' => 100,
                        ]);

                        $usedAmount = 0;
                        foreach ($transfers->data as $transfer) {
                            if ($transfer->source_transaction === $charge->id) {
                                $usedAmount += $transfer->amount;
                            }
                        }

                        $availableAmount = $charge->amount - $usedAmount;

                        if ($availableAmount >= $requiredAmount) {
                            Log::info('Found source charge from Stripe API with available balance', [
                                'withdrawal_id' => $this->id,
                                'creator_id' => $creatorId,
                                'stripe_charge_id' => $charge->id,
                                'charge_amount' => $charge->amount / 100,
                                'used_amount' => $usedAmount / 100,
                                'available_amount' => $availableAmount / 100,
                                'required_amount' => $requiredAmount / 100,
                            ]);

                            return $charge->id;
                        }
                    }

                    Log::warning('No charge with sufficient available balance found', [
                        'withdrawal_id' => $this->id,
                        'creator_id' => $creatorId,
                        'required_amount' => $requiredAmount / 100,
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to get charge from Stripe API (development fallback)', [
                    'withdrawal_id' => $this->id,
                    'creator_id' => $creatorId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::warning('No source charge found for creator', [
            'withdrawal_id' => $this->id,
            'creator_id' => $creatorId,
        ]);

        return null;
    }

    private function processStripeConnectWithdrawal(): void
    {

        $creator = User::find($this->creator_id);
        if (! $creator || empty($creator->stripe_account_id)) {
            throw new \Exception('Conta Stripe Connect não configurada para este criador.');
        }

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            throw new \Exception('Stripe não configurado.');
        }

        \Stripe\Stripe::setApiKey($stripeSecret);

        $netAmount = max(0, ($this->amount - 5.00));
        $amountInCents = (int) round($netAmount * 100);
        if ($amountInCents <= 0) {
            throw new \Exception('Valor líquido inválido para transferência.');
        }

        $sourceChargeId = $this->findSourceChargeForCreator($this->creator_id);

        if (! $sourceChargeId) {
            Log::warning('No source charge found for withdrawal', [
                'withdrawal_id' => $this->id,
                'creator_id' => $this->creator_id,
            ]);

            throw new \Exception('Não foi possível encontrar uma transação de origem válida para o saque. Entre em contato com o suporte.');
        }

        $transferParams = [
            'amount' => $amountInCents,
            'currency' => 'brl',
            'destination' => $creator->stripe_account_id,
            'source_transaction' => $sourceChargeId,
            'metadata' => [
                'withdrawal_id' => (string) $this->id,
                'creator_id' => (string) $this->creator_id,
                'gross_amount' => (string) $this->amount,
                'fixed_fee' => '5.00',
            ],
        ];

        $transfer = \Stripe\Transfer::create($transferParams);

        $this->update([
            'transaction_id' => $transfer->id,
        ]);
    }

    private function processPagarMeWithdrawal(): void
    {

        sleep(2);

        $this->update([
            'transaction_id' => 'PAGARME_'.time().'_'.$this->id,
        ]);
    }

    private function processBankTransfer(): void
    {

        sleep(3);

        $this->update([
            'transaction_id' => 'BANK_'.time().'_'.$this->id,
        ]);
    }

    private function processPixWithdrawal(): void
    {

        sleep(1);

        $this->update([
            'transaction_id' => 'PIX_'.time().'_'.$this->id,
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

    private function createTransactionRecord(): void
    {
        try {

            $existingTransaction = Transaction::where('user_id', $this->creator_id)
                ->where(function ($query) {
                    $query->whereJsonContains('payment_data->withdrawal_id', (string) $this->id)
                        ->orWhereJsonContains('payment_data->withdrawal_id', $this->id)
                        ->orWhereJsonContains('metadata->withdrawal_id', (string) $this->id)
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

            $withdrawalMethod = WithdrawalMethod::findByCode($this->withdrawal_method);
            $methodName = $withdrawalMethod ? $withdrawalMethod->name : $this->withdrawal_method_label;

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

            if ($this->withdrawal_details) {
                $paymentData = array_merge($paymentData, $this->withdrawal_details);
            }

            $paymentMethod = 'withdrawal';
            $stripePaymentIntentId = null;
            $stripeChargeId = null;

            if ($this->withdrawal_method === 'stripe_connect' || $this->withdrawal_method === 'stripe_card') {
                $paymentMethod = 'stripe_withdrawal';

                if ($this->transaction_id && strpos($this->transaction_id, 'tr_') === 0) {

                    $stripePaymentIntentId = $this->transaction_id;
                }
            } elseif ($this->withdrawal_method === 'pagarme_bank_transfer') {
                $paymentMethod = 'pagarme_withdrawal';
            } elseif ($this->withdrawal_method === 'pix') {
                $paymentMethod = 'pix_withdrawal';
            } elseif ($this->withdrawal_method === 'bank_transfer') {
                $paymentMethod = 'bank_transfer_withdrawal';
            }

            $cardBrand = null;
            $cardLast4 = null;
            $cardHolderName = null;

            if ($this->withdrawal_details) {

                if ($this->withdrawal_method === 'stripe_connect_bank_account') {
                    $cardBrand = $this->withdrawal_details['bank_name'] ?? null;
                    $cardLast4 = $this->withdrawal_details['bank_last4'] ?? null;
                    $cardHolderName = $this->withdrawal_details['account_holder_name'] ?? null;
                } else {

                    $cardBrand = $this->withdrawal_details['card_brand'] ?? null;
                    $cardLast4 = $this->withdrawal_details['card_last4'] ?? null;
                    $cardHolderName = $this->withdrawal_details['card_holder_name'] ?? null;
                }
            }

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

            Log::error('Failed to create transaction record for withdrawal', [
                'withdrawal_id' => $this->id,
                'creator_id' => $this->creator_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function cancel(?string $reason = null): bool
    {
        if (! $this->canBeCancelled()) {
            return false;
        }

        $this->update([
            'status' => 'cancelled',
            'failure_reason' => $reason,
        ]);

        $this->refundToCreator();

        self::createWithdrawalNotification('cancelled', $reason);

        return true;
    }

    public function getFormattedAmountAttribute(): string
    {
        return 'R$ '.number_format($this->amount, 2, ',', '.');
    }

    public function getPercentageFeeAttribute(): float
    {
        $withdrawalMethod = WithdrawalMethod::findByCode($this->withdrawal_method);
        if (! $withdrawalMethod) {
            return 0;
        }

        return (float) $withdrawalMethod->fee;
    }

    public function getPercentageFeeAmountAttribute(): float
    {
        return ($this->amount * $this->percentage_fee) / 100;
    }

    public function getPlatformFeeAmountAttribute(): float
    {
        return ($this->amount * $this->platform_fee) / 100;
    }

    public function getTotalFeesAttribute(): float
    {
        return $this->percentage_fee_amount + $this->platform_fee_amount + $this->fixed_fee;
    }

    public function getNetAmountAttribute(): float
    {
        return $this->amount - $this->total_fees;
    }

    public function getFormattedPlatformFeeAttribute(): string
    {
        return number_format($this->platform_fee, 2).'%';
    }

    public function getFormattedPlatformFeeAmountAttribute(): string
    {
        return 'R$ '.number_format($this->platform_fee_amount, 2, ',', '.');
    }

    public function getFormattedFixedFeeAttribute(): string
    {
        return 'R$ '.number_format($this->fixed_fee, 2, ',', '.');
    }

    public function getFormattedPercentageFeeAmountAttribute(): string
    {
        return 'R$ '.number_format($this->percentage_fee_amount, 2, ',', '.');
    }

    public function getFormattedTotalFeesAttribute(): string
    {
        return 'R$ '.number_format($this->total_fees, 2, ',', '.');
    }

    public function getFormattedNetAmountAttribute(): string
    {
        return 'R$ '.number_format($this->net_amount, 2, ',', '.');
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
