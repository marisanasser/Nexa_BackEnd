<?php

declare(strict_types=1);

namespace App\Http\Controllers\Campaign;

use App\Domain\Notification\Services\AdminNotificationService;
use App\Domain\Notification\Services\CampaignNotificationService;
use App\Domain\Shared\Traits\HasAuthenticatedUser;
use App\Http\Controllers\Base\Controller;
use App\Http\Controllers\Chat\ChatController;
use App\Models\Campaign\Campaign;
use App\Models\Campaign\CampaignApplication;
use App\Models\Chat\ChatRoom;
use App\Models\Contract\Contract;
use App\Models\Contract\Offer;
use App\Models\Payment\BrandPaymentMethod;
use App\Models\User\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Stripe\Account;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Customer;
use Stripe\Stripe;

class CampaignApplicationController extends Controller

{
     use HasAuthenticatedUser;
    public function index(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $query = CampaignApplication::with(['campaign.brand', 'creator', 'reviewer'])
            ->whereHas('creator')
        ;

        if ($user->isCreator()) {
            $query->byCreator($user->id);
        } elseif ($user->isStudent()) {
            $query->byCreator($user->id);
        } elseif ($user->isBrand()) {
            $query->whereHas('campaign', function ($q) use ($user): void {
                $q->where('brand_id', $user->id);
            });
        } elseif ($user->isAdmin()) {
        } else {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($request->has('status')) {
            $status = $request->get('status');
            if (in_array($status, ['pending', 'approved', 'rejected'])) {
                $query->where('status', $status);
            }
        }

        if ($request->has('campaign_id')) {
            $query->byCampaign($request->get('campaign_id'));
        }

        $applications = $query->orderBy('created_at', 'desc')->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $applications,
        ]);
    }

    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json(['message' => 'Only creators and students can apply to campaigns'], 403);
        }

        if (!$user->hasPremiumAccess()) {
            return response()->json(['message' => 'Only premium users can send proposals to start a chat with brands.'], 403);
        }

        if (!$campaign->isApproved() || !$campaign->is_active) {
            return response()->json(['message' => 'Campaign is not available for applications'], 400);
        }

        if ($campaign->applications()->where('creator_id', $user->id)->exists()) {
            return response()->json(['message' => 'You have already applied to this campaign'], 400);
        }

        $validator = Validator::make($request->all(), [
            'proposal' => 'required|string|min:10|max:2000',
            'portfolio_links' => 'nullable|array',
            'portfolio_links.*' => 'url',
            'estimated_delivery_days' => 'nullable|integer|min:1|max:365',
            'proposed_budget' => 'nullable|numeric|min:0|max:999999.99',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $application = CampaignApplication::create([
            'campaign_id' => $campaign->id,
            'creator_id' => $user->id,
            'proposal' => $request->proposal,
            'portfolio_links' => $request->portfolio_links,
            'estimated_delivery_days' => $request->estimated_delivery_days,
            'proposed_budget' => $request->proposed_budget,
            'status' => 'pending',
        ]);

        AdminNotificationService::notifyAdminOfNewApplication($application);

        CampaignNotificationService::notifyBrandOfNewApplication($application);

        return response()->json([
            'success' => true,
            'message' => 'Application submitted successfully',
            'data' => $application->load(['campaign', 'creator']),
        ], 201);
    }

    public function show(CampaignApplication $application): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (($user->isCreator() || $user->isStudent()) && $application->creator_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isBrand() && $application->campaign->brand_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $application->load(['campaign', 'creator', 'reviewer']),
        ]);
    }

    public function approve(CampaignApplication $application): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        // 1. Authorization checks
        if (!$user->isBrand() || $application->campaign->brand_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$application->canBeReviewedBy($user)) {
            return response()->json(['message' => 'Application cannot be approved'], 400);
        }

        // 2. Stripe Account Verification - DISABLING for now to allow Chat without Payment
        /*
        if (!$this->brandHasStripeAccount($user)) {
            Log::info('Brand has no Stripe account, redirecting to Stripe Connect setup', [
                'user_id' => $user->id,
                'application_id' => $application->id,
            ]);

            $frontendUrl = config('app.frontend_url', 'http://localhost:5000');
            $redirectUrl = $frontendUrl.'/dashboard/payment-methods?requires_stripe_account=true&application_id='.$application->id.'&campaign_id='.$application->campaign_id;

            return response()->json([
                'success' => false,
                'message' => 'You need to connect your Stripe account before approving proposals.',
                'requires_stripe_account' => true,
                'requires_funding' => true,
                'redirect_url' => $redirectUrl,
            ], 402);
        }
        */

        // 3. Payment Method Verification - DISABLING for now to allow Chat without Payment
        /*
        if (!$this->brandHasPaymentMethod($user)) {
            return $this->handleMissingPaymentMethod($user, $application);
        }
        */

        // 4. Contract Funding Check - DISABLING for now to allow Chat without Payment
        /*
        if ($fundingResponse = $this->handleContractFundingCheck($user, $application)) {
            return $fundingResponse;
        }
        */

        // 5. Execute Approval
        $application->approve($user->id);

        // 6. Post-Approval Tasks (Chat, Notifications)
        $chatRoom = $this->initializeChatForApprovedApplication($application, $user);
        $contract = $this->ensureContractCreatedAfterApproval($application, $chatRoom);
        $this->sendApprovalNotifications($application, $user);

        return response()->json([
            'success' => true,
            'message' => 'Application approved successfully.',
            'data' => $application->load(['campaign', 'creator']),
            'chat_room_id' => $chatRoom?->room_id,
            'contract_id' => $contract?->id,
            'contract_status' => $contract?->status,
            'contract_workflow_status' => $contract?->workflow_status,
        ]);
    }

    public function reject(Request $request, CampaignApplication $application): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if (!$user->isBrand() || $application->campaign->brand_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$application->canBeReviewedBy($user)) {
            return response()->json(['message' => 'Application cannot be rejected'], 400);
        }

        $validator = Validator::make($request->all(), [
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $application->reject($user->id, $request->rejection_reason);

        AdminNotificationService::notifyAdminOfSystemActivity('application_rejected', [
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
            'data' => $application->load(['campaign', 'creator']),
        ]);
    }

    public function withdraw(CampaignApplication $application): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if ((!$user->isCreator() && !$user->isStudent()) || $application->creator_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$application->canBeWithdrawnBy($user)) {
            return response()->json(['message' => 'Application cannot be withdrawn'], 400);
        }

        $application->delete();

        AdminNotificationService::notifyAdminOfSystemActivity('application_withdrawn', [
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
            'message' => 'Application withdrawn successfully',
        ]);
    }

    public function campaignApplications(Campaign $campaign): JsonResponse
    {
        $user = $this->getAuthenticatedUser();

        if ($user->isCreator()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($user->isBrand() && $campaign->brand_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $applications = $campaign->applications()
            ->with(['creator', 'reviewer'])
            ->whereHas('creator')
            ->orderBy('created_at', 'desc')
            ->paginate(15)
        ;

        return response()->json([
            'success' => true,
            'data' => $applications,
        ]);
    }

    public function statistics(): JsonResponse
    {
        $user = $this->getAuthenticatedUser();
        $query = CampaignApplication::query();

        if ($user->isCreator()) {
            $query->byCreator($user->id);
        } elseif ($user->isStudent()) {
            $query->byCreator($user->id);
        } elseif ($user->isBrand()) {
            $query->whereHas('campaign', function ($q) use ($user): void {
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
            'data' => $statistics,
        ]);
    }

    /**
     * Check if the brand has an active Stripe Connect account.
     */
    private function brandHasStripeAccount(User $user): bool
    {
        if (empty($user->stripe_account_id)) {
            return false;
        }

        try {
            Stripe::setApiKey(config('services.stripe.secret'));
            $stripeAccount = Account::retrieve($user->stripe_account_id);

            return $stripeAccount->charges_enabled && $stripeAccount->payouts_enabled;
        } catch (Exception $e) {
            Log::warning('Brand Stripe account retrieval failed', [
                'user_id' => $user->id,
                'stripe_account_id' => $user->stripe_account_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if the brand has a valid payment method configured.
     */
    private function brandHasPaymentMethod(User $user): bool
    {
        if ($user->stripe_customer_id && $user->stripe_payment_method_id) {
            return true;
        }

        return BrandPaymentMethod::where('user_id', $user->id)
            ->where('is_active', true)
            ->exists()
        ;
    }

    /**
     * Handle the case where a brand is missing a payment method.
     */
    private function handleMissingPaymentMethod(User $user, CampaignApplication $application): JsonResponse
    {
        Log::info('Brand has no payment method, creating checkout session for setup', [
            'user_id' => $user->id,
            'application_id' => $application->id,
        ]);

        try {
            $checkoutSession = $this->createStripeSetupSession($user, $application);

            return response()->json([
                'success' => false,
                'message' => 'You need to configure a payment method before approving proposals.',
                'requires_funding' => true,
                'redirect_url' => $checkoutSession->url,
                'checkout_session_id' => $checkoutSession->id,
            ], 402);
        } catch (Exception $e) {
            Log::error('Failed to create Stripe Checkout Session for setup', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Please set up your payment method in the dashboard.',
                'requires_funding' => true,
                'redirect_url' => config('app.frontend_url').'/dashboard/payment-methods',
            ], 402);
        }
    }

    /**
     * Create a Stripe Checkout Session for payment method setup.
     */
    private function createStripeSetupSession(User $user, CampaignApplication $application): StripeSession
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        $customerId = $this->getOrCreateStripeCustomerId($user);

        $params = $this->getSetupSessionParams($user, $application, $customerId);

        return StripeSession::create($params);
    }

    private function getSetupSessionParams(User $user, CampaignApplication $application, string $customerId): array
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:5000');

        return [
            'customer' => $customerId,
            'mode' => 'setup',
            'payment_method_types' => ['card'],
            'locale' => 'pt-BR',
            'success_url' => $frontendUrl.'/dashboard/payment-methods?success=true&session_id={CHECKOUT_SESSION_ID}&application_id='.$application->id.'&campaign_id='.$application->campaign_id,
            'cancel_url' => $frontendUrl.'/dashboard/payment-methods?canceled=true&application_id='.$application->id.'&campaign_id='.$application->campaign_id,
            'metadata' => [
                'user_id' => (string) $user->id,
                'type' => 'payment_method_setup',
                'application_id' => (string) $application->id,
                'campaign_id' => (string) $application->campaign_id,
                'action' => 'approve_application',
            ],
        ];
    }

    /**
     * Check if there are contracts that need funding before approval.
     */
    private function handleContractFundingCheck(User $user, CampaignApplication $application): ?JsonResponse
    {
        $contractsNeedingFunding = Contract::where('brand_id', $user->id)
            ->where(function ($query) use ($application): void {
                $query->whereHas('offer', function ($q) use ($application): void {
                    $q->where('campaign_id', $application->campaign_id)
                        ->where('creator_id', $application->creator_id)
                    ;
                })
                    ->orWhere(function ($q) use ($application): void {
                        $q->where('creator_id', $application->creator_id)
                            ->whereNull('offer_id')
                        ;
                    })
                ;
            })
            ->get()
            ->filter(fn ($contract) => $contract->needsFunding())
        ;

        if ($contractsNeedingFunding->isEmpty()) {
            return null;
        }

        $contractToFund = $contractsNeedingFunding->first();

        try {
            $checkoutSession = $this->createStripeFundingSession($user, $application, $contractToFund);

            return response()->json([
                'success' => false,
                'message' => 'Contract needs to be funded before approval can proceed.',
                'requires_funding' => true,
                'redirect_url' => $checkoutSession->url,
                'checkout_session_id' => $checkoutSession->id,
                'contract_id' => $contractToFund->id,
            ], 402);
        } catch (Exception $e) {
            Log::error('Failed to create contract funding checkout session', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return null; // Proceed if funding session creation fails but keep logs
        }
    }

    /**
     * Create a Stripe Checkout Session for contract funding.
     */
    private function createStripeFundingSession(User $user, CampaignApplication $application, Contract $contract): StripeSession
    {
        Stripe::setApiKey(config('services.stripe.secret'));
        $customerId = $this->getOrCreateStripeCustomerId($user);

        $params = $this->getFundingSessionParams($user, $application, $contract, $customerId);

        return StripeSession::create($params);
    }

    private function getFundingSessionParams(User $user, CampaignApplication $application, Contract $contract, string $customerId): array
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:5000');

        return [
            'customer' => $customerId,
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'locale' => 'pt-BR',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'brl',
                    'product_data' => [
                        'name' => 'Contract Funding: '.$contract->title,
                        'description' => 'Escrow deposit for contract #'.$contract->id,
                    ],
                    'unit_amount' => (int) round($contract->budget * 100),
                ],
                'quantity' => 1,
            ]],
            'success_url' => "$frontendUrl/dashboard/payment-methods?funding_success=true&session_id={CHECKOUT_SESSION_ID}&contract_id={$contract->id}&application_id={$application->id}&campaign_id={$application->campaign_id}",
            'cancel_url' => "$frontendUrl/dashboard/payment-methods?funding_canceled=true&contract_id={$contract->id}&application_id={$application->id}&campaign_id={$application->campaign_id}",
            'metadata' => [
                'user_id' => (string) $user->id,
                'type' => 'contract_funding',
                'contract_id' => (string) $contract->id,
                'application_id' => (string) $application->id,
                'campaign_id' => (string) $application->campaign_id,
                'action' => 'approve_application_funding',
            ],
        ];
    }

    /**
     * Get or create a Stripe Customer ID for the user.
     */
    private function getOrCreateStripeCustomerId(User $user): string
    {
        if ($user->stripe_customer_id) {
            try {
                Customer::retrieve($user->stripe_customer_id);

                return $user->stripe_customer_id;
            } catch (Exception $e) {
                Log::warning('Stored Stripe customer ID invalid, creating new one', ['user_id' => $user->id]);
            }
        }

        $customer = Customer::create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => $user->id, 'role' => 'brand'],
        ]);

        $user->update(['stripe_customer_id' => $customer->id]);

        return $customer->id;
    }

    /**
     * Initialize chat room and first contact for approved application.
     */
    private function initializeChatForApprovedApplication(CampaignApplication $application, User $brand): ?ChatRoom
    {
        try {
            $chatRoom = ChatRoom::findOrCreateRoom(
                $application->campaign_id,
                $brand->id,
                $application->creator_id
            );

            // Always move the application workflow forward after approval.
            // A room may already exist, and in that case we still need to advance and ensure the first offer exists.
            if ($application->isFirstContactPending()) {
                $application->initiateFirstContact();
            }

            (new ChatController())->sendInitialOfferIfNeeded($chatRoom);

            return $chatRoom;
        } catch (Exception $e) {
            Log::error('Failed to initialize chat for approved application', [
                'application_id' => $application->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Ensure contract is created immediately after proposal approval.
     * This aligns the application flow with "approve proposal -> contract pending payment".
     */
    private function ensureContractCreatedAfterApproval(CampaignApplication $application, ?ChatRoom $chatRoom): ?Contract
    {
        if (!$chatRoom) {
            return null;
        }

        $existingContract = Contract::whereHas('offer', function ($query) use ($chatRoom): void {
            $query->where('chat_room_id', $chatRoom->id);
        })
            ->where('brand_id', $chatRoom->brand_id)
            ->where('creator_id', $chatRoom->creator_id)
            ->orderByDesc('id')
            ->first()
        ;

        if ($existingContract) {
            return $existingContract;
        }

        $campaign = $application->campaign;

        $budget = (float) ($application->proposed_budget ?? $campaign->budget ?? 0);
        $campaignDeadline = $campaign->deadline ?? null;
        $defaultEstimatedDays = $campaignDeadline ? now()->diffInDays($campaignDeadline, false) : 30;
        $estimatedDays = (int) ($application->estimated_delivery_days ?? $defaultEstimatedDays);
        if ($estimatedDays <= 0) {
            $estimatedDays = 30;
        }

        $offer = Offer::where('chat_room_id', $chatRoom->id)
            ->where('campaign_id', $application->campaign_id)
            ->where('brand_id', $chatRoom->brand_id)
            ->where('creator_id', $chatRoom->creator_id)
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->first()
        ;

        if (!$offer) {
            $offer = Offer::create([
                'brand_id' => $chatRoom->brand_id,
                'creator_id' => $chatRoom->creator_id,
                'campaign_id' => $application->campaign_id,
                'chat_room_id' => $chatRoom->id,
                'title' => 'Proposta aprovada',
                'description' => (string) ($application->proposal ?: 'Proposta aprovada automaticamente pela marca.'),
                'budget' => $budget,
                'estimated_days' => $estimatedDays,
                'requirements' => $campaign->requirements ?? [],
                'expires_at' => now()->addDays(7),
            ]);
        } else {
            $offer->update([
                'title' => 'Proposta aprovada',
                'description' => (string) ($application->proposal ?: $offer->description),
                'budget' => $budget,
                'estimated_days' => $estimatedDays,
                'requirements' => $campaign->requirements ?? $offer->requirements ?? [],
                'expires_at' => now()->addDays(7),
            ]);
        }

        if (!$offer->accept()) {
            Log::warning('Failed to auto-accept offer after application approval', [
                'application_id' => $application->id,
                'offer_id' => $offer->id,
            ]);

            return null;
        }

        if ($application->isAgreementInProgress()) {
            $application->finalizeAgreement();
        }

        return Contract::where('offer_id', $offer->id)->first();
    }

    /**
     * Send notifications after application approval.
     */
    private function sendApprovalNotifications(CampaignApplication $application, User $brand): void
    {
        CampaignNotificationService::notifyCreatorOfProposalApproval($application);

        AdminNotificationService::notifyAdminOfSystemActivity('application_approved', [
            'application_id' => $application->id,
            'campaign_id' => $application->campaign_id,
            'campaign_title' => $application->campaign->title,
            'creator_name' => $application->creator->name,
            'brand_name' => $application->campaign->brand->name,
            'proposal_amount' => $application->proposed_budget,
            'approved_by' => $brand->name,
        ]);
    }
}
