<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Carbon\Carbon;
use App\Services\NotificationService;

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
        'workflow_status', // New field for detailed workflow tracking
        'has_brand_review',
        'has_creator_review',
        'has_both_reviews',
    ];

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

    // Relationships
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

    // User-specific review relationships
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

    /**
     * Check if brand has funded contracts for a specific application
     * 
     * @param int $brandId The brand user ID
     * @param int $campaignId The campaign ID
     * @param int $creatorId The creator user ID
     * @return array Returns array with 'has_funded' boolean and 'contracts_needing_funding' collection
     */
    public static function checkBrandFundsForApplication(int $brandId, int $campaignId, int $creatorId): array
    {
        // Find all contracts related to this application
        $contracts = self::where('brand_id', $brandId)
            ->where(function ($query) use ($campaignId, $creatorId) {
                // Check for contracts related to this campaign via offers
                $query->whereHas('offer', function ($q) use ($campaignId, $creatorId) {
                    $q->where('campaign_id', $campaignId)
                      ->where('creator_id', $creatorId);
                })
                // Or check for contracts with this creator (might be created directly)
                ->orWhere(function ($q) use ($creatorId) {
                    $q->where('creator_id', $creatorId)
                      ->whereNull('offer_id');
                });
            })
            ->get();

        // Separate contracts into funded and needing funding
        $fundedContracts = $contracts->filter(function ($contract) {
            return $contract->isFunded();
        });

        $contractsNeedingFunding = $contracts->filter(function ($contract) {
            return $contract->needsFunding();
        });

        return [
            'has_funded' => $fundedContracts->isNotEmpty(),
            'all_funded' => $contractsNeedingFunding->isEmpty() && $contracts->isNotEmpty(),
            'has_unfunded' => $contractsNeedingFunding->isNotEmpty(),
            'contracts' => $contracts,
            'funded_contracts' => $fundedContracts,
            'contracts_needing_funding' => $contractsNeedingFunding,
        ];
    }

    public function messages(): HasMany
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

    // Scopes
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
                    ->where('expected_completion_at', '<', now());
    }

    // Methods
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isDisputed(): bool
    {
        return $this->status === 'disputed';
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

    /**
     * Check if contract can be terminated
     */
    public function canBeTerminated(): bool
    {
        return $this->isActive() && !$this->isCompleted();
    }

    /**
     * Terminate a contract (brand only)
     */
    public function terminate(string $reason = null): bool
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

        // Notify both parties about termination
        NotificationService::notifyUserOfContractTerminated($this, $reason);

        // Check if campaign should be marked as cancelled
        $this->checkAndCancelCampaign();

        return true;
    }

    /**
     * Check if contract is waiting for review
     */
    public function isWaitingForReview(): bool
    {
        return $this->status === 'completed' && $this->workflow_status === 'waiting_review';
    }

    /**
     * Check if contract has been reviewed and payment is available
     */
    public function isPaymentAvailable(): bool
    {
        return $this->status === 'completed' && $this->workflow_status === 'payment_available';
    }

    /**
     * Check if contract payment has been withdrawn
     */
    public function isPaymentWithdrawn(): bool
    {
        return $this->status === 'completed' && $this->workflow_status === 'payment_withdrawn';
    }

    /**
     * Check if the brand has reviewed this contract
     */
    public function hasBrandReview(): bool
    {
        return $this->reviews()->where('reviewer_id', $this->brand_id)->exists();
    }

    /**
     * Check if the creator has reviewed this contract
     */
    public function hasCreatorReview(): bool
    {
        return $this->reviews()->where('reviewer_id', $this->creator_id)->exists();
    }

    /**
     * Check if both parties have reviewed each other
     */
    public function hasBothReviews(): bool
    {
        return $this->hasBrandReview() && $this->hasCreatorReview();
    }

    /**
     * Get the brand's review for this contract
     */
    public function getBrandReview()
    {
        return $this->reviews()->where('reviewer_id', $this->brand_id)->first();
    }

    /**
     * Get the creator's review for this contract
     */
    public function getCreatorReview()
    {
        return $this->reviews()->where('reviewer_id', $this->creator_id)->first();
    }

    /**
     * Update contract review status
     */
    public function updateReviewStatus(): void
    {
        $this->has_brand_review = $this->hasBrandReview();
        $this->has_creator_review = $this->hasCreatorReview();
        $this->has_both_reviews = $this->hasBothReviews();
        $this->save();
    }

    /**
     * Process payment after review is submitted
     */
    public function processPaymentAfterReview(): bool
    {
        // Only process payment for completed contracts where creator has reviewed the brand
        if ($this->status !== 'completed' || $this->workflow_status !== 'waiting_review') {
            return false;
        }

        // Check if creator has submitted a review
        $creatorReview = $this->reviews()->where('reviewer_id', $this->creator_id)->first();
        if (!$creatorReview) {
            return false;
        }

        // Update payment status to completed
        if ($this->payment) {
            $this->payment->update([
                'status' => 'completed',
            ]);
        }

        // Get or create creator balance
        $balance = CreatorBalance::firstOrCreate(
            ['creator_id' => $this->creator_id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_earned' => 0,
                'total_withdrawn' => 0,
            ]
        );

        // Ensure payment is in pending_balance first (in case it wasn't added during contract funding)
        // If the amount is not in pending_balance, add it first
        $balance->refresh(); // Get latest balance from database
        
        if ($balance->pending_balance < $this->creator_amount) {
            // Add the creator amount to pending_balance if it's not already there
            $amountToAdd = $this->creator_amount - $balance->pending_balance;
            if ($amountToAdd > 0) {
                $previousPendingBalance = $balance->pending_balance;
                $balance->addPendingAmount($amountToAdd);
                $balance->refresh(); // Refresh to get updated pending_balance
                
                \Illuminate\Support\Facades\Log::info('Added payment to pending balance during review processing', [
                    'contract_id' => $this->id,
                    'creator_id' => $this->creator_id,
                    'amount_added' => $amountToAdd,
                    'creator_amount' => $this->creator_amount,
                    'previous_pending_balance' => $previousPendingBalance,
                    'new_pending_balance' => $balance->pending_balance,
                ]);
            }
        }

        // Move escrow from pending to available balance upon review
        // Only proceed if the move is successful
        $moveSuccess = $balance->movePendingToAvailable($this->creator_amount);
        
        if (!$moveSuccess) {
            \Illuminate\Support\Facades\Log::error('Failed to move payment from pending to available balance', [
                'contract_id' => $this->id,
                'creator_id' => $this->creator_id,
                'creator_amount' => $this->creator_amount,
                'pending_balance' => $balance->pending_balance,
                'available_balance' => $balance->available_balance,
            ]);
            
            // Still try to add the amount directly to available_balance as fallback
            // This handles the case where payment was never in pending_balance
            $balance->increment('available_balance', $this->creator_amount);
            $balance->refresh();
            
            \Illuminate\Support\Facades\Log::info('Added payment directly to available balance as fallback', [
                'contract_id' => $this->id,
                'creator_id' => $this->creator_id,
                'creator_amount' => $this->creator_amount,
                'available_balance_after' => $balance->available_balance,
            ]);
        }
        
        // Only add to total_earned if we successfully moved or added to available_balance
        $balance->addEarning($this->creator_amount);

        // Update workflow status
        $this->update([
            'workflow_status' => 'payment_available',
        ]);

        // Notify creator that funds are available
        NotificationService::notifyCreatorOfPaymentAvailable($this);

        \Illuminate\Support\Facades\Log::info('Payment processed after review - moved to available balance', [
            'contract_id' => $this->id,
            'creator_id' => $this->creator_id,
            'creator_amount' => $this->creator_amount,
            'available_balance' => $balance->available_balance,
            'pending_balance' => $balance->pending_balance,
        ]);

        return true;
    }

    /**
     * Check if contract payment has been processed
     */
    public function hasPaymentProcessed(): bool
    {
        return $this->payment && $this->payment->status === 'completed';
    }

    /**
     * Check if contract is funded (brand has paid for this contract)
     * A contract is considered funded if:
     * 1. It has a payment record with status 'completed', OR
     * 2. It's active (meaning payment was processed and contract started), OR
     * 3. It's completed (meaning payment was processed and work is done)
     */
    public function isFunded(): bool
    {
        // Check if payment exists and is completed
        if ($this->hasPaymentProcessed()) {
            return true;
        }

        // If contract is active or completed, it means payment was processed
        if ($this->isActive() || $this->isCompleted()) {
            return true;
        }

        // Check if there's a completed transaction
        if ($this->payment && in_array($this->payment->status, ['completed', 'processing'])) {
            return true;
        }

        return false;
    }

    /**
     * Check if contract needs funding (brand hasn't paid yet)
     */
    public function needsFunding(): bool
    {
        // If contract is already funded, it doesn't need funding
        if ($this->isFunded()) {
            return false;
        }

        // Contract needs funding if:
        // 1. Status is pending and workflow is payment_pending, OR
        // 2. No payment exists, OR
        // 3. Payment status is pending or failed
        return ($this->status === 'pending' && $this->workflow_status === 'payment_pending') ||
               !$this->payment ||
               ($this->payment && in_array($this->payment->status, ['pending', 'failed']));
    }

    /**
     * Check if contract payment is pending
     */
    public function isPaymentPending(): bool
    {
        return $this->status === 'pending' && $this->workflow_status === 'payment_pending';
    }

    /**
     * Check if contract payment failed
     */
    public function isPaymentFailed(): bool
    {
        return $this->status === 'payment_failed' && $this->workflow_status === 'payment_failed';
    }

    /**
     * Check if contract can be started (payment processed)
     */
    public function canBeStarted(): bool
    {
        return $this->status === 'pending' && $this->hasPaymentProcessed();
    }

    /**
     * Retry payment for failed contracts
     */
    public function retryPayment(): bool
    {
        if (!$this->isPaymentFailed()) {
            return false;
        }

        $paymentService = new \App\Services\AutomaticPaymentService();
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

    /**
     * Mark payment as withdrawn
     */
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

        // Only allow completion of active contracts
        if ($this->status !== 'active') {
            return false;
        }

        // Calculate payment amounts
        $creatorAmount = $this->budget * 0.95;
        $platformFee = $this->budget * 0.05;

        // Check if transaction already exists for this contract
        $existingTransaction = \App\Models\Transaction::where('contract_id', $this->id)->first();
        
        // Create transaction record if it doesn't exist (for tracking brand's payment)
        if (!$existingTransaction) {
            $transaction = \App\Models\Transaction::create([
                'user_id' => $this->brand_id,
                'contract_id' => $this->id,
                'stripe_payment_intent_id' => 'contract_completed_' . $this->id,
                'status' => 'paid', // Assuming payment is already made to platform
                'amount' => $this->budget,
                'payment_method' => 'platform_escrow', // Indicates payment held by platform
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

        // Create payment record immediately when brand completes the campaign
        $jobPayment = JobPayment::create([
            'contract_id' => $this->id,
            'brand_id' => $this->brand_id,
            'creator_id' => $this->creator_id,
            'total_amount' => $this->budget,
            'platform_fee' => $platformFee,
            'creator_amount' => $creatorAmount,
            'payment_method' => 'platform_escrow',
            'status' => 'pending', // Payment is pending until creator reviews
            'transaction_id' => $transaction->id, // Link to the transaction
        ]);

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'workflow_status' => 'waiting_review', // Waiting for creator to review brand
            'platform_fee' => $platformFee,
            'creator_amount' => $creatorAmount,
        ]);

        // Notify brand that review is required
        NotificationService::notifyBrandOfReviewRequired($this);

        // Notify creator that contract is completed and waiting for review
        NotificationService::notifyCreatorOfContractCompleted($this);

        // Check if campaign should be marked as completed
        $this->checkAndCompleteCampaign();

        return true;
    }

    /**
     * Check if all contracts for the campaign are completed and mark campaign as completed
     */
    private function checkAndCompleteCampaign(): void
    {
        // Load offer relationship if not already loaded
        if (!$this->relationLoaded('offer')) {
            $this->load('offer');
        }

        // Get campaign through offer relationship
        if (!$this->offer || !$this->offer->campaign_id) {
            return;
        }

        $campaign = Campaign::find($this->offer->campaign_id);
        if (!$campaign) {
            return;
        }

        // Only check campaigns that are approved and not already completed/cancelled
        if ($campaign->status !== 'approved' || $campaign->isCompleted() || $campaign->isCancelled()) {
            return;
        }

        // Get all contracts for this campaign through offers
        $allContracts = self::whereHas('offer', function ($query) use ($campaign) {
            $query->where('campaign_id', $campaign->id);
        })->get();

        // If no contracts exist, don't mark campaign as completed
        if ($allContracts->isEmpty()) {
            return;
        }

        // Check if all contracts are completed or cancelled (not pending or active)
        $allCompletedOrCancelled = $allContracts->every(function ($contract) {
            return in_array($contract->status, ['completed', 'cancelled']);
        });

        // If all contracts are completed or cancelled, mark campaign as completed
        if ($allCompletedOrCancelled) {
            $campaign->complete();
            
            // Process payments for all completed contracts and withdraw to creators
            $this->processCampaignPayments($campaign, $allContracts);
            
            \Illuminate\Support\Facades\Log::info('Campaign marked as completed', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'completed_contracts_count' => $allContracts->where('status', 'completed')->count(),
                'cancelled_contracts_count' => $allContracts->where('status', 'cancelled')->count(),
                'total_contracts' => $allContracts->count(),
            ]);
        }
    }

    /**
     * Process payments for all completed contracts and withdraw to creators
     * Platform fee is 5%, creator gets 95%
     */
    private function processCampaignPayments(Campaign $campaign, $allContracts): void
    {
        // Get only completed contracts (not cancelled ones)
        $completedContracts = $allContracts->where('status', 'completed');
        
        if ($completedContracts->isEmpty()) {
            return;
        }

        foreach ($completedContracts as $contract) {
            // Get the payment for this contract
            $payment = JobPayment::where('contract_id', $contract->id)->first();
            
            if (!$payment) {
                \Illuminate\Support\Facades\Log::warning('No payment found for completed contract', [
                    'contract_id' => $contract->id,
                    'campaign_id' => $campaign->id,
                ]);
                continue;
            }

            // Process payment if it's still pending
            if ($payment->isPending()) {
                try {
                    // Process the payment to move it from pending to available balance
                    $payment->process();
                    
                    \Illuminate\Support\Facades\Log::info('Payment processed for contract on campaign completion', [
                        'contract_id' => $contract->id,
                        'campaign_id' => $campaign->id,
                        'payment_id' => $payment->id,
                        'creator_id' => $contract->creator_id,
                        'creator_amount' => $payment->creator_amount,
                        'platform_fee' => $payment->platform_fee,
                    ]);
                } catch (\Exception $e) {
                    \Illuminate\Support\Facades\Log::error('Failed to process payment for contract on campaign completion', [
                        'contract_id' => $contract->id,
                        'campaign_id' => $campaign->id,
                        'payment_id' => $payment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            } elseif ($payment->isCompleted()) {
                // Payment already completed, ensure balance is updated
                $balance = CreatorBalance::where('creator_id', $contract->creator_id)->first();
                if ($balance) {
                    // Ensure the creator amount is in available balance
                    // If it's still in pending, move it to available
                    $balance->refresh(); // Get latest balance
                    $moveSuccess = $balance->movePendingToAvailable($payment->creator_amount);
                        
                    if ($moveSuccess) {
                        \Illuminate\Support\Facades\Log::info('Moved payment to available balance for contract on campaign completion', [
                            'contract_id' => $contract->id,
                            'campaign_id' => $campaign->id,
                            'creator_id' => $contract->creator_id,
                            'creator_amount' => $payment->creator_amount,
                        ]);
                    } else {
                        // If move failed, add directly to available_balance as fallback
                        $balance->increment('available_balance', $payment->creator_amount);
                        $balance->refresh();
                        
                        \Illuminate\Support\Facades\Log::info('Added payment directly to available balance for contract on campaign completion (fallback)', [
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

    /**
     * Send chat message about contract completion
     */
    // private function sendContractCompletionMessage(): void
    // {
    //     try {
    //         // Get the chat room for this contract
    //         $chatRoom = \App\Models\ChatRoom::whereHas('offers', function ($query) {
    //             $query->where('id', $this->offer_id);
    //         })->first();

    //         if ($chatRoom) {
    //             // Message for brand asking for review
    //             $brandReviewMessage = "🎉 Campanha finalizada com sucesso!\n\n" .
    //                 "A campanha foi finalizada e o pagamento está pronto para ser liberado. " .
    //                 "Por favor, envie sua avaliação para completar o processo e liberar o pagamento para o criador.\n\n" .
    //                 "Seu feedback ajuda a manter a qualidade de nossa plataforma e apoia outros usuários a tomar decisões informadas.";

    //             // Message for creator about payment release
    //             $creatorPaymentMessage = "🎉 Campanha finalizada com sucesso!\n\n" .
    //                 "A marca finalizou a campanha e o pagamento está pronto para ser liberado. " .
    //                 "Para receber seu pagamento, por favor, envie uma avaliação da marca.\n\n" .
    //                 "Após ambas as avaliações serem enviadas, você poderá sacar seu pagamento.";

    //             // For system messages, use the creator's ID as sender to avoid potential foreign key issues
    //             \App\Models\Message::create([
    //                 'chat_room_id' => $chatRoom->id,
    //                 'sender_id' => $this->creator_id,
    //                 'message' => $brandReviewMessage,
    //                 'message_type' => 'text',
    //                 'is_system_message' => true,
    //             ]);

    //             \App\Models\Message::create([
    //                 'chat_room_id' => $chatRoom->id,
    //                 'sender_id' => $this->creator_id,
    //                 'message' => $creatorPaymentMessage,
    //                 'message_type' => 'text',
    //                 'is_system_message' => true,
    //             ]);
    //         }
    //     } catch (\Exception $e) {
    //         \Illuminate\Support\Facades\Log::error('Failed to send contract completion message', [
    //             'contract_id' => $this->id,
    //             'error' => $e->getMessage()
    //         ]);
    //     }
    // }

    public function cancel(string $reason = null): bool
    {
        if (!$this->canBeCancelled()) {
            return false;
        }

        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        // Notify both parties about cancellation
        NotificationService::notifyUserOfContractCancelled($this, $reason);

        // Check if campaign should be marked as cancelled
        $this->checkAndCancelCampaign();

        return true;
    }

    /**
     * Check if all contracts for the campaign are cancelled and mark campaign as cancelled
     */
    private function checkAndCancelCampaign(): void
    {
        // Load offer relationship if not already loaded
        if (!$this->relationLoaded('offer')) {
            $this->load('offer');
        }

        // Get campaign through offer relationship
        if (!$this->offer || !$this->offer->campaign_id) {
            return;
        }

        $campaign = Campaign::find($this->offer->campaign_id);
        if (!$campaign) {
            return;
        }

        // Only check campaigns that are approved and not already completed/cancelled
        if ($campaign->status !== 'approved' || $campaign->isCompleted() || $campaign->isCancelled()) {
            return;
        }

        // Get all contracts for this campaign through offers
        $allContracts = self::whereHas('offer', function ($query) use ($campaign) {
            $query->where('campaign_id', $campaign->id);
        })->get();

        // If no contracts exist, don't mark campaign as cancelled
        if ($allContracts->isEmpty()) {
            return;
        }

        // Check if all contracts are cancelled or terminated (not pending or active)
        $allCancelledOrTerminated = $allContracts->every(function ($contract) {
            return in_array($contract->status, ['cancelled', 'terminated']);
        });

        // If all contracts are cancelled or terminated, mark campaign as cancelled
        if ($allCancelledOrTerminated) {
            $campaign->cancel();
            
            \Illuminate\Support\Facades\Log::info('Campaign marked as cancelled', [
                'campaign_id' => $campaign->id,
                'campaign_title' => $campaign->title,
                'cancelled_contracts_count' => $allContracts->where('status', 'cancelled')->count(),
                'terminated_contracts_count' => $allContracts->where('status', 'terminated')->count(),
                'total_contracts' => $allContracts->count(),
            ]);
        }
    }

    public function dispute(string $reason = null): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        $this->update([
            'status' => 'disputed',
        ]);

        // Notify admin about dispute
        NotificationService::notifyAdminOfContractDispute($this, $reason);

        return true;
    }

    private function updateCreatorBalance(float $amount = null): void
    {
        $amount = $amount ?? $this->creator_amount;
        
        $balance = CreatorBalance::firstOrCreate(
            ['creator_id' => $this->creator_id],
            [
                'available_balance' => 0,
                'pending_balance' => 0,
                'total_earned' => 0,
                'total_withdrawn' => 0,
            ]
        );

        // Add to available balance since payment is processed after review
        $balance->increment('available_balance', $amount);
        $balance->increment('total_earned', $amount);
    }

    public function getFormattedBudgetAttribute(): string
    {
        return 'R$ ' . number_format($this->budget, 2, ',', '.');
    }

    public function getFormattedCreatorAmountAttribute(): string
    {
        return 'R$ ' . number_format($this->creator_amount, 2, ',', '.');
    }

    public function getFormattedPlatformFeeAttribute(): string
    {
        return 'R$ ' . number_format($this->platform_fee, 2, ',', '.');
    }

    public function getDaysUntilCompletionAttribute(): int
    {
        return max(0, now()->diffInDays($this->expected_completion_at, false));
    }

    public function getProgressPercentageAttribute(): int
    {
        $totalDays = $this->started_at->diffInDays($this->expected_completion_at);
        $elapsedDays = $this->started_at->diffInDays(now());
        
        return min(100, max(0, round(($elapsedDays / $totalDays) * 100)));
    }

    public function getIsNearCompletionAttribute(): bool
    {
        return $this->days_until_completion <= 2;
    }
} 