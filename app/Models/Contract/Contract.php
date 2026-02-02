<?php

declare(strict_types=1);

namespace App\Models\Contract;

use App\Domain\Notification\Services\AdminNotificationService;
use App\Domain\Notification\Services\ContractNotificationService;
use App\Domain\Notification\Services\PaymentNotificationService;
use App\Domain\Payment\Services\AutomaticPaymentService;
use App\Models\Campaign\Campaign;
use App\Models\Campaign\CampaignTimeline;
use App\Models\Campaign\DeliveryMaterial;
use App\Models\Chat\ChatRoom;
use App\Models\Chat\Message;
use App\Models\Payment\CreatorBalance;
use App\Models\Payment\JobPayment;
use App\Models\Payment\Transaction;
use App\Models\User\Review;
use App\Models\User\User;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Log;

use function in_array;

/**
 * @property int                 $id
 * @property int                 $offer_id
 * @property int                 $brand_id
 * @property int                 $creator_id
 * @property string              $title
 * @property string              $description
 * @property float               $budget
 * @property int                 $estimated_days
 * @property array               $requirements
 * @property string              $status
 * @property null|Carbon         $started_at
 * @property null|Carbon         $expected_completion_at
 * @property null|Carbon         $completed_at
 * @property null|Carbon         $cancelled_at
 * @property null|string         $cancellation_reason
 * @property float               $platform_fee
 * @property float               $creator_amount
 * @property string              $workflow_status
 * @property bool                $has_brand_review
 * @property bool                $has_creator_review
 * @property bool                $has_both_reviews
 * @property Carbon              $created_at
 * @property Carbon              $updated_at
 * @property null|Offer          $offer
 * @property null|User           $brand
 * @property null|User           $creator
 * @property Collection|Review[] $reviews
 * @property null|JobPayment     $payment
 */
class Contract extends Model
{
    use HasFactory;

    protected $fillable = [
        'offer_id',
        'brand_id',
        'creator_id',
        'title',
        'description',
        'budget',
        'estimated_days',
        'requirements',
        'status',
        'started_at',
        'expected_completion_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'platform_fee',
        'creator_amount',
        'workflow_status',
        'has_brand_review',
        'has_creator_review',
        'has_both_reviews',
    ];

    protected $appends = ['remaining_percentage'];

