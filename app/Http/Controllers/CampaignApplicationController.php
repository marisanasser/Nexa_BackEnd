<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\CampaignApplication;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Checkout\Session;
use Stripe\Customer;

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

        // Check if brand has payment methods configured
        // This is required before approving applications to ensure payment capability
        $hasPaymentMethod = false;
        
        // Check for Stripe payment method (direct Stripe integration on user model)
        if ($user->stripe_customer_id && $user->stripe_payment_method_id) {
            $hasPaymentMethod = true;
            Log::info('Brand has direct Stripe payment method', [
                'user_id' => $user->id,
                'stripe_customer_id' => $user->stripe_customer_id,
                'stripe_payment_method_id' => $user->stripe_payment_method_id,
            ]);
        }
        
        // Check for BrandPaymentMethod records (active payment methods)
        if (!$hasPaymentMethod) {
            $activePaymentMethods = \App\Models\BrandPaymentMethod::where('user_id', $user->id)
                ->where('is_active', true)
                ->count();
            
            if ($activePaymentMethods > 0) {
                $hasPaymentMethod = true;
                Log::info('Brand has active payment methods in BrandPaymentMethod table', [
                    'user_id' => $user->id,
                    'active_methods_count' => $activePaymentMethods,
                ]);
            }
        }

        // If no payment method exists, create Stripe checkout session for payment method setup
        if (!$hasPaymentMethod) {
            Log::info('Brand has no payment method, creating checkout session for setup', [
                'user_id' => $user->id,
                'application_id' => $application->id,
                'campaign_id' => $application->campaign_id,
            ]);
            try {
                // Set Stripe API key
                Stripe::setApiKey(config('services.stripe.secret'));

                // Ensure Stripe customer exists for the brand
                $customerId = $user->stripe_customer_id;
                
                if (!$customerId) {
                    Log::info('Creating new Stripe customer for brand payment setup', [
                        'user_id' => $user->id,
                        'email' => $user->email,
                    ]);

                    $customer = Customer::create([
                        'email' => $user->email,
                        'name' => $user->name,
                        'metadata' => [
                            'user_id' => $user->id,
                            'role' => 'brand',
                        ],
                    ]);

                    $customerId = $customer->id;
                    $user->update(['stripe_customer_id' => $customerId]);

                    Log::info('Stripe customer created for brand', [
                        'user_id' => $user->id,
                        'customer_id' => $customerId,
                    ]);
                } else {
                    // Verify customer exists
                    try {
                        Customer::retrieve($customerId);
                    } catch (\Exception $e) {
                        Log::warning('Stripe customer not found, creating new one', [
                            'user_id' => $user->id,
                            'old_customer_id' => $customerId,
                        ]);

                        $customer = Customer::create([
                            'email' => $user->email,
                            'name' => $user->name,
                            'metadata' => [
                                'user_id' => $user->id,
                                'role' => 'brand',
                            ],
                        ]);

                        $customerId = $customer->id;
                        $user->update(['stripe_customer_id' => $customerId]);
                    }
                }

                // Get frontend URL from config
                $frontendUrl = config('app.frontend_url', 'http://localhost:5000');

                // Create Checkout Session in setup mode for payment method configuration
                // This allows the brand to add a payment method without making an immediate charge
                $checkoutSession = Session::create([
                    'customer' => $customerId,
                    'mode' => 'setup', // Setup mode for payment method collection only
                    'payment_method_types' => ['card'],
                    'success_url' => $frontendUrl . '/brand?component=Pagamentos&success=true&session_id={CHECKOUT_SESSION_ID}&application_id=' . $application->id . '&campaign_id=' . $application->campaign_id,
                    'cancel_url' => $frontendUrl . '/brand?component=Pagamentos&canceled=true&application_id=' . $application->id . '&campaign_id=' . $application->campaign_id,
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'type' => 'payment_method_setup',
                        'application_id' => (string) $application->id,
                        'campaign_id' => (string) $application->campaign_id,
                        'action' => 'approve_application',
                    ],
                ]);
                
                Log::info('Checkout session created for application approval funding', [
                    'session_id' => $checkoutSession->id,
                    'user_id' => $user->id,
                    'customer_id' => $customerId,
                    'application_id' => $application->id,
                    'metadata' => $checkoutSession->metadata ?? null,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'You need to configure a payment method before approving proposals.',
                    'requires_funding' => true,
                    'redirect_url' => $checkoutSession->url,
                    'checkout_session_id' => $checkoutSession->id,
                ], 402); // 402 Payment Required

            } catch (\Exception $e) {
                Log::error('Failed to create Stripe Checkout Session for application approval', [
                    'user_id' => $user->id,
                    'application_id' => $application->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                // Fallback to frontend redirect if Stripe fails
                $frontendUrl = config('app.frontend_url', 'http://localhost:5000');
                $redirectUrl = $frontendUrl . '/brand?component=Pagamentos';
                
                return response()->json([
                    'success' => false,
                    'message' => 'You need to configure a payment method before approving proposals. Please set up your payment method and try again.',
                    'requires_funding' => true,
                    'redirect_url' => $redirectUrl,
                ], 402);
            }
        }

        // If payment method exists, check if there are contracts that need funding
        // Check for contracts related to this campaign/creator that are pending payment
        if ($hasPaymentMethod) {
            $contractsNeedingFunding = \App\Models\Contract::where('brand_id', $user->id)
                ->where('status', 'pending')
                ->where('workflow_status', 'payment_pending')
                ->where(function ($query) use ($application) {
                    // Check for contracts related to this campaign via offers
                    $query->whereHas('offer', function ($q) use ($application) {
                        $q->where('campaign_id', $application->campaign_id)
                          ->where('creator_id', $application->creator_id);
                    })
                    // Or check for contracts with this creator (might be created directly)
                    ->orWhere(function ($q) use ($application) {
                        $q->where('creator_id', $application->creator_id)
                          ->whereNull('offer_id'); // Contracts without offers
                    });
                })
                ->where(function ($query) {
                    // Check if payment doesn't exist or is not completed
                    $query->whereDoesntHave('payment')
                          ->orWhereHas('payment', function ($q) {
                              $q->where('status', '!=', 'completed');
                          });
                })
                ->get();

            // If there are contracts needing funding, create checkout session for the first one
            if ($contractsNeedingFunding->isNotEmpty()) {
                $contractToFund = $contractsNeedingFunding->first();
                
                Log::info('Brand has payment method but contract needs funding, creating checkout session', [
                    'user_id' => $user->id,
                    'application_id' => $application->id,
                    'contract_id' => $contractToFund->id,
                    'contract_budget' => $contractToFund->budget,
                ]);

                try {
                    // Set Stripe API key
                    Stripe::setApiKey(config('services.stripe.secret'));

                    // Ensure Stripe customer exists
                    $customerId = $user->stripe_customer_id;
                    
                    if (!$customerId) {
                        $customer = Customer::create([
                            'email' => $user->email,
                            'name' => $user->name,
                            'metadata' => [
                                'user_id' => $user->id,
                                'role' => 'brand',
                            ],
                        ]);
                        $customerId = $customer->id;
                        $user->update(['stripe_customer_id' => $customerId]);
                    } else {
                        // Verify customer exists
                        try {
                            Customer::retrieve($customerId);
                        } catch (\Exception $e) {
                            $customer = Customer::create([
                                'email' => $user->email,
                                'name' => $user->name,
                                'metadata' => [
                                    'user_id' => $user->id,
                                    'role' => 'brand',
                                ],
                            ]);
                            $customerId = $customer->id;
                            $user->update(['stripe_customer_id' => $customerId]);
                        }
                    }

                    // Get frontend URL from config
                    $frontendUrl = config('app.frontend_url', 'http://localhost:5000');

                    // Create Checkout Session in payment mode for contract funding
                    $checkoutSession = Session::create([
                        'customer' => $customerId,
                        'mode' => 'payment', // Payment mode to charge the brand
                        'payment_method_types' => ['card'],
                        'line_items' => [[
                            'price_data' => [
                                'currency' => 'brl',
                                'product_data' => [
                                    'name' => 'Contract Funding: ' . $contractToFund->title,
                                    'description' => 'Escrow deposit for contract #' . $contractToFund->id,
                                ],
                                'unit_amount' => (int) round($contractToFund->budget * 100), // Convert to cents
                            ],
                            'quantity' => 1,
                        ]],
                        'success_url' => $frontendUrl . '/brand?component=Pagamentos&funding_success=true&session_id={CHECKOUT_SESSION_ID}&contract_id=' . $contractToFund->id . '&application_id=' . $application->id . '&campaign_id=' . $application->campaign_id,
                        'cancel_url' => $frontendUrl . '/brand?component=Pagamentos&funding_canceled=true&contract_id=' . $contractToFund->id . '&application_id=' . $application->id . '&campaign_id=' . $application->campaign_id,
                        'metadata' => [
                            'user_id' => (string) $user->id,
                            'type' => 'contract_funding',
                            'contract_id' => (string) $contractToFund->id,
                            'application_id' => (string) $application->id,
                            'campaign_id' => (string) $application->campaign_id,
                            'action' => 'approve_application_funding',
                        ],
                    ]);

                    Log::info('Contract funding checkout session created during application approval', [
                        'session_id' => $checkoutSession->id,
                        'user_id' => $user->id,
                        'contract_id' => $contractToFund->id,
                        'application_id' => $application->id,
                        'amount' => $contractToFund->budget,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Contract needs to be funded before approval can proceed. Please complete the payment.',
                        'requires_funding' => true,
                        'redirect_url' => $checkoutSession->url,
                        'checkout_session_id' => $checkoutSession->id,
                        'contract_id' => $contractToFund->id,
                    ], 402); // 402 Payment Required

                } catch (\Exception $e) {
                    Log::error('Failed to create contract funding checkout session during application approval', [
                        'user_id' => $user->id,
                        'application_id' => $application->id,
                        'contract_id' => $contractToFund->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    // Continue with approval even if funding checkout creation fails
                    // The brand can fund the contract later
                    Log::warning('Proceeding with approval despite funding checkout failure', [
                        'user_id' => $user->id,
                        'application_id' => $application->id,
                    ]);
                }
            }
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