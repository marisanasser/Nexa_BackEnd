<?php

declare(strict_types=1);

namespace App\Domain\Contract\Actions;

use App\Domain\Contract\DTOs\ContractCompletionResult;
use App\Domain\Payment\Services\ContractPaymentService;
use App\Models\Campaign\Campaign;
use App\Models\Contract\Contract;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CompleteContractAction handles the process of completing a contract.
 *
 * This action encapsulates all business logic for marking a contract as complete,
 * including updating statuses, releasing funds, updating parent campaign, and sending notifications.
 */
class CompleteContractAction
{
    public function __construct(
        private readonly ContractPaymentService $paymentService
    ) {
    }

    /**
     * Execute the contract completion process.
     *
     * @param Contract    $contract        The contract to complete
     * @param int         $completedBy     User ID of who is completing the contract
     * @param null|string $completionNotes Optional notes about the completion
     *
     * @return ContractCompletionResult Result object with success/failure info
     */
    public function execute(
        Contract $contract,
        int $completedBy,
        ?string $completionNotes = null
    ): ContractCompletionResult {
        if (!$this->canComplete($contract)) {
            return ContractCompletionResult::failure(
                'Contract cannot be completed in current status: ' . $contract->status
            );
        }

        try {
            return DB::transaction(function () use ($contract, $completedBy, $completionNotes) {
                // Update contract status
                $contract->update([
                    'status' => 'completed',
                    'workflow_status' => 'payment_available', // Ensure workflow aligns
                    'completed_at' => now(),
                    'completion_notes' => $completionNotes,
                    'completed_by' => $completedBy,
                ]);

                // Release payment via PaymentService
                $fundsReleased = false;

                try {
                    $this->paymentService->releasePaymentToCreator($contract);
                    $fundsReleased = true;
                } catch (Exception $e) {
                    Log::error('Payment release failed during contract completion', ['error' => $e->getMessage()]);

                    throw $e;
                }

                // Check and complete parent campaign if all contracts are done
                $this->checkAndCompleteCampaign($contract);

                // Send notifications
                $this->sendNotifications($contract);

                Log::info('Contract completed successfully', [
                    'contract_id' => $contract->id,
                    'creator_id' => $contract->creator_id,
                    'brand_id' => $contract->brand_id,
                    'completed_by' => $completedBy,
                ]);

                return ContractCompletionResult::success($contract, $fundsReleased ? 1.0 : 0.0);
            });
        } catch (Exception $e) {
            Log::error('Contract completion failed', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ContractCompletionResult::failure($e->getMessage());
        }
    }

    /**
     * Check if the contract can be completed.
     */
    private function canComplete(Contract $contract): bool
    {
        $completableStatuses = ['in_progress', 'pending_review', 'pending_completion', 'active'];

        return in_array($contract->status, $completableStatuses, true);
    }

    /**
     * Check if parent campaign is complete and update if so.
     */
    private function checkAndCompleteCampaign(Contract $contract): void
    {
        if (!$contract->relationLoaded('offer')) {
            $contract->load('offer');
        }

        if (!$contract->offer || !$contract->offer->campaign_id) {
            return;
        }

        $campaign = Campaign::find($contract->offer->campaign_id);
        if (!$campaign) {
            return;
        }

        if ('approved' !== $campaign->status || $campaign->isCompleted() || $campaign->isCancelled()) {
            return;
        }

        $allContracts = Contract::whereHas('offer', function ($query) use ($campaign): void {
            $query->where('campaign_id', $campaign->id);
        })->get();

        if ($allContracts->isEmpty()) {
            return;
        }

        // Check if every contract is either completed or cancelled
        $allCompletedOrCancelled = $allContracts->every(fn($c) => in_array($c->status, ['completed', 'cancelled'], true));

        if ($allCompletedOrCancelled) {
            $campaign->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('Campaign marked as completed via Action', [
                'campaign_id' => $campaign->id,
                'total_contracts' => $allContracts->count(),
            ]);
        }
    }

    /**
     * Send completion notifications to both parties.
     */
    private function sendNotifications(Contract $contract): void
    {
        try {
            // Ideally trigger events or call NotificationService
            // ContractNotificationService::notifyCreatorOfContractCompleted($contract);
            // ContractNotificationService::notifyBrandOfReviewRequired($contract);
        } catch (Exception $e) {
            Log::warning('Failed to send contract completion notifications', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
