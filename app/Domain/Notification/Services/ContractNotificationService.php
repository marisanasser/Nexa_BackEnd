<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Mail\DeliveryMaterialApproved;
use App\Mail\DeliveryMaterialRejected;
use App\Mail\MilestoneApproved;
use App\Mail\MilestoneDelayWarning;
use App\Mail\MilestoneRejected;
use App\Mail\NewDeliveryMaterial;
use App\Models\Campaign\DeliveryMaterial;
use App\Models\Common\Notification;
use App\Models\Contract\Contract;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;


class ContractNotificationService
{
    public static function notifyCreatorOfContractStarted(Contract $contract): void
    {
        try {
            $notification = Notification::createContractStarted($contract->creator_id, [
                'contract_id' => $contract->id,
                'contract_title' => $contract->title,
                'brand_name' => $contract->brand->name ?? 'Marca',
            ]);

            NotificationService::sendSocketNotification($contract->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify creator of contract started', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyBrandOfContractStarted(Contract $contract): void
    {
        try {
            $notification = Notification::createContractStarted($contract->brand_id, [
                'contract_id' => $contract->id,
                'contract_title' => $contract->title,
                'creator_name' => $contract->creator->name ?? 'Criador',
            ]);

            NotificationService::sendSocketNotification($contract->brand_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify brand of contract started', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfNewOffer($offer): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $offer->creator_id,
                'type' => 'new_offer',
                'title' => 'Nova Oferta Recebida',
                'message' => "Você recebeu uma nova oferta de R$ {$offer->formatted_budget}",
                'data' => [
                    'offer_id' => $offer->id,
                    'brand_id' => $offer->brand_id,
                    'brand_name' => $offer->brand->name,
                    'budget' => $offer->budget,
                    'estimated_days' => $offer->estimated_days,
                ],
                'is_read' => false,
            ]);

            NotificationService::sendSocketNotification($offer->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of new offer', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfOfferAccepted($offer): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $offer->brand_id,
                'type' => 'offer_accepted',
                'title' => 'Oferta Aceita',
                'message' => 'Sua oferta foi aceita pelo criador',
                'data' => [
                    'offer_id' => $offer->id,
                    'creator_id' => $offer->creator_id,
                    'creator_name' => $offer->creator->name,
                    'budget' => $offer->budget,
                    'contract_id' => $offer->contract->id ?? null,
                ],
                'is_read' => false,
            ]);

            NotificationService::sendSocketNotification($offer->brand_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of offer acceptance', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfOfferRejected($offer, ?string $reason = null): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $offer->brand_id,
                'type' => 'offer_rejected',
                'title' => 'Oferta Rejeitada',
                'message' => 'Sua oferta foi rejeitada pelo criador' . ($reason ? ": {$reason}" : ''),
                'data' => [
                    'offer_id' => $offer->id,
                    'creator_id' => $offer->creator_id,
                    'creator_name' => $offer->creator->name,
                    'rejection_reason' => $reason,
                ],
                'is_read' => false,
            ]);

            NotificationService::sendSocketNotification($offer->brand_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of offer rejection', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfOfferCancelled($offer): void
    {
        try {
            $notification = Notification::createOfferCancelled($offer->creator_id, [
                'offer_id' => $offer->id,
                'offer_title' => $offer->title,
                'brand_id' => $offer->brand_id,
                'brand_name' => $offer->brand->name,
                'cancelled_at' => now()->toISOString(),
            ]);

            NotificationService::sendSocketNotification($offer->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of offer cancellation', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyBrandOfReviewRequired($contract): void
    {
        try {
            $notification = Notification::createReviewRequired($contract->brand_id, [
                'contract_id' => $contract->id,
                'contract_title' => $contract->title,
                'creator_id' => $contract->creator_id,
                'creator_name' => $contract->creator->name,
                'completed_at' => $contract->completed_at->toISOString(),
            ]);

            NotificationService::sendSocketNotification($contract->brand_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify brand of review requirement', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyCreatorOfContractCompleted($contract): void
    {
        try {
            $notification = Notification::createContractCompleted($contract->creator_id, [
                'contract_id' => $contract->id,
                'contract_title' => $contract->title,
                'brand_id' => $contract->brand_id,
                'brand_name' => $contract->brand->name,
                'completed_at' => $contract->completed_at->toISOString(),
            ]);

            NotificationService::sendSocketNotification($contract->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify creator of contract completion', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfNewReview($review): void
    {
        try {
            $notification = Notification::createNewReview($review->reviewed_id, [
                'review_id' => $review->id,
                'contract_id' => $review->contract_id,
                'contract_title' => $review->contract->title,
                'reviewer_id' => $review->reviewer_id,
                'reviewer_name' => $review->reviewer->name,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'created_at' => $review->created_at->toISOString(),
            ]);

            NotificationService::sendSocketNotification($review->reviewed_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of new review', [
                'review_id' => $review->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfContractTerminated($contract, ?string $reason = null): void
    {
        try {
            $users = [$contract->brand_id, $contract->creator_id];

            foreach ($users as $userId) {
                $notification = Notification::createContractTerminated($userId, [
                    'contract_id' => $contract->id,
                    'contract_title' => $contract->title,
                    'terminated_at' => $contract->cancelled_at->toISOString(),
                    'reason' => $reason,
                ]);

                NotificationService::sendSocketNotification($userId, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify users of contract termination', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyBrandOfDeliveryMaterialAction($material, $action): void
    {
        try {
            $actionText = 'approved' === $action ? 'aprovou' : 'rejeitou';
            $actionTitle = 'approved' === $action ? 'Material Aprovado' : 'Material Rejeitado';

            $notification = Notification::create([
                'user_id' => $material->brand_id,
                'type' => 'delivery_material_action',
                'title' => $actionTitle,
                'message' => "Você {$actionText} o material '{$material->file_name}' do criador {$material->creator->name} para o contrato '{$material->contract->title}'.",
                'data' => [
                    'material_id' => $material->id,
                    'contract_id' => $material->contract_id,
                    'contract_title' => $material->contract->title,
                    'creator_name' => $material->creator->name,
                    'file_name' => $material->file_name,
                    'media_type' => $material->media_type,
                    'action' => $action,
                    'action_at' => $material->reviewed_at->toISOString(),
                    'comment' => $material->comment,
                    'rejection_reason' => $material->rejection_reason,
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($material->brand_id, $notification);

            try {
                $material->load(['contract', 'creator', 'brand']);
                if ('approved' === $action) {
                    Mail::to($material->brand->email)->send(new DeliveryMaterialApproved($material));
                } else {
                    Mail::to($material->brand->email)->send(new DeliveryMaterialRejected($material));
                }
            } catch (Exception $emailError) {
                Log::error('Failed to send delivery material action email to brand', [
                    'material_id' => $material->id,
                    'brand_email' => $material->brand->email,
                    'action' => $action,
                    'error' => $emailError->getMessage(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify brand of delivery material action', [
                'material_id' => $material->id,
                'brand_id' => $material->brand_id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyCreatorOfMilestoneApproval($milestone): void
    {
        try {
            $milestone->load(['contract.creator', 'contract.brand']);

            $notification = Notification::create([
                'user_id' => $milestone->contract->creator_id,
                'type' => 'milestone_approved',
                'title' => 'Milestone Aprovado',
                'message' => "O milestone '{$milestone->title}' foi aprovado para o contrato '{$milestone->contract->title}'.",
                'data' => [
                    'milestone_id' => $milestone->id,
                    'contract_id' => $milestone->contract_id,
                    'contract_title' => $milestone->contract->title,
                    'brand_name' => $milestone->contract->brand->name,
                    'milestone_type' => $milestone->milestone_type,
                    'approved_at' => now()->toISOString(),
                    'comment' => $milestone->comment,
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($milestone->contract->creator_id, $notification);

            try {
                Mail::to($milestone->contract->creator->email)->send(new MilestoneApproved($milestone));
            } catch (Exception $emailError) {
                Log::error('Failed to send milestone approval email', [
                    'milestone_id' => $milestone->id,
                    'creator_email' => $milestone->contract->creator->email,
                    'error' => $emailError->getMessage(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify creator of milestone approval', [
                'milestone_id' => $milestone->id,
                'creator_id' => $milestone->contract->creator_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyCreatorOfMilestoneRejection($milestone): void
    {
        try {
            $milestone->load(['contract.creator', 'contract.brand']);

            $notification = Notification::create([
                'user_id' => $milestone->contract->creator_id,
                'type' => 'milestone_rejected',
                'title' => 'Milestone Rejeitado',
                'message' => "O milestone '{$milestone->title}' foi rejeitado para o contrato '{$milestone->contract->title}'.",
                'data' => [
                    'milestone_id' => $milestone->id,
                    'contract_id' => $milestone->contract_id,
                    'contract_title' => $milestone->contract->title,
                    'brand_name' => $milestone->contract->brand->name,
                    'milestone_type' => $milestone->milestone_type,
                    'rejected_at' => now()->toISOString(),
                    'comment' => $milestone->comment,
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($milestone->contract->creator_id, $notification);

            try {
                Mail::to($milestone->contract->creator->email)->send(new MilestoneRejected($milestone));
            } catch (Exception $emailError) {
                Log::error('Failed to send milestone rejection email', [
                    'milestone_id' => $milestone->id,
                    'creator_email' => $milestone->contract->creator->email,
                    'error' => $emailError->getMessage(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify creator of milestone rejection', [
                'milestone_id' => $milestone->id,
                'creator_id' => $milestone->contract->creator_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyCreatorOfMilestoneDelay($milestone): void
    {
        try {
            $milestone->load(['contract.creator', 'contract.brand']);

            $notification = Notification::create([
                'user_id' => $milestone->contract->creator_id,
                'type' => 'milestone_delay_warning',
                'title' => 'Aviso de Atraso - Milestone',
                'message' => "⚠️ O milestone '{$milestone->title}' está atrasado. Justifique o atraso para evitar penalidades de 7 dias sem novos convites.",
                'data' => [
                    'milestone_id' => $milestone->id,
                    'contract_id' => $milestone->contract_id,
                    'contract_title' => $milestone->contract->title,
                    'brand_name' => $milestone->contract->brand->name,
                    'milestone_type' => $milestone->milestone_type,
                    'deadline' => $milestone->deadline->toISOString(),
                    'days_overdue' => $milestone->getDaysOverdue(),
                    'warning_sent_at' => now()->toISOString()
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($milestone->contract->creator_id, $notification);

            try {
                Mail::to($milestone->contract->creator->email)->send(new MilestoneDelayWarning($milestone));
            } catch (Exception $emailError) {
                Log::error('Failed to send milestone delay warning email', [
                    'milestone_id' => $milestone->id,
                    'creator_email' => $milestone->contract->creator->email,
                    'error' => $emailError->getMessage(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify creator of milestone delay warning', [
                'milestone_id' => $milestone->id,
                'creator_id' => $milestone->contract->creator_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfContractCancelled($contract, ?string $reason = null): void
    {
        try {
            $users = [$contract->brand_id, $contract->creator_id];

            foreach ($users as $userId) {
                $notification = Notification::create([
                    'user_id' => $userId,
                    'type' => 'contract_cancelled',
                    'title' => 'Contrato Cancelado',
                    'message' => "O contrato '{$contract->title}' foi cancelado." . ($reason ? " Motivo: {$reason}" : ''),
                    'data' => [
                        'contract_id' => $contract->id,
                        'contract_title' => $contract->title,
                        'cancelled_at' => $contract->cancelled_at ? $contract->cancelled_at->toISOString() : now()->toISOString(),
                        'reason' => $reason,
                    ],
                    'is_read' => false,
                ]);

                NotificationService::sendSocketNotification($userId, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify users of contract cancellation', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUsersOfDisputeResolution(Contract $contract, string $resolution, string $winner, string $reason): void
    {
        try {
            $users = [$contract->brand_id, $contract->creator_id];

            foreach ($users as $userId) {
                $notification = Notification::create([
                    'user_id' => $userId,
                    'type' => 'dispute_resolved',
                    'title' => 'Disputa de Contrato Resolvida',
                    'message' => "A disputa do contrato '{$contract->title}' foi resolvida. Resolução: {$resolution}. Vencedor: {$winner}. Motivo: {$reason}",
                    'data' => [
                        'contract_id' => $contract->id,
                        'contract_title' => $contract->title,
                        'resolution' => $resolution,
                        'winner' => $winner,
                        'reason' => $reason,
                        'resolved_at' => now()->toISOString(),
                    ],
                    'is_read' => false,
                ]);

                NotificationService::sendSocketNotification($userId, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify users of dispute resolution', [
                'contract_id' => $contract->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyBrandOfNewDeliveryMaterial(DeliveryMaterial $material): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $material->brand_id,
                'type' => 'new_delivery_material',
                'title' => 'Novo Material Entregue',
                'message' => "O criador {$material->creator->name} entregou um novo material para o contrato '{$material->contract->title}'.",
                'data' => [
                    'material_id' => $material->id,
                    'contract_id' => $material->contract_id,
                    'contract_title' => $material->contract->title,
                    'creator_name' => $material->creator->name,
                    'file_name' => $material->file_name,
                    'media_type' => $material->media_type,
                    'submitted_at' => $material->submitted_at->toISOString(),
                ],
                'is_read' => false,
            ]);

            NotificationService::sendSocketNotification($material->brand_id, $notification);

            try {
                $material->load(['contract', 'creator']);
                Mail::to($material->brand->email)->send(new NewDeliveryMaterial($material));
            } catch (Exception $emailError) {
                Log::error('Failed to send new delivery material email to brand', [
                    'material_id' => $material->id,
                    'brand_email' => $material->brand->email,
                    'error' => $emailError->getMessage(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify brand of new delivery material', [
                'material_id' => $material->id,
                'brand_id' => $material->brand_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyCreatorOfDeliveryMaterialApproval(DeliveryMaterial $material): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $material->creator_id,
                'type' => 'delivery_material_approved',
                'title' => 'Material Aprovado',
                'message' => "Seu material '{$material->file_name}' foi aprovado para o contrato '{$material->contract->title}'.",
                'data' => [
                    'material_id' => $material->id,
                    'contract_id' => $material->contract_id,
                    'contract_title' => $material->contract->title,
                    'brand_name' => $material->brand->name,
                    'file_name' => $material->file_name,
                    'approved_at' => $material->reviewed_at->toISOString(),
                    'comment' => $material->comment,
                ],
                'is_read' => false,
            ]);

            NotificationService::sendSocketNotification($material->creator_id, $notification);

            try {
                $material->load(['contract', 'creator', 'brand']);
                Mail::to($material->creator->email)->send(new DeliveryMaterialApproved($material));
            } catch (Exception $emailError) {
                Log::error('Failed to send delivery material approval email', [
                    'material_id' => $material->id,
                    'creator_email' => $material->creator->email,
                    'error' => $emailError->getMessage(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify creator of delivery material approval', [
                'material_id' => $material->id,
                'creator_id' => $material->creator_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyCreatorOfDeliveryMaterialRejection(DeliveryMaterial $material): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $material->creator_id,
                'type' => 'delivery_material_rejected',
                'title' => 'Material Rejeitado',
                'message' => "Seu material '{$material->file_name}' foi rejeitado para o contrato '{$material->contract_id}'.",
                'data' => [
                    'material_id' => $material->id,
                    'contract_id' => $material->contract_id,
                    'contract_title' => $material->contract->title,
                    'brand_name' => $material->brand->name,
                    'file_name' => $material->file_name,
                    'rejected_at' => $material->reviewed_at->toISOString(),
                    'rejection_reason' => $material->rejection_reason,
                    'comment' => $material->comment,
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($material->creator_id, $notification);

            try {
                $material->load(['contract', 'creator', 'brand']);
                Mail::to($material->creator->email)->send(new DeliveryMaterialRejected($material));
            } catch (Exception $emailError) {
                Log::error('Failed to send delivery material rejection email', [
                    'material_id' => $material->id,
                    'creator_email' => $material->creator->email,
                    'error' => $emailError->getMessage(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify creator of delivery material rejection', [
                'material_id' => $material->id,
                'creator_id' => $material->creator_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyBrandOfMilestoneSubmission($milestone): void
    {
        try {
            $milestone->load(['contract.creator', 'contract.brand']);

            $notification = Notification::create([
                'user_id' => $milestone->contract->brand_id,
                'type' => 'milestone_submitted',
                'title' => 'Milestone Submetido',
                'message' => "O criador submeteu o milestone '{$milestone->title}' para revisão.",
                'data' => [
                    'milestone_id' => $milestone->id,
                    'contract_id' => $milestone->contract_id,
                    'contract_title' => $milestone->contract->title,
                    'creator_name' => $milestone->contract->creator->name,
                    'submitted_at' => now()->toISOString(),
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($milestone->contract->brand_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify brand of milestone submission', [
                'milestone_id' => $milestone->id,
                'brand_id' => $milestone->contract->brand_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyCreatorOfMilestoneChangesRequested($milestone): void
    {
        try {
            $milestone->load(['contract.creator', 'contract.brand']);

            $notification = Notification::create([
                'user_id' => $milestone->contract->creator_id,
                'type' => 'milestone_changes_requested',
                'title' => 'Ajustes Solicitados',
                'message' => "A marca solicitou ajustes no milestone '{$milestone->title}'.",
                'data' => [
                    'milestone_id' => $milestone->id,
                    'contract_id' => $milestone->contract_id,
                    'contract_title' => $milestone->contract->title,
                    'brand_name' => $milestone->contract->brand->name,
                    'feedback' => $milestone->feedback,
                    'requested_at' => now()->toISOString(),
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($milestone->contract->creator_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify creator of milestone changes requested', [
                'milestone_id' => $milestone->id,
                'creator_id' => $milestone->contract->creator_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
