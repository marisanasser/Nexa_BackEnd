<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignApplication;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class CampaignApplicationController extends Controller
{
    /**
     * Display a listing of applications based on user role
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $query = CampaignApplication::with(['campaign', 'creator', 'reviewer'])
            ->whereHas('creator'); // Only include applications where creator still exists

        if ($user->isCreator()) {
            // Creators see their own applications
            $query->byCreator($user->id);
        } elseif ($user->isStudent()) {
            // Students see their own applications (they can apply)
            $query->byCreator($user->id);
        } elseif ($user->isBrand()) {
            // Brands see applications for their campaigns
            $query->whereHas('campaign', function ($q) use ($user) {
                $q->where('brand_id', $user->id);
            });
        } elseif ($user->isAdmin()) {
            // Admins see all applications
            // No additional filtering needed
        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Filter by status if provided
        if ($request->has('status')) {
            $status = $request->get('status');
            if (in_array($status, ['pending', 'approved', 'rejected'])) {
                $query->where('status', $status);
            }
        }

        // Filter by campaign if provided
        if ($request->has('campaign_id')) {
            $query->byCampaign($request->get('campaign_id'));
        }

        $applications = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $applications
        ]);
    }

    /**
     * Store a newly created application
     */
    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $user = Auth::user();

        // Only creators and students can apply
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json(['message' => 'Only creators and students can apply to campaigns'], 403);
        }

        // Check if campaign is active and approved
        if (!$campaign->isApproved() || !$campaign->is_active) {
            return response()->json(['message' => 'Campaign is not available for applications'], 400);
        }

        // Check if creator already applied
        if ($campaign->applications()->where('creator_id', $user->id)->exists()) {
            return response()->json(['message' => 'You have already applied to this campaign'], 400);
        }

        $validator = Validator::make($request->all(), [
            'proposal' => 'required|string|min:10|max:2000',
            'portfolio_links' => 'nullable|array',
            'portfolio_links.*' => 'url',
            'estimated_delivery_days' => 'nullable|integer|min:1|max:365',
            'proposed_budget' => 'nullable|numeric|min:0|max:999999.99'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $application = CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'creator_id' => $user->id,
            'proposal' => $request->proposal,
            'portfolio_links' => $request->portfolio_links,
            'estimated_delivery_days' => $request->estimated_delivery_days,
            'proposed_budget' => $request->proposed_budget,
            'status' => 'pending'
        ]);

        // Notify admin of new application
        \App\Services\NotificationService::notifyAdminOfNewApplication($application);

        // Send email notification to brand about new application received
        \App\Services\NotificationService::notifyBrandOfNewApplication($application);

        return response()->json([
            'success' => true,
            'message' => 'Application submitted successfully',
            'data' => $application->load(['campaign', 'creator'])
        ], 201);
    }

    /**
     * Display the specified application
     */
    public function show(CampaignApplication $application): JsonResponse
    {
        $user = Auth::user();

        // Check if user has access to this application
        if (($user->isCreator() || $user->isStudent()) && $application->creator_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isBrand() && $application->campaign->brand_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $application->load(['campaign', 'creator', 'reviewer'])
        ]);
    }

    /**
     * Approve an application (Brand only)
     */
    public function approve(CampaignApplication $application): JsonResponse
    {
        $user = Auth::user();

        // Only brands can approve applications for their campaigns
        if (!$user->isBrand() || $application->campaign->brand_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$application->canBeReviewedBy($user)) {
            return response()->json(['message' => 'Application cannot be approved'], 400);
        }

        $application->approve($user->id);

        // Automatically create chat room when proposal is approved
        try {
            $chatRoom = \App\Models\ChatRoom::findOrCreateRoom(
                $application->campaign_id,
                $user->id, // brand_id
                $application->creator_id
            );

            // Update application workflow status to indicate first contact has been initiated
            if ($chatRoom->wasRecentlyCreated) {
                $application->initiateFirstContact();
                
                \Log::info('Application workflow status updated to agreement_in_progress', [
                    'application_id' => $application->id,
                    'campaign_id' => $application->campaign_id,
                    'creator_id' => $application->creator_id,
                    'workflow_status' => $application->workflow_status,
                ]);
                
                // Send initial offer automatically when chat room is created
                $chatController = new \App\Http\Controllers\ChatController();
                $chatController->sendInitialOfferIfNeeded($chatRoom);
            }

            \Log::info('Chat room created automatically for approved proposal', [
                'application_id' => $application->id,
                'chat_room_id' => $chatRoom->id,
                'room_id' => $chatRoom->room_id,
                'campaign_id' => $application->campaign_id,
                'brand_id' => $user->id,
                'creator_id' => $application->creator_id,
            ]);
        } catch (\Exception $e) {
            \Log::error('Failed to create chat room for approved proposal', [
                'application_id' => $application->id,
                'campaign_id' => $application->campaign_id,
                'brand_id' => $user->id,
                'creator_id' => $application->creator_id,
                'error' => $e->getMessage(),
            ]);
        }

        // Notify creator about proposal approval
        \App\Services\NotificationService::notifyCreatorOfProposalApproval($application);

        // Notify admin of application approval
        \App\Services\NotificationService::notifyAdminOfSystemActivity('application_approved', [
            'application_id' => $application->id,
            'campaign_id' => $application->campaign_id,
            'campaign_title' => $application->campaign->title,
            'creator_name' => $application->creator->name,
            'brand_name' => $application->campaign->brand->name,
            'proposal_amount' => $application->proposed_budget,
            'approved_by' => $user->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Application approved successfully.',
            'data' => $application->load(['campaign', 'creator'])
        ]);
    }

    /**
     * Reject an application (Brand only)
     */
    public function reject(Request $request, CampaignApplication $application): JsonResponse
    {
        $user = Auth::user();

        // Only brands can reject applications for their campaigns
        if (!$user->isBrand() || $application->campaign->brand_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$application->canBeReviewedBy($user)) {
            return response()->json(['message' => 'Application cannot be rejected'], 400);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $application->reject($user->id, $request->rejection_reason);

        // Notify admin of application rejection
        \App\Services\NotificationService::notifyAdminOfSystemActivity('application_rejected', [
            'application_id' => $application->id,
            'campaign_id' => $application->campaign_id,
            'campaign_title' => $application->campaign->title,
            'creator_name' => $application->creator->name,
            'brand_name' => $application->campaign->brand->name,
            'proposal_amount' => $application->proposed_budget,
            'rejected_by' => $user->name,
            'rejection_reason' => $request->rejection_reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Application rejected successfully',
            'data' => $application->load(['campaign', 'creator'])
        ]);
    }

    /**
     * Withdraw an application (Creator only)
     */
    public function withdraw(CampaignApplication $application): JsonResponse
    {
        $user = Auth::user();

        // Only creators and students can withdraw their own applications
        if ((!$user->isCreator() && !$user->isStudent()) || $application->creator_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$application->canBeWithdrawnBy($user)) {
            return response()->json(['message' => 'Application cannot be withdrawn'], 400);
        }

        $application->delete();

        // Notify admin of application withdrawal
        \App\Services\NotificationService::notifyAdminOfSystemActivity('application_withdrawn', [
            'application_id' => $application->id,
            'campaign_id' => $application->campaign_id,
            'campaign_title' => $application->campaign->title,
            'creator_name' => $application->creator->name,
            'brand_name' => $application->campaign->brand->name,
            'proposal_amount' => $application->proposed_budget,
            'withdrawn_by' => $user->name,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Application withdrawn successfully'
        ]);
    }

    /**
     * Get applications for a specific campaign
     */
    public function campaignApplications(Campaign $campaign): JsonResponse
    {
        $user = Auth::user();

        // Only brand owner or admin can see applications for a campaign
        if ($user->isCreator()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isBrand() && $campaign->brand_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $applications = $campaign->applications()
            ->with(['creator', 'reviewer'])
            ->whereHas('creator') // Only include applications where creator still exists
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $applications
        ]);
    }

    /**
     * Get application statistics
     */
    public function statistics(): JsonResponse
    {
        $user = Auth::user();
        $query = CampaignApplication::query();

        if ($user->isCreator()) {
            $query->byCreator($user->id);
        } elseif ($user->isStudent()) {
            // Students see their own application statistics
            $query->byCreator($user->id);
        } elseif ($user->isBrand()) {
            $query->whereHas('campaign', function ($q) use ($user) {
                $q->where('brand_id', $user->id);
            });
        }

        $statistics = [
            'total' => $query->count(),
            'pending' => $query->clone()->pending()->count(),
            'approved' => $query->clone()->approved()->count(),
            'rejected' => $query->clone()->rejected()->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $statistics
        ]);
    }
}