<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Mail\ApplicationReceived;
use App\Mail\CampaignApproved;
use App\Mail\CampaignCreated;
use App\Mail\CampaignRejected;
use App\Mail\ProposalApproved;
use App\Models\Campaign\Bid;
use App\Models\Campaign\Campaign;
use App\Models\Campaign\CampaignApplication;
use App\Models\Common\Notification;
use App\Models\User\User;
use Exception;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class CampaignNotificationService
{
    public static function notifyBrandOfCampaignCreated(Campaign $campaign): void
    {
        try {
            $campaign->load(['brand']);

            Mail::to($campaign->brand->email)->send(new CampaignCreated($campaign));

            Log::info('Campaign creation email sent successfully', [
                'campaign_id' => $campaign->id,
                'brand_email' => $campaign->brand->email,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send campaign creation email', [
                'campaign_id' => $campaign->id,
                'brand_email' => $campaign->brand->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyBrandOfNewApplication(CampaignApplication $application): void
    {
        try {
            $application->load(['campaign', 'campaign.brand', 'creator']);

            Mail::to($application->campaign->brand->email)->send(new ApplicationReceived($application));

            Log::info('Application received email sent successfully to brand', [
                'application_id' => $application->id,
                'campaign_id' => $application->campaign_id,
                'creator_id' => $application->creator_id,
                'creator_name' => $application->creator->name,
                'brand_email' => $application->campaign->brand->email,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to send application received email to brand', [
                'application_id' => $application->id,
                'campaign_id' => $application->campaign_id,
                'brand_email' => $application->campaign->brand->email ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyCreatorsOfNewProject(Campaign $campaign): void
    {
        try {
            $creators = User::where('role', 'creator')->get();

            foreach ($creators as $creator) {
                $notification = Notification::createNewProject(
                    $creator->id,
                    $campaign->id,
                    $campaign->title
                );

                NotificationService::sendSocketNotification($creator->id, $notification);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify creators of new project', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyBrandOfProjectStatus(Campaign $campaign, string $status, ?string $reason = null): void
    {
        try {
            if ('approved' === $status) {
                $notification = Notification::createProjectApproved(
                    $campaign->brand_id,
                    $campaign->id,
                    $campaign->title
                );
            } else {
                $notification = Notification::createProjectRejected(
                    $campaign->brand_id,
                    $campaign->id,
                    $campaign->title,
                    $reason
                );
            }

            NotificationService::sendSocketNotification($campaign->brand_id, $notification);

            try {
                $campaign->load(['brand']);
                if ('approved' === $status) {
                    Mail::to($campaign->brand->email)->send(new CampaignApproved($campaign));
                } else {
                    Mail::to($campaign->brand->email)->send(new CampaignRejected($campaign));
                }
            } catch (Exception $emailError) {
                Log::error('Failed to send campaign status email', [
                    'campaign_id' => $campaign->id,
                    'brand_email' => $campaign->brand->email,
                    'status' => $status,
                    'error' => $emailError->getMessage(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify brand of project status', [
                'campaign_id' => $campaign->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyCreatorOfProposalStatus(Bid $bid, string $status, ?string $reason = null): void
    {
        try {
            $campaign = $bid->campaign;
            $brand = $campaign->brand;

            if ('accepted' === $status) {
                $notification = Notification::createProposalApproved(
                    $bid->user_id,
                    $campaign->id,
                    $campaign->title,
                    $brand->name
                );
            } else {
                $notification = Notification::createProposalRejected(
                    $bid->user_id,
                    $campaign->id,
                    $campaign->title,
                    $brand->name,
                    $reason
                );
            }

            NotificationService::sendSocketNotification($bid->user_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify creator of proposal status', [
                'bid_id' => $bid->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyCreatorOfProposalApproval(CampaignApplication $application): void
    {
        try {
            $application->load(['campaign.brand', 'creator']);

            $notification = Notification::create([
                'user_id' => $application->creator_id,
                'type' => 'proposal_approved',
                'title' => '💖 Parabéns! Seu perfil foi selecionado!',
                'message' => 'Parabéns! Você tem a cara da marca e foi selecionada para uma parceria de sucesso! Prepare-se para mostrar todo o seu talento e representar a NEXA com criatividade e profissionalismo.',
                'data' => [
                    'application_id' => $application->id,
                    'campaign_id' => $application->campaign_id,
                    'campaign_title' => $application->campaign->title,
                    'brand_id' => $application->campaign->brand_id,
                    'brand_name' => $application->campaign->brand->name,
                    'proposed_budget' => $application->proposed_budget,
                    'estimated_delivery_days' => $application->estimated_delivery_days,
                    'approved_at' => $application->approved_at->toISOString(),
                ],
                'read_at' => null,
            ]);

            NotificationService::sendSocketNotification($application->creator_id, $notification);

            try {
                Mail::to($application->creator->email)->send(new ProposalApproved($application));
            } catch (Exception $emailError) {
                Log::error('Failed to send proposal approval email', [
                    'application_id' => $application->id,
                    'creator_email' => $application->creator->email,
                    'error' => $emailError->getMessage(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify creator of proposal approval', [
                'application_id' => $application->id,
                'creator_id' => $application->creator_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
