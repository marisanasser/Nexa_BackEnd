<?php

namespace App\Http\Controllers;

use App\Events\OfferAccepted;
use App\Events\OfferCancelled;
use App\Events\OfferCreated;
use App\Events\OfferRejected;
use App\Models\CampaignApplication;
use App\Models\ChatRoom;
use App\Models\Message;
use App\Models\Offer;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Stripe\Account;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Stripe;

class OfferController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'creator_id' => 'required|integer|exists:users,id',
            'chat_room_id' => 'required|string',
            'budget' => 'required|numeric|min:10|max:100000',
            'estimated_days' => 'required|integer|min:1|max:365',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can create offers',
            ], 403);
        }

        $hasStripeAccount = false;
        $stripeAccountStatus = null;

        if (! empty($user->stripe_account_id)) {
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

                Log::info('Brand has Stripe account when sending offer', [
                    'user_id' => $user->id,
                    'stripe_account_id' => $user->stripe_account_id,
                    'account_status' => $stripeAccountStatus,
                ]);
            } catch (\Exception $e) {
                Log::warning('Brand Stripe account not found or invalid when sending offer', [
                    'user_id' => $user->id,
                    'stripe_account_id' => $user->stripe_account_id,
                    'error' => $e->getMessage(),
                ]);
                $hasStripeAccount = false;
            }
        } else {
            Log::info('Brand has no Stripe account ID when sending offer', [
                'user_id' => $user->id,
            ]);
        }

        $hasPaymentMethod = false;

        if ($user->stripe_customer_id && $user->stripe_payment_method_id) {
            $hasPaymentMethod = true;
            Log::info('Brand has direct Stripe payment method when sending offer', [
                'user_id' => $user->id,
                'stripe_customer_id' => $user->stripe_customer_id,
                'stripe_payment_method_id' => $user->stripe_payment_method_id,
            ]);
        }

        if (! $hasPaymentMethod) {
            $activePaymentMethods = \App\Models\BrandPaymentMethod::where('user_id', $user->id)
                ->where('is_active', true)
                ->count();

            if ($activePaymentMethods > 0) {
                $hasPaymentMethod = true;
                Log::info('Brand has active payment methods when sending offer', [
                    'user_id' => $user->id,
                    'active_methods_count' => $activePaymentMethods,
                ]);
            }
        }

        if (! $hasStripeAccount) {
            Log::info('Brand has no Stripe account when sending offer, redirecting to Stripe Connect setup', [
                'user_id' => $user->id,
                'creator_id' => $request->creator_id,
                'chat_room_id' => $request->chat_room_id,
            ]);

            $frontendUrl = config('app.frontend_url', 'http://localhost:5000');
            $redirectUrl = $frontendUrl.'/brand?component=Pagamentos&requires_stripe_account=true&action=send_offer&creator_id='.$request->creator_id.'&chat_room_id='.$request->chat_room_id;

            return response()->json([
                'success' => false,
                'message' => 'You need to connect your Stripe account before sending offers. Please set up your Stripe account and try again.',
                'requires_stripe_account' => true,
                'requires_funding' => true,
                'redirect_url' => $redirectUrl,
            ], 402);
        }

        if (! $hasPaymentMethod) {
            Log::info('Brand has no payment method when sending offer, creating checkout session for setup', [
                'user_id' => $user->id,
                'creator_id' => $request->creator_id,
                'chat_room_id' => $request->chat_room_id,
                'stripe_account_id' => $user->stripe_account_id,
            ]);

            try {
                Stripe::setApiKey(config('services.stripe.secret'));

                $customerId = $user->stripe_customer_id;

                if (! $customerId) {
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
                    'mode' => 'setup',
                    'payment_method_types' => ['card'],
                    'locale' => 'pt-BR',
                    'success_url' => $frontendUrl.'/brand?component=Pagamentos&success=true&session_id={CHECKOUT_SESSION_ID}&action=send_offer&creator_id='.$request->creator_id.'&chat_room_id='.$request->chat_room_id,
                    'cancel_url' => $frontendUrl.'/brand?component=Pagamentos&canceled=true&action=send_offer&creator_id='.$request->creator_id.'&chat_room_id='.$request->chat_room_id,
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'type' => 'payment_method_setup',
                        'action' => 'send_offer',
                        'creator_id' => (string) $request->creator_id,
                        'chat_room_id' => $request->chat_room_id,
                    ],
                ]);

                Log::info('Checkout session created for offer funding', [
                    'session_id' => $checkoutSession->id,
                    'user_id' => $user->id,
                    'customer_id' => $customerId,
                    'creator_id' => $request->creator_id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'You need to configure a payment method before sending offers.',
                    'requires_funding' => true,
                    'redirect_url' => $checkoutSession->url,
                    'checkout_session_id' => $checkoutSession->id,
                ], 402);
            } catch (\Exception $e) {
                Log::error('Failed to create Stripe Checkout Session for offer funding', [
                    'user_id' => $user->id,
                    'creator_id' => $request->creator_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $frontendUrl = config('app.frontend_url', 'http://localhost:5000');
                $redirectUrl = $frontendUrl.'/brand?component=Pagamentos&requires_funding=true&action=send_offer';

                return response()->json([
                    'success' => false,
                    'message' => 'You need to configure a payment method before sending offers. Please set up your payment method and try again.',
                    'requires_funding' => true,
                    'redirect_url' => $redirectUrl,
                ], 402);
            }
        }

        $creator = User::find($request->creator_id);
        if (! $creator || (! $creator->isCreator() && ! $creator->isStudent())) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid creator or student',
            ], 404);
        }

        $chatRoom = ChatRoom::where('room_id', $request->chat_room_id)
            ->where('brand_id', $user->id)
            ->where('creator_id', $creator->id)
            ->first();

        if (! $chatRoom) {
            return response()->json([
                'success' => false,
                'message' => 'Chat room not found or access denied',
            ], 404);
        }

        $existingOffer = Offer::where('brand_id', $user->id)
            ->where('creator_id', $creator->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if ($existingOffer) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending offer for this creator. Please wait for them to respond or cancel the existing offer.',
                'existing_offer_id' => $existingOffer->id,
            ], 400);
        }

        try {
            $offer = Offer::create([
                'brand_id' => $user->id,
                'creator_id' => $creator->id,
                'chat_room_id' => $chatRoom->id,
                'title' => 'Oferta de Projeto',
                'description' => 'Oferta enviada via chat',
                'budget' => $request->budget,
                'estimated_days' => $request->estimated_days,
                'requirements' => [],
                'expires_at' => now()->addDays(1),
            ]);

            NotificationService::notifyUserOfNewOffer($offer);

            $this->createOfferChatMessage($chatRoom, 'offer_created', [
                'sender_id' => $user->id,
                'message' => "Oferta enviada: {$offer->formatted_budget}",
                'offer_data' => [
                    'offer_id' => $offer->id,
                    'title' => $offer->title,
                    'description' => $offer->description,
                    'budget' => $offer->budget,
                    'formatted_budget' => $offer->formatted_budget,
                    'estimated_days' => $offer->estimated_days,
                    'status' => 'pending',
                    'expires_at' => $offer->expires_at->format('Y-m-d H:i:s'),
                    'days_until_expiry' => $offer->days_until_expiry,
                    'sender' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'avatar_url' => $user->avatar_url,
                    ],
                ],
            ]);

            event(new OfferCreated($offer, $chatRoom, $user->id));

            Log::info('Offer created successfully', [
                'offer_id' => $offer->id,
                'brand_id' => $user->id,
                'creator_id' => $creator->id,
                'budget' => $request->budget,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Offer sent successfully',
                'data' => [
                    'id' => $offer->id,
                    'title' => $offer->title,
                    'budget' => $offer->formatted_budget,
                    'estimated_days' => $offer->estimated_days,
                    'expires_at' => $offer->expires_at->format('Y-m-d H:i:s'),
                    'days_until_expiry' => $offer->days_until_expiry,
                ],
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating offer', [
                'user_id' => $user->id,
                'creator_id' => $creator->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create offer. Please try again.',
            ], 500);
        }
    }

    private function createOfferChatMessage(ChatRoom $chatRoom, string $messageType, array $data = []): void
    {
        try {
            $messageData = [
                'chat_room_id' => $chatRoom->id,
                'sender_id' => $data['sender_id'] ?? null,
                'message' => $data['message'] ?? '',
                'message_type' => 'offer',
                'offer_data' => json_encode($data['offer_data'] ?? []),
            ];

            Message::create($messageData);
        } catch (\Exception $e) {
            Log::error('Failed to create offer chat message', [
                'chat_room_id' => $chatRoom->id,
                'message_type' => $messageType,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // The emitSocketEvent method is no longer used and can be removed
    // private function emitSocketEvent(string $event, array $data): void
    // {
    //     try {
    //         if (isset($GLOBALS['socket_server'])) {
    //             $io = $GLOBALS['socket_server'];
    //             $io->emit($event, $data);
    //         }
    //     } catch (\Exception $e) {
    //         Log::error('Failed to emit socket event', [
    //             'event' => $event,
    //             'error' => $e->getMessage(),
    //         ]);
    //     }
    // }

    public function index(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $type = $request->get('type', 'received');
        $status = $request->get('status');

        try {
            $query = $user->isBrand()
                ? $user->sentOffers()
                : $user->receivedOffers();

            if ($status) {
                $query->where('status', $status);
            }

            $offers = $query->with(['brand:id,name,avatar_url', 'creator:id,name,avatar_url'])
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            $offers->getCollection()->transform(function ($offer) use ($user) {
                $otherUser = $user->isBrand() ? $offer->creator : $offer->brand;

                return [
                    'id' => $offer->id,
                    'title' => $offer->title,
                    'description' => $offer->description,
                    'budget' => $offer->formatted_budget,
                    'estimated_days' => $offer->estimated_days,
                    'requirements' => $offer->requirements,
                    'status' => $offer->status,
                    'expires_at' => $offer->expires_at->format('Y-m-d H:i:s'),
                    'days_until_expiry' => $offer->days_until_expiry,
                    'is_expiring_soon' => $offer->is_expiring_soon,
                    'accepted_at' => $offer->accepted_at?->format('Y-m-d H:i:s'),
                    'rejected_at' => $offer->rejected_at?->format('Y-m-d H:i:s'),
                    'rejection_reason' => $offer->rejection_reason,
                    'other_user' => [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'avatar_url' => $otherUser->avatar_url,
                    ],
                    'created_at' => $offer->created_at->format('Y-m-d H:i:s'),
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $offers,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching offers', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch offers',
            ], 500);
        }
    }

    public function show(int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        try {
            $offer = Offer::with(['brand:id,name,avatar_url', 'creator:id,name,avatar_url'])
                ->where(function ($query) use ($user) {
                    $query->where('brand_id', $user->id)
                        ->orWhere('creator_id', $user->id);
                })
                ->find($id);

            if (! $offer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Offer not found',
                ], 404);
            }

            $otherUser = $user->isBrand() ? $offer->creator : $offer->brand;

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $offer->id,
                    'title' => $offer->title,
                    'description' => $offer->description,
                    'budget' => $offer->formatted_budget,
                    'estimated_days' => $offer->estimated_days,
                    'requirements' => $offer->requirements,
                    'status' => $offer->status,
                    'expires_at' => $offer->expires_at->format('Y-m-d H:i:s'),
                    'days_until_expiry' => $offer->days_until_expiry,
                    'is_expiring_soon' => $offer->is_expiring_soon,
                    'can_be_accepted' => $offer->canBeAccepted(),
                    'accepted_at' => $offer->accepted_at?->format('Y-m-d H:i:s'),
                    'rejected_at' => $offer->rejected_at?->format('Y-m-d H:i:s'),
                    'rejection_reason' => $offer->rejection_reason,
                    'other_user' => [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'avatar_url' => $otherUser->avatar_url,
                    ],
                    'created_at' => $offer->created_at->format('Y-m-d H:i:s'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching offer', [
                'user_id' => $user->id,
                'offer_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch offer',
            ], 500);
        }
    }

    public function accept(int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isCreator() && ! $user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can accept offers',
            ], 403);
        }

        try {
            Log::info('Attempting to accept offer', [
                'user_id' => $user->id,
                'offer_id' => $id,
                'user_role' => $user->role,
            ]);

            $offer = Offer::where('creator_id', $user->id)
                ->find($id);

            if (! $offer) {
                Log::warning('Offer not found for acceptance', [
                    'user_id' => $user->id,
                    'offer_id' => $id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Offer not found',
                ], 404);
            }

            Log::info('Offer found for acceptance', [
                'offer_id' => $offer->id,
                'offer_status' => $offer->status,
                'offer_expires_at' => $offer->expires_at,
                'can_be_accepted' => $offer->canBeAccepted(),
            ]);

            if ($offer->status === 'accepted') {
                return response()->json([
                    'success' => false,
                    'message' => 'This offer has already been accepted',
                ], 400);
            }

            if ($offer->status === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'This offer has already been rejected',
                ], 400);
            }

            if ($offer->status === 'cancelled') {
                return response()->json([
                    'success' => false,
                    'message' => 'This offer has been cancelled',
                ], 400);
            }

            if ($offer->isExpired()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This offer has expired',
                ], 400);
            }

            if (! $offer->canBeAccepted()) {
                Log::warning('Offer cannot be accepted', [
                    'offer_id' => $offer->id,
                    'status' => $offer->status,
                    'expires_at' => $offer->expires_at,
                    'is_expired' => $offer->isExpired(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Offer cannot be accepted (expired or already processed)',
                ], 400);
            }

            Log::info('Attempting to accept offer in model', [
                'offer_id' => $offer->id,
                'brand_id' => $offer->brand_id,
                'creator_id' => $offer->creator_id,
            ]);

            if ($offer->accept()) {

                $chatRoom = ChatRoom::find($offer->chat_room_id);

                if ($chatRoom) {

                    $contract = $offer->contract;

                    $application = CampaignApplication::where('campaign_id', $chatRoom->campaign_id)
                        ->where('creator_id', $chatRoom->creator_id)
                        ->where('status', 'approved')
                        ->first();

                    if ($application) {
                        $application->finalizeAgreement();

                        Log::info('Application workflow status updated to finalized', [
                            'application_id' => $application->id,
                            'campaign_id' => $chatRoom->campaign_id,
                            'creator_id' => $chatRoom->creator_id,
                            'workflow_status' => $application->workflow_status,
                        ]);
                    }

                    Log::info('Contract created successfully', [
                        'contract_id' => $contract->id ?? 'null',
                        'contract_status' => $contract->status ?? 'null',
                        'workflow_status' => $contract->workflow_status ?? 'null',
                    ]);

                    $this->createOfferChatMessage($chatRoom, 'offer_accepted', [
                        'sender_id' => $user->id,
                        'message' => 'Oferta aceita! Contrato criado.',
                        'offer_data' => [
                            'offer_id' => $offer->id,
                            'title' => $offer->title,
                            'description' => $offer->description,
                            'budget' => $offer->budget,
                            'formatted_budget' => $offer->formatted_budget,
                            'estimated_days' => $offer->estimated_days,
                            'status' => $offer->status,
                            'contract_id' => $contract->id ?? null,
                            'contract_status' => $contract->status ?? null,
                            'can_be_completed' => $contract ? $contract->canBeCompleted() : false,
                            'sender' => [
                                'id' => $user->id,
                                'name' => $user->name,
                                'avatar_url' => $user->avatar_url,
                            ],
                        ],
                    ]);

                    Message::create([
                        'chat_room_id' => $chatRoom->id,
                        'sender_id' => null,
                        'message' => 'A criadora aceitou a oferta.',
                        'message_type' => 'system',
                        'is_system_message' => true,
                    ]);

                    event(new OfferAccepted($offer, $chatRoom, $contract));
                }

                Log::info('Offer accepted successfully', [
                    'offer_id' => $offer->id,
                    'creator_id' => $user->id,
                    'brand_id' => $offer->brand_id,
                    'contract_id' => $offer->contract->id ?? 'null',
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Offer accepted successfully! Contract has been created.',
                    'data' => [
                        'offer_id' => $offer->id,
                        'contract_id' => $offer->contract->id ?? null,
                        'status' => $offer->status,
                        'offer' => [
                            'id' => $offer->id,
                            'title' => $offer->title,
                            'description' => $offer->description,
                            'budget' => $offer->budget,
                            'formatted_budget' => $offer->formatted_budget,
                            'estimated_days' => $offer->estimated_days,
                            'status' => $offer->status,
                            'brand_id' => $offer->brand_id,
                            'creator_id' => $offer->creator_id,
                            'chat_room_id' => $chatRoom->room_id,
                        ],
                        'contract' => $contract ? [
                            'id' => $contract->id,
                            'title' => $contract->title,
                            'description' => $contract->description,
                            'status' => $contract->status,
                            'workflow_status' => $contract->status,
                            'brand_id' => $contract->brand_id,
                            'creator_id' => $contract->creator_id,
                            'can_be_completed' => $contract->canBeCompleted(),
                        ] : null,
                    ],
                ]);
            } else {
                Log::error('Failed to accept offer in model', [
                    'offer_id' => $offer->id,
                    'user_id' => $user->id,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to accept offer. Please try again.',
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error accepting offer', [
                'user_id' => $user->id,
                'offer_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to accept offer. Please try again.',
            ], 500);
        }
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isCreator() && ! $user->isStudent()) {
            return response()->json([
                'success' => false,
                'message' => 'Only creators and students can reject offers',
            ], 403);
        }

        try {
            $offer = Offer::where('creator_id', $user->id)
                ->where('status', 'pending')
                ->find($id);

            if (! $offer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Offer not found or cannot be rejected',
                ], 404);
            }

            if (! $offer->canBeAccepted()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Offer cannot be rejected (expired or already processed)',
                ], 400);
            }

            if ($offer->reject($request->reason)) {

                $chatRoom = ChatRoom::find($offer->chat_room_id);

                if ($chatRoom) {

                    $this->createOfferChatMessage($chatRoom, 'offer_rejected', [
                        'sender_id' => $user->id,
                        'message' => 'Oferta rejeitada'.($request->reason ? ": {$request->reason}" : ''),
                        'offer_data' => [
                            'offer_id' => $offer->id,
                            'title' => $offer->title,
                            'description' => $offer->description,
                            'budget' => $offer->budget,
                            'formatted_budget' => $offer->formatted_budget,
                            'estimated_days' => $offer->estimated_days,
                            'status' => $offer->status,
                            'rejection_reason' => $request->reason,
                            'sender' => [
                                'id' => $user->id,
                                'name' => $user->name,
                                'avatar_url' => $user->avatar_url,
                            ],
                        ],
                    ]);

                    event(new OfferRejected($offer, $chatRoom, $user->id, $request->reason));
                }

                Log::info('Offer rejected successfully', [
                    'offer_id' => $offer->id,
                    'creator_id' => $user->id,
                    'brand_id' => $offer->brand_id,
                    'reason' => $request->reason,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Offer rejected successfully',
                    'data' => [
                        'offer_id' => $offer->id,
                        'status' => $offer->status,
                    ],
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to reject offer',
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Error rejecting offer', [
                'user_id' => $user->id,
                'offer_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reject offer. Please try again.',
            ], 500);
        }
    }

    public function cancel(int $id): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        if (! $user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can cancel offers',
            ], 403);
        }

        try {
            $offer = Offer::where('brand_id', $user->id)
                ->where('status', 'pending')
                ->find($id);

            if (! $offer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Offer not found or cannot be cancelled',
                ], 404);
            }

            $offer->update([
                'status' => 'cancelled',
            ]);

            $chatRoom = ChatRoom::find($offer->chat_room_id);

            if ($chatRoom) {

                $this->createOfferChatMessage($chatRoom, 'offer_cancelled', [
                    'sender_id' => $user->id,
                    'message' => 'Oferta cancelada',
                    'offer_data' => [
                        'offer_id' => $offer->id,
                        'title' => $offer->title,
                        'description' => $offer->description,
                        'budget' => $offer->budget,
                        'formatted_budget' => $offer->formatted_budget,
                        'estimated_days' => $offer->estimated_days,
                        'status' => $offer->status,
                        'sender' => [
                            'id' => $user->id,
                            'name' => $user->name,
                            'avatar_url' => $user->avatar_url,
                        ],
                    ],
                ]);

                event(new OfferCancelled($offer, $chatRoom, $user->id));
            }

            NotificationService::notifyUserOfOfferCancelled($offer);

            Log::info('Offer cancelled successfully', [
                'offer_id' => $offer->id,
                'brand_id' => $user->id,
                'creator_id' => $offer->creator_id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Offer cancelled successfully',
                'data' => [
                    'offer_id' => $offer->id,
                    'status' => $offer->status,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error cancelling offer', [
                'user_id' => $user->id,
                'offer_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel offer. Please try again.',
            ], 500);
        }
    }

    public function getOffersForChatRoom(Request $request, string $roomId): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();

        $chatRoom = ChatRoom::where('room_id', $roomId)
            ->where(function ($query) use ($user) {
                $query->where('brand_id', $user->id)
                    ->orWhere('creator_id', $user->id);
            })
            ->first();

        if (! $chatRoom) {
            return response()->json([
                'success' => false,
                'message' => 'Chat room not found or access denied',
            ], 404);
        }

        $offers = Offer::where('chat_room_id', $chatRoom->id)
            ->with(['brand', 'creator'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($offer) {
                return [
                    'id' => $offer->id,
                    'title' => $offer->title,
                    'description' => $offer->description,
                    'budget' => $offer->budget,
                    'formatted_budget' => $offer->formatted_budget,
                    'estimated_days' => $offer->estimated_days,
                    'requirements' => $offer->requirements,
                    'status' => $offer->status,
                    'expires_at' => $offer->expires_at?->format('Y-m-d H:i:s'),
                    'days_until_expiry' => $offer->days_until_expiry,
                    'accepted_at' => $offer->accepted_at?->format('Y-m-d H:i:s'),
                    'rejected_at' => $offer->rejected_at?->format('Y-m-d H:i:s'),
                    'rejection_reason' => $offer->rejection_reason,
                    'brand' => [
                        'id' => $offer->brand->id,
                        'name' => $offer->brand->name,
                        'avatar' => $offer->brand->avatar_url,
                    ],
                    'creator' => [
                        'id' => $offer->creator->id,
                        'name' => $offer->creator->name,
                        'avatar' => $offer->creator->avatar_url,
                    ],
                    'can_accept' => $offer->canBeAccepted(),
                    'can_reject' => $offer->canBeRejected(),
                    'can_cancel' => $offer->canBeCancelled(),
                    'is_expired' => $offer->isExpired(),
                    'created_at' => $offer->created_at->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $offers,
        ]);
    }
}
