<?php

declare(strict_types=1);

namespace App\Domain\Campaign\Services;

use App\Models\Campaign\Campaign;
use App\Models\Campaign\CampaignApplication;
use App\Models\Common\Notification;
use App\Models\User\User;

use Exception;
use Illuminate\Support\Facades\Log;
use function in_array;

/**
 * CampaignApplicationService handles campaign application operations.
 *
 * Responsibilities:
 * - Submitting applications
 * - Approving/rejecting applications
 * - Managing application status
 */
class CampaignApplicationService
{
    /**
     * Submit an application to a campaign.
     */
    public function submitApplication(
        Campaign $campaign,
        User $creator,
        array $applicationData
    ): CampaignApplication {
        // Check if already applied
        $existing = CampaignApplication::where('campaign_id', $campaign->id)
            ->where('user_id', $creator->id)
            ->first()
        ;

        if ($existing) {
            throw new Exception('You have already applied to this campaign');
        }

        // Check if campaign is accepting applications
        if ('active' !== $campaign->status || !$campaign->is_active) {
            throw new Exception('This campaign is not accepting applications');
        }

        // Check if creator meets requirements
        if (!$this->meetsRequirements($campaign, $creator)) {
            throw new Exception('You do not meet the requirements for this campaign');
        }

        $application = CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'user_id' => $creator->id,
            'message' => $applicationData['message'] ?? null,
            'proposed_rate' => $applicationData['proposed_rate'] ?? null,
            'portfolio_items' => $applicationData['portfolio_items'] ?? [],
            'status' => 'pending',
        ]);

        // Notify brand
        $this->notifyBrandOfApplication($campaign, $creator, $application);

        Log::info('Application submitted', [
            'application_id' => $application->id,
            'campaign_id' => $campaign->id,
            'creator_id' => $creator->id,
        ]);

        return $application;
    }

    /**
     * Approve an application.
     */
    public function approve(CampaignApplication $application, ?string $message = null): CampaignApplication
    {
        if ('pending' !== $application->status) {
            throw new Exception('Only pending applications can be approved');
        }

        $application->update([
            'status' => 'approved',
            'approved_at' => now(),
            'response_message' => $message,
        ]);

        // Notify creator
        $this->notifyCreatorOfApproval($application);

        Log::info('Application approved', [
            'application_id' => $application->id,
        ]);

        return $application->fresh();
    }

    /**
     * Reject an application.
     */
    public function reject(CampaignApplication $application, ?string $reason = null): CampaignApplication
    {
        if ('pending' !== $application->status) {
            throw new Exception('Only pending applications can be rejected');
        }

        $application->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'response_message' => $reason,
        ]);

        // Notify creator
        $this->notifyCreatorOfRejection($application);

        Log::info('Application rejected', [
            'application_id' => $application->id,
            'reason' => $reason,
        ]);

        return $application->fresh();
    }

    /**
     * Withdraw an application.
     */
    public function withdraw(CampaignApplication $application, User $creator): CampaignApplication
    {
        if ($application->user_id !== $creator->id) {
            throw new Exception('You can only withdraw your own applications');
        }

        if (!in_array($application->status, ['pending', 'approved'])) {
            throw new Exception('This application cannot be withdrawn');
        }

        $application->update([
            'status' => 'withdrawn',
            'withdrawn_at' => now(),
        ]);

        Log::info('Application withdrawn', [
            'application_id' => $application->id,
        ]);

        return $application->fresh();
    }

    public function meetsRequirements(Campaign $campaign, User $creator): bool
    {
        // Check follower requirements (if min_followers exists on campaign)
        $minFollowers = $campaign->min_followers ?? null;
        $followerCount = $creator->follower_count ?? 0;
        if ($minFollowers && $followerCount < $minFollowers) {
            return false;
        }

        // Check platform requirements (if platform exists on campaign)
        $campaignPlatform = $campaign->platform ?? null;
        if ($campaignPlatform) {
            $creatorPlatforms = $creator->platforms ?? [];
            if (!in_array($campaignPlatform, $creatorPlatforms)) {
                return false;
            }
        }

        // Check location requirements (if location exists on both)
        $campaignLocation = $campaign->location ?? null;
        $creatorLocation = $creator->location ?? null;
        if ($campaignLocation && $creatorLocation !== $campaignLocation) {
            return false;
        }

        return true;
    }

    /**
     * Get applications for a campaign.
     */
    public function getApplicationsForCampaign(
        Campaign $campaign,
        ?string $status = null,
        int $perPage = 15
    ) {
        $query = CampaignApplication::where('campaign_id', $campaign->id)
            ->with(['user', 'user.portfolioItems'])
        ;

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Get applications by a creator.
     */
    public function getApplicationsByCreator(User $creator, ?string $status = null, int $perPage = 15)
    {
        $query = CampaignApplication::where('user_id', $creator->id)
            ->with(['campaign', 'campaign.user'])
        ;

        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Notify brand of new application.
     */
    private function notifyBrandOfApplication(
        Campaign $campaign,
        User $creator,
        CampaignApplication $application
    ): void {
        Notification::create([
            'user_id' => $campaign->user_id,
            'type' => 'new_application',
            'title' => 'New Application',
            'message' => "{$creator->name} applied to your campaign \"{$campaign->title}\"",
            'data' => [
                'application_id' => $application->getKey(),
                'campaign_id' => $campaign->getKey(),
                'creator_id' => $creator->getKey(),
            ],
        ]);
    }

    /**
     * Notify creator of approval.
     */
    private function notifyCreatorOfApproval(CampaignApplication $application): void
    {
        Notification::create([
            'user_id' => $application->creator_id,
            'type' => 'application_approved',
            'title' => 'Application Approved',
            'message' => "Your application for \"{$application->campaign->title}\" has been approved!",
            'data' => [
                'application_id' => $application->getKey(),
                'campaign_id' => $application->campaign->getKey(),
            ],
        ]);
    }

    /**
     * Notify creator of rejection.
     */
    private function notifyCreatorOfRejection(CampaignApplication $application): void
    {
        Notification::create([
            'user_id' => $application->creator_id,
            'type' => 'application_rejected',
            'title' => 'Application Not Accepted',
            'message' => "Your application for \"{$application->campaign->title}\" was not accepted.",
            'data' => [
                'application_id' => $application->getKey(),
                'campaign_id' => $application->campaign->getKey(),
            ],
        ]);
    }
}
