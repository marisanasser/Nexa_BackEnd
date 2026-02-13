<?php

declare(strict_types=1);

namespace App\Domain\Contract\Services;

use App\Domain\Notification\Services\ContractNotificationService;
use App\Models\Contract\Contract;
use App\Models\Contract\ContractMilestone;
use App\Models\User\User;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ContractStateMachineService
{
    public function __construct(
        private ContractAuditService $auditService
    ) {}

    /**
     * Initialize the contract with standard milestones and set initial phase.
     */
    public function initialize(Contract $contract): void
    {
        DB::transaction(function () use ($contract) {
            // Create standard milestones
            $milestones = [
                [
                    'title' => 'Alinhamento e Logística',
                    'description' => 'Envio de produtos e alinhamento de expectativas.',
                    'order' => 1,
                    'status' => 'pending',
                ],
                [
                    'title' => 'Roteiro / Esboço',
                    'description' => 'Criação e aprovação do roteiro ou esboço inicial.',
                    'order' => 2,
                    'status' => 'pending',
                ],
                [
                    'title' => 'Produção do Conteúdo',
                    'description' => 'Produção e envio do conteúdo final para revisão.',
                    'order' => 3,
                    'status' => 'pending',
                ],
                [
                    'title' => 'Aprovação Final',
                    'description' => 'Aprovação final do conteúdo pela marca.',
                    'order' => 4,
                    'status' => 'pending',
                ],
            ];

            foreach ($milestones as $data) {
                $contract->milestones()->create($data);
            }

            // Set initial state
            $contract->update([
                'phase' => 'alignment',
                'status' => 'active',
                'workflow_status' => 'active',
            ]);

            // Set current milestone to the first one
            $firstMilestone = $contract->milestones()->orderBy('order')->first();
            if ($firstMilestone) {
                $contract->current_milestone_id = $firstMilestone->id;
                $firstMilestone->update(['status' => 'in_progress']);
                $contract->save();
            }

            $this->auditService->log($contract, 'contract_initialized', [
                'phase' => 'alignment',
                'milestones_count' => count($milestones),
            ]);
        });
    }

    /**
     * Submit a milestone (Creator action).
     */
    public function submitMilestone(ContractMilestone $milestone, array $data, User $user): void
    {
        if ($milestone->status === 'approved') {
            throw new Exception("Milestone already approved.");
        }

        DB::transaction(function () use ($milestone, $data, $user) {
            $oldStatus = $milestone->status;
            
            $milestone->update([
                'status' => 'submitted',
                'submission_data' => $data,
                'feedback' => null, // Clear previous feedback
            ]);

            $this->auditService->log($milestone->contract, 'milestone_submitted', [
                'milestone_id' => $milestone->id,
                'milestone_title' => $milestone->title,
                'old_status' => $oldStatus,
                'submission_data' => $data,
            ], $user);

            ContractNotificationService::notifyBrandOfMilestoneSubmission($milestone);
        });
    }

    /**
     * Approve a milestone (Brand action).
     */
    public function approveMilestone(ContractMilestone $milestone, User $user): void
    {
        if ($milestone->status !== 'submitted') {
            throw new Exception("Milestone must be submitted before approval.");
        }

        DB::transaction(function () use ($milestone, $user) {
            $milestone->update([
                'status' => 'approved',
                'completed_at' => now(),
            ]);

            $this->auditService->log($milestone->contract, 'milestone_approved', [
                'milestone_id' => $milestone->id,
                'milestone_title' => $milestone->title,
            ], $user);

            ContractNotificationService::notifyCreatorOfMilestoneApproval($milestone);

            $this->advanceToNextPhase($milestone->contract);
        });
    }

    /**
     * Request changes on a milestone (Brand action).
     */
    public function requestChanges(ContractMilestone $milestone, string $feedback, User $user): void
    {
        if ($milestone->status !== 'submitted') {
            throw new Exception("Milestone must be submitted to request changes.");
        }

        DB::transaction(function () use ($milestone, $feedback, $user) {
            $milestone->update([
                'status' => 'changes_requested',
                'feedback' => $feedback,
            ]);

            $this->auditService->log($milestone->contract, 'milestone_changes_requested', [
                'milestone_id' => $milestone->id,
                'milestone_title' => $milestone->title,
                'feedback' => $feedback,
            ], $user);

            ContractNotificationService::notifyCreatorOfMilestoneChangesRequested($milestone);
        });
    }

    /**
     * Advance the contract to the next phase/milestone.
     */
    private function advanceToNextPhase(Contract $contract): void
    {
        $currentMilestone = $contract->currentMilestone;
        
        // Find next milestone
        $nextMilestone = $contract->milestones()
            ->where('order', '>', $currentMilestone->order)
            ->orderBy('order')
            ->first();

        if ($nextMilestone) {
            // Advance to next milestone
            $contract->update([
                'current_milestone_id' => $nextMilestone->id,
            ]);
            
            $nextMilestone->update(['status' => 'in_progress']);

            // Update Phase based on milestone title or logic
            $newPhase = match($nextMilestone->order) {
                2 => 'creation',
                3 => 'production',
                4 => 'approval',
                default => $contract->phase
            };

            if ($newPhase !== $contract->phase) {
                $contract->update(['phase' => $newPhase]);
                $this->auditService->log($contract, 'phase_changed', ['new_phase' => $newPhase]);
            }

        } else {
            // No more milestones -> Move to Payment/Finished
            $contract->update(['phase' => 'payment']);
            $this->auditService->log($contract, 'phase_changed', ['new_phase' => 'payment']);
            
            // Trigger Payment Release Logic here if automated
            // Or wait for manual release
        }
    }
}
