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
use Stripe\Account;

class CampaignApplicationController extends Controller
{
    
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();
        $query = CampaignApplication::with(['campaign', 'creator', 'reviewer'])
            ->whereHas('creator'); 

        if ($user->isCreator()) {
            
            $query->byCreator($user->id);
        } elseif ($user->isStudent()) {
            
            $query->byCreator($user->id);
        } elseif ($user->isBrand()) {
            
            $query->whereHas('campaign', function ($q) use ($user) {
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
            'data' => $applications
        ]);
    }

    
    public function store(Request $request, Campaign $campaign): JsonResponse
    {
        $user = Auth::user();

        
        if (!$user->isCreator() && !$user->isStudent()) {
            return response()->json(['message' => 'Only creators and students can apply to campaigns'], 403);
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

        
        \App\Services\NotificationService::notifyAdminOfNewApplication($application);

        
        \App\Services\NotificationService::notifyBrandOfNewApplication($application);

        return response()->json([
            'success' => true,
            'message' => 'Application submitted successfully',
            'data' => $application->load(['campaign', 'creator'])
        ], 201);
    }

    
    public function show(CampaignApplication $application): JsonResponse
    {
        $user = Auth::user();

        
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

    
    public function approve(CampaignApplication $application): JsonResponse
    {
        $user = Auth::user();

        
        if (!$user->isBrand() || $application->campaign->brand_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$application->canBeReviewedBy($user)) {
            return response()->json(['message' => 'Application cannot be approved'], 400);
        }

        
        
        $hasStripeAccount = false;
        $stripeAccountStatus = null;
        
        if (!empty($user->stripe_account_id)) {
            try {
                
                Stripe::setApiKey(config('services.stripe.secret'));
                
                
                $stripeAccount = Account::retrieve($user->stripe_account_id);
                
                
                $isAccountActive = $stripeAccount->charges_enabled && $stripeAccount->payouts_enabled;
                
                $hasStripeAccount = true;
                $stripeAccountStatus = [
                    'account_id' => $stripeAccount->id,
                    'charges_enabled' => $stripeAccount->charges_enabled ?? false,
                    'payouts_enabled' => $stripeAccount->payouts_enabled ?? false,
                    'details_submitted' => $stripeAccount->details_submitted ?? false,
                    'is_active' => $isAccountActive,
                ];
                
                Log::info('Brand has Stripe account', [
                    'user_id' => $user->id,
                    'stripe_account_id' => $user->stripe_account_id,
                    'account_status' => $stripeAccountStatus,
                ]);
            } catch (\Exception $e) {
                Log::warning('Brand Stripe account not found or invalid', [
                    'user_id' => $user->id,
                    'stripe_account_id' => $user->stripe_account_id,
                    'error' => $e->getMessage(),
                ]);
                $hasStripeAccount = false;
            }
        } else {
            Log::info('Brand has no Stripe account ID', [
                'user_id' => $user->id,
            ]);
        }

        
        
        $hasPaymentMethod = false;
        
        
        if ($user->stripe_customer_id && $user->stripe_payment_method_id) {
            $hasPaymentMethod = true;
            Log::info('Brand has direct Stripe payment method', [
                'user_id' => $user->id,
                'stripe_customer_id' => $user->stripe_customer_id,
                'stripe_payment_method_id' => $user->stripe_payment_method_id,
            ]);
        }
        
        
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

        
        if (!$hasStripeAccount) {
            Log::info('Brand has no Stripe account, redirecting to Stripe Connect setup', [
                'user_id' => $user->id,
                'application_id' => $application->id,
                'campaign_id' => $application->campaign_id,
            ]);
            
            
            $frontendUrl = config('app.frontend_url', 'http://localhost:5000');
            $redirectUrl = $frontendUrl . '/brand?component=Pagamentos&requires_stripe_account=true&application_id=' . $application->id . '&campaign_id=' . $application->campaign_id;
            
            return response()->json([
                'success' => false,
                'message' => 'You need to connect your Stripe account before approving proposals. Please set up your Stripe account and try again.',
                'requires_stripe_account' => true,
                'requires_funding' => true, 
                'redirect_url' => $redirectUrl,
            ], 402); 
        }

        
        if (!$hasPaymentMethod) {
            Log::info('Brand has no payment method, creating checkout session for setup', [
                'user_id' => $user->id,
                'application_id' => $application->id,
                'campaign_id' => $application->campaign_id,
                'stripe_account_id' => $user->stripe_account_id,
            ]);
            try {
                
                Stripe::setApiKey(config('services.stripe.secret'));

                
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

                
                $frontendUrl = config('app.frontend_url', 'http://localhost:5000');

                
                
                $checkoutSession = Session::create([
                    'customer' => $customerId,
                    'mode' => 'setup', 
                    'payment_method_types' => ['card'],
                    'locale' => 'pt-BR',
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
                ], 402); 

            } catch (\Exception $e) {
                Log::error('Failed to create Stripe Checkout Session for application approval', [
                    'user_id' => $user->id,
                    'application_id' => $application->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                
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

        
        
        
        if ($hasPaymentMethod) {
            
            
            $contractsNeedingFunding = \App\Models\Contract::where('brand_id', $user->id)
                ->where(function ($query) use ($application) {
                    
                    $query->whereHas('offer', function ($q) use ($application) {
                        $q->where('campaign_id', $application->campaign_id)
                          ->where('creator_id', $application->creator_id);
                    })
                    
                    ->orWhere(function ($q) use ($application) {
                        $q->where('creator_id', $application->creator_id)
                          ->whereNull('offer_id'); 
                    });
                })
                ->get()
                ->filter(function ($contract) {
                    
                    return $contract->needsFunding();
                });

            
            
            if ($contractsNeedingFunding->isNotEmpty()) {
                $contractToFund = $contractsNeedingFunding->first();
                
                Log::info('Brand has payment method but contract needs funding - checking brand funds', [
                    'user_id' => $user->id,
                    'application_id' => $application->id,
                    'contract_id' => $contractToFund->id,
                    'contract_budget' => $contractToFund->budget,
                    'contract_status' => $contractToFund->status,
                    'workflow_status' => $contractToFund->workflow_status,
                    'has_payment' => $contractToFund->payment ? 'yes' : 'no',
                    'payment_status' => $contractToFund->payment ? $contractToFund->payment->status : 'none',
                    'is_funded' => $contractToFund->isFunded(),
                    'needs_funding' => $contractToFund->needsFunding(),
                ]);

                try {
                    
                    Stripe::setApiKey(config('services.stripe.secret'));

                    
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

                    
                    $frontendUrl = config('app.frontend_url', 'http://localhost:5000');

                    
                    $checkoutSession = Session::create([
                        'customer' => $customerId,
                        'mode' => 'payment', 
                        'payment_method_types' => ['card'],
                        'locale' => 'pt-BR',
                        'line_items' => [[
                            'price_data' => [
                                'currency' => 'brl',
                                'product_data' => [
                                    'name' => 'Contract Funding: ' . $contractToFund->title,
                                    'description' => 'Escrow deposit for contract #' . $contractToFund->id,
                                ],
                                'unit_amount' => (int) round($contractToFund->budget * 100), 
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
                    ], 402); 

                } catch (\Exception $e) {
                    Log::error('Failed to create contract funding checkout session during application approval', [
                        'user_id' => $user->id,
                        'application_id' => $application->id,
                        'contract_id' => $contractToFund->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    
                    
                    Log::warning('Proceeding with approval despite funding checkout failure', [
                        'user_id' => $user->id,
                        'application_id' => $application->id,
                    ]);
                }
            }
        }

        $application->approve($user->id);

        
        try {
            $chatRoom = \App\Models\ChatRoom::findOrCreateRoom(
                $application->campaign_id,
                $user->id, 
                $application->creator_id
            );

            
            if ($chatRoom->wasRecentlyCreated) {
                $application->initiateFirstContact();
                
                \Log::info('Application workflow status updated to agreement_in_progress', [
                    'application_id' => $application->id,
                    'campaign_id' => $application->campaign_id,
                    'creator_id' => $application->creator_id,
                    'workflow_status' => $application->workflow_status,
                ]);
                
                
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

        
        \App\Services\NotificationService::notifyCreatorOfProposalApproval($application);

        
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

    
    public function reject(Request $request, CampaignApplication $application): JsonResponse
    {
        $user = Auth::user();

        
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

    
    public function withdraw(CampaignApplication $application): JsonResponse
    {
        $user = Auth::user();

        
        if ((!$user->isCreator() && !$user->isStudent()) || $application->creator_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (!$application->canBeWithdrawnBy($user)) {
            return response()->json(['message' => 'Application cannot be withdrawn'], 400);
        }

        $application->delete();

        
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

    
    public function campaignApplications(Campaign $campaign): JsonResponse
    {
        $user = Auth::user();

        
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
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $applications
        ]);
    }

    
    public function statistics(): JsonResponse
    {
        $user = Auth::user();
        $query = CampaignApplication::query();

        if ($user->isCreator()) {
            $query->byCreator($user->id);
        } elseif ($user->isStudent()) {
            
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