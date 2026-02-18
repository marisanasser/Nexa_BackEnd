<?php

declare(strict_types=1);

namespace App\Models\Payment;

use App\Domain\Notification\Services\PaymentNotificationService;
use App\Models\User\User;
use App\Models\Payment\WithdrawalMethod;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Stripe\Charge;
use Stripe\Stripe;
use Stripe\Transfer;

/**
 * @property int         $id
 * @property int         $creator_id
 * @property float       $amount
 * @property float       $platform_fee
 * @property float       $fixed_fee
 * @property string      $withdrawal_method
 * @property null|array  $withdrawal_details
 * @property string      $status
 * @property null|string $transaction_id
 * @property null|string $failure_reason
 * @property null|Carbon $processed_at
 * @property null|Carbon $created_at
 * @property null|Carbon $updated_at
 * @property string      $formatted_amount
 * @property string      $withdrawal_method_label
 * @property float       $net_amount
 * @property float       $total_fees
 * @property float       $percentage_fee
 * @property float       $percentage_fee_amount
 * @property float       $platform_fee_amount
 * @property string      $status_color
 * @property string      $status_badge_color
 * @property int         $days_since_created
 * @property bool        $is_recent
 * @property null|array  $bank_account_info
 * @property null|array  $pix_info
 * @property string      $formatted_platform_fee_amount
 * @property string      $formatted_fixed_fee
 * @property string      $formatted_percentage_fee_amount
 * @property string      $formatted_total_fees
 * @property string      $formatted_net_amount
 * @property User        $creator
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|static where($column, $operator = null, $value = null, $boolean = 'and')
 * @method static \Illuminate\Database\Eloquent\Builder<static>|static query()
 * @method static static                                               create(array $attributes = [])
 * @method static static                                               find(mixed $id, array $columns = ['*'])
 * @method static static                                               findOrFail(mixed $id, array $columns = ['*'])
 * @method        string                                               formattedAmount()
 * @method        string                                               formattedPlatformFeeAmount()
 * @method        string                                               formattedFixedFee()
 * @method        string                                               formattedPercentageFeeAmount()
 * @method        string                                               formattedTotalFees()
 * @method        string                                               formattedNetAmount()
 * @method        float                                                totalFees()
 * @method        float                                                netAmount()
 * @method        bool                                                 canBeCancelled()
 * @method        bool                                                 cancel(?string $reason = null)
 * @method        \Illuminate\Pagination\LengthAwarePaginator          through(callable $callback)
 * @method        \Illuminate\Support\Collection                       getCollection()
 * @method static \Illuminate\Pagination\LengthAwarePaginator          paginate(int $perPage = null, array $columns = ['*'], string $pageName = 'page', int $page = null)
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 * @mixin \Illuminate\Database\Eloquent\Model
 */
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
        'failure_reason',
        'processed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
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
        return 'pending' === $this->status;
    }

    public function isProcessing(): bool
    {
        return 'processing' === $this->status;
    }

    public function isCompleted(): bool
    {
        return 'completed' === $this->status;
    }

    public function isFailed(): bool
    {
        return 'failed' === $this->status;
    }

    public function isCancelled(): bool
    {
        return 'cancelled' === $this->status;
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
            $this->processWithdrawal();

            $this->update([
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            $this->updateCreatorBalance();

            $this->createTransactionRecord();

            PaymentNotificationService::notifyUserOfWithdrawalStatus($this, 'completed');

            return true;
        } catch (Exception $e) {
            $this->update([
                'status' => 'failed',
                'failure_reason' => $e->getMessage(),
            ]);

            $this->refundToCreator();

            PaymentNotificationService::notifyUserOfWithdrawalStatus($this, 'failed', $e->getMessage());

            return false;
        }
    }

    public function cancel(?string $reason = null): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->update([
            'status' => 'cancelled',
            'failure_reason' => $reason,
        ]);

        $this->refundToCreator();

        PaymentNotificationService::notifyUserOfWithdrawalStatus($this, 'cancelled', $reason);

        return true;
    }

    public function formattedAmount(): string
    {
        return 'R$ ' . number_format((float) ($this->amount ?? 0), 2, ',', '.');
    }

    public function getFormattedAmountAttribute(): string
    {
        return $this->formattedAmount();
    }

    public function getPercentageFeeAttribute(): float
    {
        // Withdrawal fees are disabled by business rule.
        return 0;
    }

    public function getPercentageFeeAmountAttribute(): float
    {
        return ((float) $this->amount * (float) $this->percentage_fee) / 100;
    }

    public function getPlatformFeeAmountAttribute(): float
    {
        return 0;
    }

    public function totalFees(): float
    {
        return 0;
    }

    public function netAmount(): float
    {
        return (float) $this->amount;
    }

    public function getTotalFeesAttribute(): float
    {
        return $this->totalFees();
    }

    public function getNetAmountAttribute(): float
    {
        return $this->netAmount();
    }

    public function formattedPlatformFeeAmount(): string
    {
        return 'R$ ' . number_format($this->platform_fee_amount, 2, ',', '.');
    }

    public function formattedFixedFee(): string
    {
        return 'R$ ' . number_format((float) ($this->fixed_fee ?? 0), 2, ',', '.');
    }

    public function formattedPercentageFeeAmount(): string
    {
        return 'R$ ' . number_format($this->percentage_fee_amount, 2, ',', '.');
    }

    public function formattedTotalFees(): string
    {
        return 'R$ ' . number_format($this->totalFees(), 2, ',', '.');
    }

    public function formattedNetAmount(): string
    {
        return 'R$ ' . number_format($this->netAmount(), 2, ',', '.');
    }

    public function getFormattedPlatformFeeAttribute(): string
    {
        return number_format((float) ($this->platform_fee ?? 0), 2) . '%';
    }

    public function getFormattedPlatformFeeAmountAttribute(): string
    {
        return $this->formattedPlatformFeeAmount();
    }

    public function getFormattedFixedFeeAttribute(): string
    {
        return $this->formattedFixedFee();
    }

    public function getFormattedPercentageFeeAmountAttribute(): string
    {
        return $this->formattedPercentageFeeAmount();
    }

    public function getFormattedTotalFeesAttribute(): string
    {
        return $this->formattedTotalFees();
    }

    public function getFormattedNetAmountAttribute(): string
    {
        return $this->formattedNetAmount();
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
            case 'stripe_connect':
                return 'Stripe Connect';

            case 'stripe_connect_bank_account':
                return 'Stripe Conta Bancaria';

            case 'stripe_card':
                return 'Stripe Card';

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
        if ('bank_transfer' === $this->withdrawal_method && $this->withdrawal_details) {
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
        if ('pix' === $this->withdrawal_method && $this->withdrawal_details) {
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

    private function processWithdrawal(): void
    {
        switch ($this->withdrawal_method) {
            case 'stripe_connect':
            case 'stripe_connect_bank_account':
            case 'stripe_card':
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
                throw new Exception('Método de saque não suportado: ' . $this->withdrawal_method);
        }
    }

    private function findSourceChargeForCreator(int $creatorId): ?string
    {
        $jobPayment = JobPayment::where('creator_id', $creatorId)
            ->whereNotNull('transaction_id')
            ->orderBy('created_at', 'desc')
            ->get()
            ->first(fn($jobPayment) => is_numeric($jobPayment->transaction_id));

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
            $contractTransaction = Transaction::whereHas('contract', function ($query) use ($creatorId): void {
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

        $anyTransaction = Transaction::where('user_id', $creatorId)
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
                    Stripe::setApiKey($stripeSecret);

                    $requiredAmount = (int) round(((float) $this->amount) * 100);

                    $charges = Charge::all([
                        'limit' => 10,
                        'expand' => ['data.balance_transaction'],
                    ]);

                    foreach ($charges->data as $charge) {
                        if ($charge->amount < $requiredAmount) {
                            continue;
                        }

                        $transfers = Transfer::all([
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
            } catch (Exception $e) {
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
        if (!$creator || empty($creator->stripe_account_id)) {
            throw new Exception('Conta Stripe Connect não configurada para este criador.');
        }

        $stripeSecret = config('services.stripe.secret');
        if (empty($stripeSecret)) {
            throw new Exception('Stripe não configurado.');
        }

        Stripe::setApiKey($stripeSecret);

        $amountInCents = (int) round(((float) $this->amount) * 100);
        if ($amountInCents <= 0) {
            throw new Exception('Valor líquido inválido para transferência.');
        }

        $sourceChargeId = $this->findSourceChargeForCreator($this->creator_id);

        $transferParams = [
            'amount' => $amountInCents,
            'currency' => 'brl',
            'destination' => $creator->stripe_account_id,
            'metadata' => [
                'withdrawal_id' => (string) $this->id,
                'creator_id' => (string) $this->creator_id,
                'gross_amount' => (string) $this->amount,
                'fees_disabled' => 'true',
            ],
        ];

        if ($sourceChargeId) {
            $transferParams['source_transaction'] = $sourceChargeId;
        } else {
            Log::warning('No source charge found for withdrawal, attempting transfer from platform balance', [
                'withdrawal_id' => $this->id,
                'creator_id' => $this->creator_id,
                'amount' => $this->amount,
            ]);
        }

        $transfer = Transfer::create($transferParams);

        $this->update([
            'transaction_id' => $transfer->id,
        ]);
    }

    private function processPagarMeWithdrawal(): void
    {
        sleep(2);

        $this->update([
            'transaction_id' => 'PAGARME_' . time() . '_' . $this->id,
        ]);
    }

    private function processBankTransfer(): void
    {
        sleep(3);

        $this->update([
            'transaction_id' => 'BANK_' . time() . '_' . $this->id,
        ]);
    }

    private function processPixWithdrawal(): void
    {
        sleep(1);

        $this->update([
            'transaction_id' => 'PIX_' . time() . '_' . $this->id,
        ]);
    }

    private function updateCreatorBalance(): void
    {
        $balance = CreatorBalance::where('creator_id', $this->creator_id)->first();
        if ($balance) {
            $balance->withdraw((float) $this->amount);
        }
    }

    private function refundToCreator(): void
    {
        $balance = CreatorBalance::where('creator_id', $this->creator_id)->first();
        if ($balance) {
            $balance->increment('available_balance', (float) $this->amount);
        }
    }

    private function createTransactionRecord(): void
    {
        try {
            $existingTransaction = Transaction::where('user_id', $this->creator_id)
                ->where(function ($query): void {
                    $query->whereJsonContains('payment_data->withdrawal_id', (string) $this->id)
                        ->orWhereJsonContains('payment_data->withdrawal_id', $this->id)
                        ->orWhereJsonContains('metadata->withdrawal_id', (string) $this->id)
                        ->orWhereJsonContains('metadata->withdrawal_id', $this->id)
                    ;
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

            if (in_array($this->withdrawal_method, ['stripe_connect', 'stripe_connect_bank_account', 'stripe_card'], true)) {
                $paymentMethod = 'stripe_withdrawal';

                if ($this->transaction_id && str_starts_with($this->transaction_id, 'tr_')) {
                    $stripePaymentIntentId = $this->transaction_id;
                }
            } elseif ('pagarme_bank_transfer' === $this->withdrawal_method) {
                $paymentMethod = 'pagarme_withdrawal';
            } elseif ('pix' === $this->withdrawal_method) {
                $paymentMethod = 'pix_withdrawal';
            } elseif ('bank_transfer' === $this->withdrawal_method) {
                $paymentMethod = 'bank_transfer_withdrawal';
            }

            $cardBrand = null;
            $cardLast4 = null;
            $cardHolderName = null;

            if ($this->withdrawal_details) {
                if ('stripe_connect_bank_account' === $this->withdrawal_method) {
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
        } catch (Exception $e) {
            Log::error('Failed to create transaction record for withdrawal', [
                'withdrawal_id' => $this->id,
                'creator_id' => $this->creator_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