    protected $casts = [
        'budget' => 'decimal:2',
        'requirements' => 'array',
        'started_at' => 'datetime',
        'expected_completion_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'platform_fee' => 'decimal:2',
        'creator_amount' => 'decimal:2',
    ];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(User::class, 'brand_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function userReview($userId): HasOne
    {
        return $this->hasOne(Review::class)->where('reviewer_id', $userId);
    }

    public function brandReview(): HasOne
    {
        return $this->hasOne(Review::class)->where('reviewer_id', $this->brand_id);
    }

    public function creatorReview(): HasOne
    {
        return $this->hasOne(Review::class)->where('reviewer_id', $this->creator_id);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(JobPayment::class);
    }

    public static function checkBrandFundsForApplication(int $brandId, int $campaignId, int $creatorId): array
    {
        $contracts = self::where('brand_id', $brandId)
            ->where(function ($query) use ($campaignId, $creatorId): void {
                $query->whereHas('offer', function ($q) use ($campaignId, $creatorId): void {
                    $q->where('campaign_id', $campaignId)
                        ->where('creator_id', $creatorId)
                    ;
                })
                    ->orWhere(function ($q) use ($creatorId): void {
                        $q->where('creator_id', $creatorId)
                            ->whereNull('offer_id')
                        ;
                    })
                ;
            })
            ->get()
        ;

        $fundedContracts = $contracts->filter(fn ($contract) => $contract->isFunded());

        $contractsNeedingFunding = $contracts->filter(fn ($contract) => $contract->needsFunding());

        return [
            'has_funded' => $fundedContracts->isNotEmpty(),
            'all_funded' => $contractsNeedingFunding->isEmpty() && $contracts->isNotEmpty(),
            'has_unfunded' => $contractsNeedingFunding->isNotEmpty(),
            'contracts' => $contracts,
            'funded_contracts' => $fundedContracts,
            'contracts_needing_funding' => $contractsNeedingFunding,
        ];
    }

    public function messages(): HasManyThrough
    {
        return $this->hasManyThrough(Message::class, Offer::class, 'id', 'chat_room_id', 'offer_id', 'chat_room_id');
    }

    public function timeline(): HasMany
    {
        return $this->hasMany(CampaignTimeline::class);
    }

    public function deliveryMaterials(): HasMany
    {
        return $this->hasMany(DeliveryMaterial::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
    }

    public function scopeDisputed($query)
    {
        return $query->where('status', 'disputed');
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'active')
            ->where('expected_completion_at', '<', now())
        ;
    }

    public function isActive(): bool
    {
        return 'active' === $this->status;
    }

    public function isCompleted(): bool
    {
        return 'completed' === $this->status;
    }

    public function isCancelled(): bool
    {
        return 'cancelled' === $this->status;
    }

    public function isDisputed(): bool
    {
        return 'disputed' === $this->status;
    }

    public function isOverdue(): bool
    {
        return $this->isActive() && $this->expected_completion_at->isPast();
    }

    public function canBeCompleted(): bool
    {
        return $this->isActive();
    }

    public function canBeCancelled(): bool
    {
        return $this->isActive() && !$this->isCompleted();
    }

    public function canBeTerminated(): bool
    {
        return $this->isActive() && !$this->isCompleted();
    }

    public function terminate(?string $reason = null): bool
    {
        if (!$this->canBeTerminated()) {
            return false;
        }

        $this->update([
            'status' => 'terminated',
            'workflow_status' => 'terminated',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason ?? 'Contract terminated by brand',
        ]);

        ContractNotificationService::notifyUserOfContractTerminated($this, $reason);

        $this->checkAndCancelCampaign();

        return true;
    }

    public function isWaitingForReview(): bool
    {
        return 'completed' === $this->status && 'waiting_review' === $this->workflow_status;
    }

    public function isPaymentAvailable(): bool
    {
        return 'completed' === $this->status && 'payment_available' === $this->workflow_status;
    }

    public function isPaymentWithdrawn(): bool
    {
        return 'completed' === $this->status && 'payment_withdrawn' === $this->workflow_status;
    }

    public function hasBrandReview(): bool
    {
        return $this->reviews()->where('reviewer_id', $this->brand_id)->exists();
    }

    public function hasCreatorReview(): bool
    {
        return $this->reviews()->where('reviewer_id', $this->creator_id)->exists();
    }

    public function hasBothReviews(): bool
    {
        return $this->hasBrandReview() && $this->hasCreatorReview();
    }

    public function getBrandReview()
    {
        return $this->reviews()->where('reviewer_id', $this->brand_id)->first();
    }

    public function getCreatorReview()
    {
        return $this->reviews()->where('reviewer_id', $this->creator_id)->first();
    }

    public function updateReviewStatus(): void
    {
        $this->has_brand_review = $this->hasBrandReview();
        $this->has_creator_review = $this->hasCreatorReview();
        $this->has_both_reviews = $this->hasBothReviews();
        $this->save();
    }

    public function processPaymentAfterReview(): bool
    {
        if ('completed' !== $this->status || 'waiting_review' !== $this->workflow_status) {
            return false;
        }

        $creatorReview = $this->reviews()->where('reviewer_id', $this->creator_id)->first();
        if (!$creatorReview) {
            return false;
        }

        if ($this->payment) {
            $this->payment->update([
                'status' => 'completed',
            ]);
        }

        $balance = CreatorBalance::firstOrCreate(
            ['creator_id' => $this->creator_id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_earned' => 0,
                'total_withdrawn' => 0,
            ]
        );

        $balance->refresh();

        if ($balance->pending_balance < $this->creator_amount) {
            $amountToAdd = $this->creator_amount - $balance->pending_balance;
            if ($amountToAdd > 0) {
                $previousPendingBalance = $balance->pending_balance;
                $balance->addPendingAmount($amountToAdd);
                $balance->refresh();

                Log::info('Added payment to pending balance during review processing', [
                    'contract_id' => $this->id,
                    'creator_id' => $this->creator_id,
                    'amount_added' => $amountToAdd,
                    'creator_amount' => $this->creator_amount,
                    'previous_pending_balance' => $previousPendingBalance,
                    'new_pending_balance' => $balance->pending_balance,
                ]);
            }
        }

        $moveSuccess = $balance->movePendingToAvailable($this->creator_amount);

        if (!$moveSuccess) {
            Log::error('Failed to move payment from pending to available balance', [
                'contract_id' => $this->id,
                'creator_id' => $this->creator_id,
                'creator_amount' => $this->creator_amount,
                'pending_balance' => $balance->pending_balance,
                'available_balance' => $balance->available_balance,
            ]);

            $balance->increment('available_balance', $this->creator_amount);
            $balance->refresh();

            Log::info('Added payment directly to available balance as fallback', [
                'contract_id' => $this->id,
                'creator_id' => $this->creator_id,
                'creator_amount' => $this->creator_amount,
                'available_balance_after' => $balance->available_balance,
            ]);
        }

        $balance->addEarning($this->creator_amount);

        $this->update([
            'workflow_status' => 'payment_available',
        ]);

        PaymentNotificationService::notifyCreatorOfPaymentAvailable($this);

        Log::info('Payment processed after review - moved to available balance', [
            'contract_id' => $this->id,
            'creator_id' => $this->creator_id,
            'creator_amount' => $this->creator_amount,
            'available_balance' => $balance->available_balance,
            'pending_balance' => $balance->pending_balance,
        ]);

        // Arquiva o chat após o pagamento ser processado
        $this->archiveChatRoom();

        return true;
    }

    /**
     * Arquiva o chat room associado a este contrato.
     * Chamado automaticamente após o pagamento ser processado.
     */
    public function archiveChatRoom(): bool
    {
        // Busca o chat room através da offer
        if (!$this->offer || !$this->offer->chat_room_id) {
            Log::info('No chat room to archive for contract', [
                'contract_id' => $this->id,
            ]);
            return false;
        }

        $chatRoom = ChatRoom::find($this->offer->chat_room_id);
        
        if (!$chatRoom) {
            Log::warning('Chat room not found for archiving', [
                'contract_id' => $this->id,
                'chat_room_id' => $this->offer->chat_room_id,
            ]);
            return false;
        }

        // Verifica se todos os contratos desta campanha estão completos
        $campaignContracts = self::whereHas('offer', function ($query) use ($chatRoom) {
            $query->where('campaign_id', $chatRoom->campaign_id);
        })->get();

        $allCompleted = $campaignContracts->every(fn ($contract) => 
            in_array($contract->status, ['completed', 'cancelled', 'terminated'])
        );

        if ($allCompleted) {
            $archived = $chatRoom->archive(ChatRoom::CLOSURE_PAYMENT_COMPLETED);

            if ($archived) {
                Log::info('Chat room archived after payment completion', [
                    'contract_id' => $this->id,
                    'chat_room_id' => $chatRoom->id,
                    'campaign_id' => $chatRoom->campaign_id,
                ]);
            }

            return $archived;
        }

        // Se ainda há contratos pendentes, apenas marca como completo
        $chatRoom->markAsCompleted(ChatRoom::CLOSURE_CONTRACT_COMPLETED);

        return true;
    }

    public function hasPaymentProcessed(): bool
    {
        return $this->payment && 'completed' === $this->payment->status;
    }

    public function isFunded(): bool
    {
        if ($this->hasPaymentProcessed()) {
            return true;
        }

        if ($this->isActive() || $this->isCompleted()) {
            return true;
        }

        if ($this->payment && in_array($this->payment->status, ['completed', 'processing'])) {
            return true;
        }

        return false;
    }

    public function needsFunding(): bool
    {
        if ($this->isFunded()) {
            return false;
        }

        return ('pending' === $this->status && 'payment_pending' === $this->workflow_status)
               || !$this->payment
               || ($this->payment && in_array($this->payment->status, ['pending', 'failed']));
    }

    public function isPaymentPending(): bool
    {
        return 'pending' === $this->status && 'payment_pending' === $this->workflow_status;
    }

    public function isPaymentFailed(): bool
    {
        return 'payment_failed' === $this->status && 'payment_failed' === $this->workflow_status;
    }

    public function canBeStarted(): bool
    {
        return 'pending' === $this->status && $this->hasPaymentProcessed();
    }

    public function retryPayment(): bool
    {
        if (!$this->isPaymentFailed()) {
            return false;
        }

        $paymentService = new AutomaticPaymentService();
        $paymentResult = $paymentService->processContractPayment($this);

        if ($paymentResult['success']) {
            $this->update([
                'status' => 'active',
                'workflow_status' => 'active',
            ]);

            return true;
        }

        return false;
    }

    public function markPaymentWithdrawn(): bool
    {
        if (!$this->isPaymentAvailable()) {
            return false;
        }

        $this->update([
            'workflow_status' => 'payment_withdrawn',
        ]);

        return true;
    }

    public function complete(): bool
    {
        if (!$this->canBeCompleted()) {
            return false;
        }

        if ('active' !== $this->status) {
            return false;
        }

        $creatorAmount = $this->budget * 0.95;
        $platformFee = $this->budget * 0.05;

        $existingTransaction = Transaction::where('contract_id', $this->id)->first();

        if (!$existingTransaction) {
            $transaction = Transaction::create([
                'user_id' => $this->brand_id,
                'contract_id' => $this->id,
                'stripe_payment_intent_id' => 'contract_completed_'.$this->id,
                'status' => 'paid',
                'amount' => $this->budget,
                'payment_method' => 'platform_escrow',
                'payment_data' => [
                    'type' => 'contract_completion',
                    'contract_id' => $this->id,
                    'created_at_completion' => true,
                ],
                'paid_at' => now(),
            ]);
        } else {
            $transaction = $existingTransaction;
        }

        $jobPayment = JobPayment::create([
            'contract_id' => $this->id,
            'brand_id' => $this->brand_id,
            'creator_id' => $this->creator_id,
            'total_amount' => $this->budget,
            'platform_fee' => $platformFee,
            'creator_amount' => $creatorAmount,
            'payment_method' => 'platform_escrow',
            'status' => 'pending',
            'transaction_id' => $transaction->id,
        ]);

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'workflow_status' => 'waiting_review',
            'platform_fee' => $platformFee,
            'creator_amount' => $creatorAmount,
        ]);

