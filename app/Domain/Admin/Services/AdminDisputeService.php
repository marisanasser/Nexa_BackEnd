<?php

declare(strict_types=1);

namespace App\Domain\Admin\Services;

use App\Domain\Notification\Services\ContractNotificationService;
use App\Models\Contract\Contract;
use Exception;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminDisputeService
{
    /**
     * Get disputed contracts with pagination.
     */
    public function getDisputedContracts(int $perPage = 20): LengthAwarePaginator
    {
        $contracts = Contract::with(['brand:id,name,email,avatar_url', 'creator:id,name,email,avatar_url'])
            ->where('status', 'disputed')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage)
        ;

        if ($contracts instanceof LengthAwarePaginator) {
            $contracts->through($this->transformContractData(...));
        }

        return $contracts;
    }

    /**
     * Resolve a contract dispute.
     */
    public function resolveDispute(int $contractId, string $resolution, string $reason, string $winner): array
    {
        $contract = Contract::with(['brand', 'creator'])->find($contractId);

        if (!$contract || 'disputed' !== $contract->status) {
            throw new Exception('Disputed contract not found');
        }

        $this->applyResolution($contract, $resolution, $reason, $winner);

        Log::info('Admin resolved contract dispute', [
            'contract_id' => $contract->getKey(),
            'admin_id' => Auth::id(),
            'resolution' => $resolution,
            'winner' => $winner,
            'reason' => $reason,
        ]);

        try {
            ContractNotificationService::notifyUsersOfDisputeResolution(
                $contract,
                $resolution,
                $winner,
                $reason
            );
        } catch (Exception $e) {
            Log::error('Failed to send dispute resolution notification', [
                'contract_id' => $contract->getKey(),
                'error' => $e->getMessage(),
            ]);
            // Don't fail the whole transaction just because notification failed, but log it.
        }

        return [
            'contract_id' => $contract->getKey(),
            'resolution' => $resolution,
            'winner' => $winner,
            'new_status' => $contract->status,
        ];
    }

    // ========================================
    // Private Helper Methods
    // ========================================

    private function applyResolution(Contract $contract, string $resolution, string $reason, string $winner): void
    {
        match ($resolution) {
            'complete' => $contract->update([
                'status' => 'completed',
                'workflow_status' => 'waiting_review',
            ]),
            'cancel' => $contract->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
            ]),
            'refund' => $this->handleRefundResolution($contract, $reason, $winner),
        };
    }

    private function handleRefundResolution(Contract $contract, string $reason, string $winner): void
    {
        if ('creator' === $winner) {
            $contract->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
            ]);
        } elseif ('brand' === $winner) {
            $contract->update([
                'status' => 'completed',
                'workflow_status' => 'waiting_review',
            ]);
            // Note: In a real refund scenario for brand, logic might be more complex
            // (e.g. triggering refund via Stripe). Assuming status update is sufficient per legacy code.
        }
    }

    private function transformContractData(Contract $contract): array
    {
        return [
            'id' => $contract->getKey(),
            'title' => $contract->title,
            'description' => $contract->description,
            'budget' => $contract->getFormattedBudgetAttribute(),
            'status' => $contract->status,
            'workflow_status' => $contract->workflow_status,
            'created_at' => $contract->created_at->format('Y-m-d H:i:s'),
            'brand' => [
                'id' => $contract->brand->id,
                'name' => $contract->brand->name,
                'email' => $contract->brand->email,
                'avatar_url' => $contract->brand->getAvatarAttribute(),
            ],
            'creator' => [
                'id' => $contract->creator->id,
                'name' => $contract->creator->name,
                'email' => $contract->creator->email,
                'avatar_url' => $contract->creator->getAvatarAttribute(),
            ],
        ];
    }
}
