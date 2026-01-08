<?php

declare(strict_types=1);

namespace App\Domain\Contract\Services;

use App\Models\Contract\Contract;
use App\Models\User\User;
use Carbon\Carbon;
use Exception;
use Log;
use function in_array;

/**
 * ContractWorkflowService handles contract workflow operations.
 *
 * Responsibilities:
 * - Managing contract status transitions
 * - Handling milestones
 * - Processing delivery submissions
 * - Managing revisions
 */
class ContractWorkflowService
{
    /**
     * Contract status flow:
     * pending -> approved -> active -> pending_delivery -> completed
     * Any status can go to: cancelled, disputed
     */
    private const array STATUS_TRANSITIONS = [
        'pending' => ['approved', 'cancelled'],
        'approved' => ['active', 'cancelled'],
        'active' => ['pending_delivery', 'cancelled', 'disputed'],
        'pending_delivery' => ['in_revision', 'completed', 'disputed'],
        'in_revision' => ['pending_delivery', 'cancelled', 'disputed'],
        'completed' => [],
        'cancelled' => [],
        'disputed' => ['completed', 'cancelled'],
    ];

    /**
     * Check if a status transition is valid.
     */
    public function canTransition(string $currentStatus, string $newStatus): bool
    {
        $allowedTransitions = self::STATUS_TRANSITIONS[$currentStatus] ?? [];

        return in_array($newStatus, $allowedTransitions);
    }

    /**
     * Transition contract to a new status.
     */
    public function transitionTo(Contract $contract, string $newStatus, ?string $reason = null): Contract
    {
        if (!$this->canTransition($contract->status, $newStatus)) {
            throw new Exception("Cannot transition from {$contract->status} to {$newStatus}");
        }

        $oldStatus = $contract->status;

        $updateData = [
            'status' => $newStatus,
            'status_changed_at' => now(),
        ];

        // Handle specific transitions
        switch ($newStatus) {
            case 'active':
                $updateData['started_at'] = now();

                break;

            case 'completed':
                $updateData['completed_at'] = now();

                break;

            case 'cancelled':
                $updateData['cancelled_at'] = now();
                $updateData['cancellation_reason'] = $reason;

                break;

            case 'disputed':
                $updateData['disputed_at'] = now();
                $updateData['dispute_reason'] = $reason;

                break;
        }

        $contract->update($updateData);

        Log::info('Contract status transitioned', [
            'contract_id' => $contract->id,
            'from' => $oldStatus,
            'to' => $newStatus,
        ]);

        return $contract->fresh();
    }

    /**
     * Submit delivery for review.
     */
    public function submitDelivery(Contract $contract, array $deliveryData): Contract
    {
        if (!in_array($contract->status, ['active', 'in_revision'])) {
            throw new Exception('Contract must be active or in revision to submit delivery');
        }

        $contract->update([
            'status' => 'pending_delivery',
            'last_delivery_at' => now(),
            'delivery_notes' => $deliveryData['notes'] ?? null,
            'delivery_files' => $deliveryData['files'] ?? [],
        ]);

        Log::info('Delivery submitted for contract', [
            'contract_id' => $contract->id,
        ]);

        return $contract->fresh();
    }

    /**
     * Request revision on delivery.
     */
    public function requestRevision(Contract $contract, string $reason, ?User $requestedBy = null): Contract
    {
        if ('pending_delivery' !== $contract->status) {
            throw new Exception('Contract must be pending delivery to request revision');
        }

        // Check revision limit
        $maxRevisions = $contract->max_revisions ?? 3;
        if ($contract->revision_count >= $maxRevisions) {
            throw new Exception('Maximum number of revisions reached');
        }

        $contract->update([
            'status' => 'in_revision',
            'revision_count' => $contract->revision_count + 1,
            'last_revision_reason' => $reason,
            'last_revision_at' => now(),
            'last_revision_by' => $requestedBy?->id,
        ]);

        Log::info('Revision requested for contract', [
            'contract_id' => $contract->id,
            'revision_count' => $contract->revision_count,
        ]);

        return $contract->fresh();
    }

    /**
     * Approve delivery and complete contract.
     */
    public function approveDelivery(Contract $contract, ?User $approvedBy = null): Contract
    {
        if ('pending_delivery' !== $contract->status) {
            throw new Exception('Contract must be pending delivery to approve');
        }

        $contract->update([
            'status' => 'completed',
            'completed_at' => now(),
            'approved_by' => $approvedBy?->id,
            'workflow_status' => 'pending_payment_release',
        ]);

        Log::info('Delivery approved for contract', [
            'contract_id' => $contract->id,
        ]);

        return $contract->fresh();
    }

    /**
     * Cancel a contract.
     */
    public function cancelContract(Contract $contract, string $reason, ?User $cancelledBy = null): Contract
    {
        if (!$this->canTransition($contract->status, 'cancelled')) {
            throw new Exception("Cannot cancel contract in {$contract->status} status");
        }

        $contract->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'cancelled_by' => $cancelledBy?->id,
        ]);

        Log::info('Contract cancelled', [
            'contract_id' => $contract->id,
            'reason' => $reason,
        ]);

        return $contract->fresh();
    }

    /**
     * Start a dispute on a contract.
     */
    public function startDispute(Contract $contract, string $reason, User $disputedBy): Contract
    {
        if (!$this->canTransition($contract->status, 'disputed')) {
            throw new Exception("Cannot dispute contract in {$contract->status} status");
        }

        $contract->update([
            'status' => 'disputed',
            'disputed_at' => now(),
            'dispute_reason' => $reason,
            'disputed_by' => $disputedBy->id,
        ]);

        Log::info('Contract dispute started', [
            'contract_id' => $contract->id,
            'reason' => $reason,
        ]);

        return $contract->fresh();
    }

    /**
     * Resolve a dispute.
     */
    public function resolveDispute(
        Contract $contract,
        string $resolution,
        string $outcome,
        ?User $resolvedBy = null
    ): Contract {
        if ('disputed' !== $contract->status) {
            throw new Exception('Contract must be disputed to resolve');
        }

        $newStatus = 'completed' === $outcome ? 'completed' : 'cancelled';

        $contract->update([
            'status' => $newStatus,
            'dispute_resolved_at' => now(),
            'dispute_resolution' => $resolution,
            'dispute_resolved_by' => $resolvedBy?->id,
            'completed' === $newStatus ? 'completed_at' : 'cancelled_at' => now(),
        ]);

        Log::info('Contract dispute resolved', [
            'contract_id' => $contract->id,
            'outcome' => $outcome,
        ]);

        return $contract->fresh();
    }

    /**
     * Get contract progress percentage.
     */
    public function getProgressPercentage(Contract $contract): int
    {
        $statusProgress = [
            'pending' => 0,
            'approved' => 10,
            'active' => 25,
            'pending_delivery' => 75,
            'in_revision' => 60,
            'completed' => 100,
            'cancelled' => 0,
            'disputed' => 50,
        ];

        return $statusProgress[$contract->status] ?? 0;
    }

    /**
     * Check if contract is overdue.
     */
    public function isOverdue(Contract $contract): bool
    {
        if (!$contract->deadline) {
            return false;
        }

        if (in_array($contract->status, ['completed', 'cancelled'])) {
            return false;
        }

        return Carbon::parse($contract->deadline)->isPast();
    }

    /**
     * Get days until deadline.
     */
    public function getDaysUntilDeadline(Contract $contract): ?int
    {
        if (!$contract->deadline) {
            return null;
        }

        return Carbon::now()->diffInDays(Carbon::parse($contract->deadline), false);
    }
}