        ContractNotificationService::notifyBrandOfReviewRequired($this);

        ContractNotificationService::notifyCreatorOfContractCompleted($this);

        $this->checkAndCompleteCampaign();

        return true;
    }

    public function cancel(?string $reason = null): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        ContractNotificationService::notifyUserOfContractCancelled($this, $reason);

        $this->checkAndCancelCampaign();

        return true;
    }

    public function dispute(?string $reason = null): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $this->update([
            'status' => 'disputed',
        ]);

        AdminNotificationService::notifyAdminOfContractDispute($this, $reason);

        return true;
    }

    public function getFormattedBudgetAttribute(): string
    {
        return 'R$ '.number_format((float) ($this->budget ?? 0), 2, ',', '.');
    }

    public function getFormattedCreatorAmountAttribute(): string
    {
        return 'R$ '.number_format((float) ($this->creator_amount ?? 0), 2, ',', '.');
    }

    public function getFormattedPlatformFeeAttribute(): string
    {
        return 'R$ '.number_format((float) ($this->platform_fee ?? 0), 2, ',', '.');
    }

    public function getDaysUntilCompletionAttribute(): int
    {
        return max(0, now()->diffInDays($this->expected_completion_at, false));
    }

    public function getProgressPercentageAttribute(): int
    {
        if (!$this->started_at || !$this->expected_completion_at) {
            return 0;
        }

        $totalDays = $this->started_at->diffInDays($this->expected_completion_at);

        if ($totalDays <= 0) {
            return 100;
        }

        $elapsedDays = $this->started_at->diffInDays(now());

        return min(100, max(0, round(($elapsedDays / $totalDays) * 100)));
    }

    public function getRemainingPercentageAttribute(): int
    {
        return 100 - $this->progress_percentage;
    }

    public function getIsNearCompletionAttribute(): bool
    {
        return $this->days_until_completion <= 2;
    }

    private function checkAndCompleteCampaign(): void
    {
        if (!$this->relationLoaded('offer')) {
            $this->load('offer');
        }

        if (!$this->offer || !$this->offer->campaign_id) {
            return;
        }

        $campaign = Campaign::find($this->offer->campaign_id);
        if (!$campaign) {
            return;
        }

        if ('approved' !== $campaign->status || $campaign->isCompleted() || $campaign->isCancelled()) {
            return;
        }

        $allContracts = self::whereHas('offer', function ($query) use ($campaign): void {
            $query->where('campaign_id', $campaign->id);
        })->get();

        if ($allContracts->isEmpty()) {
            return;
        }

        $allCompletedOrCancelled = $allContracts->every(fn ($contract) => in_array($contract->status, ['completed', 'cancelled']));

        if ($allCompletedOrCancelled) {
            $campaign->complete();

            $this->processCampaignPayments($campaign, $allContracts);

            Log::info('Campaign marked as completed', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'completed_contracts_count' => $allContracts->where('status', 'completed')->count(),
                'cancelled_contracts_count' => $allContracts->where('status', 'cancelled')->count(),
                'total_contracts' => $allContracts->count(),
            ]);
        }
    }

    private function processCampaignPayments(Campaign $campaign, $allContracts): void
    {
        $completedContracts = $allContracts->where('status', 'completed');

        if ($completedContracts->isEmpty()) {
            return;
        }

        foreach ($completedContracts as $contract) {
            $payment = JobPayment::where('contract_id', $contract->id)->first();

            if (!$payment) {
                Log::warning('No payment found for completed contract', [
                    'contract_id' => $contract->id,
                    'campaign_id' => $campaign->id,
                ]);

                continue;
            }

            if ($payment->isPending()) {
                try {
                    $payment->process();

                    Log::info('Payment processed for contract on campaign completion', [
                        'contract_id' => $contract->id,
                        'campaign_id' => $campaign->id,
                        'payment_id' => $payment->id,
                        'creator_id' => $contract->creator_id,
                        'creator_amount' => $payment->creator_amount,
                        'platform_fee' => $payment->platform_fee,
                    ]);
                } catch (Exception $e) {
                    Log::error('Failed to process payment for contract on campaign completion', [
                        'contract_id' => $contract->id,
                        'campaign_id' => $campaign->id,
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif ($payment->isCompleted()) {
                $balance = CreatorBalance::where('creator_id', $contract->creator_id)->first();
                if ($balance) {
                    $balance->refresh();
                    $moveSuccess = $balance->movePendingToAvailable($payment->creator_amount);

                    if ($moveSuccess) {
                        Log::info('Moved payment to available balance for contract on campaign completion', [
                            'contract_id' => $contract->id,
                            'campaign_id' => $campaign->id,
                            'creator_id' => $contract->creator_id,
                            'creator_amount' => $payment->creator_amount,
                        ]);
                    } else {
                        $balance->increment('available_balance', $payment->creator_amount);
                        $balance->refresh();

                        Log::info('Added payment directly to available balance for contract on campaign completion (fallback)', [
                            'contract_id' => $contract->id,
                            'campaign_id' => $campaign->id,
                            'creator_id' => $contract->creator_id,
                            'creator_amount' => $payment->creator_amount,
                            'available_balance_after' => $balance->available_balance,
                        ]);
                    }
                }
            }
        }
    }

    private function checkAndCancelCampaign(): void
    {
        if (!$this->relationLoaded('offer')) {
            $this->load('offer');
        }

        if (!$this->offer || !$this->offer->campaign_id) {
            return;
        }

        $campaign = Campaign::find($this->offer->campaign_id);
        if (!$campaign) {
            return;
        }

        if ('approved' !== $campaign->status || $campaign->isCompleted() || $campaign->isCancelled()) {
            return;
        }

        $allContracts = self::whereHas('offer', function ($query) use ($campaign): void {
            $query->where('campaign_id', $campaign->id);
        })->get();

        if ($allContracts->isEmpty()) {
            return;
        }

        $allCancelledOrTerminated = $allContracts->every(fn ($contract) => in_array($contract->status, ['cancelled', 'terminated']));

        if ($allCancelledOrTerminated) {
            $campaign->cancel();

            Log::info('Campaign marked as cancelled', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'cancelled_contracts_count' => $allContracts->where('status', 'cancelled')->count(),
                'terminated_contracts_count' => $allContracts->where('status', 'terminated')->count(),
                'total_contracts' => $allContracts->count(),
            ]);
        }
    }

    private function updateCreatorBalance(?float $amount = null): void
    {
        $amount ??= $this->creator_amount;

        $balance = CreatorBalance::firstOrCreate(
            ['creator_id' => $this->creator_id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_earned' => 0,
                'total_withdrawn' => 0,
            ]
        );

        $balance->increment('available_balance', $amount);
        $balance->increment('total_earned', $amount);
    }
}
