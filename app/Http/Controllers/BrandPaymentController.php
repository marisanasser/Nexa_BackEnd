<?php

namespace App\Http\Controllers;

use App\Models\BrandPaymentMethod;
use App\Models\User;
use App\Http\Requests\StoreBrandPaymentMethodRequest;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\Checkout\Session;
use Stripe\PaymentMethod as StripePaymentMethod;
use Stripe\SetupIntent;

class BrandPaymentController extends Controller
{
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    
    public function savePaymentMethod(StoreBrandPaymentMethodRequest $request): JsonResponse
    {
        $user = auth()->user();

        Log::info('Save payment method request', [
            'user_id' => $user->id,
            'is_default' => $request->boolean('is_default')
        ]);

        try {
            $paymentMethod = $this->paymentService->saveBrandPaymentMethod($user, $request->all());

            Log::info('Payment method created successfully', [
                'user_id' => $user->id,
                'payment_method_id' => $paymentMethod->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment method added successfully',
                'data' => $paymentMethod
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create payment method', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment method. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
    public function getPaymentMethods(): JsonResponse
    {
        $user = auth()->user();

        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can access payment methods',
            ], 403);
        }

        $paymentMethods = $this->paymentService->getBrandPaymentMethods($user);

        return response()->json([
            'success' => true,
            'data' => $paymentMethods->map(function ($method) {
                return [
                    'id' => $method->id,
                    'card_info' => $method->formatted_card_info,
                    'card_brand' => $method->card_brand,
                    'card_last4' => $method->card_last4,
                    'card_holder_name' => $method->card_holder_name,
                    'is_default' => $method->is_default,
                    'created_at' => $method->created_at,
                ];
            })
        ]);
    }

    
    public function setDefaultPaymentMethod(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can manage payment methods',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|exists:brand_payment_methods,id,user_id,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $paymentMethod = BrandPaymentMethod::find($request->payment_method_id);
        
        $this->paymentService->setAsDefault($user, $paymentMethod);

        Log::info('Updated user default payment method', [
            'user_id' => $user->id,
            'payment_method_id' => $paymentMethod->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Default payment method updated successfully',
        ]);
    }

    
    public function deletePaymentMethod(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user->isBrand()) {
            return response()->json([
                'success' => false,
                'message' => 'Only brands can manage payment methods',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'payment_method_id' => 'required|exists:brand_payment_methods,id,user_id,' . $user->id,
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $this->paymentService->deleteBrandPaymentMethod($user, $request->payment_method_id);
            
            return response()->json([
                'success' => true,
                'message' => 'Payment method deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    
    public function createCheckoutSession(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can add payment methods',
                ], 403);
            }

            Log::info('Creating Stripe Checkout Session for payment method setup', [
                'user_id' => $user->id,
            ]);

            $session = $this->paymentService->createSetupCheckoutSession($user);

            Log::info('Stripe Checkout Session created', [
                'user_id' => $user->id,
                'session_id' => $session->id,
                'customer_id' => $session->customer,
            ]);

            return response()->json([
                'success' => true,
                'url' => $session->url,
                'session_id' => $session->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create Stripe Checkout Session', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    public function handleCheckoutSuccess(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->isBrand()) {
                 return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized or not a brand',
                ], 403);
            }

            $sessionId = $request->input('session_id') ?? $request->query('session_id');

            if (!$sessionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session ID is required',
                ], 400);
            }

            $result = $this->paymentService->handleSetupSessionSuccess($sessionId, $user);
            $paymentMethodRecord = $result['payment_method'];

            return response()->json([
                'success' => true,
                'message' => 'Payment method added successfully',
                'data' => [
                    'id' => $paymentMethodRecord->id,
                    'card_info' => $paymentMethodRecord->formatted_card_info,
                    'card_brand' => $paymentMethodRecord->card_brand,
                    'card_last4' => $paymentMethodRecord->card_last4,
                    'card_holder_name' => $paymentMethodRecord->card_holder_name,
                    'is_default' => $paymentMethodRecord->is_default,
                    'created_at' => $paymentMethodRecord->created_at,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to handle checkout success', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to save payment method. ' . $e->getMessage(),
            ], 500);
        }
    }

    
    public function createFundingCheckout(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can create funding checkout sessions',
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'amount' => 'required|numeric|min:10|max:100000',
                'creator_id' => 'required|integer|exists:users,id',
                'chat_room_id' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            
            $chatRoom = \App\Models\ChatRoom::where('room_id', $request->chat_room_id)->first();
            $campaignId = $chatRoom ? $chatRoom->campaign_id : null;

            $stripeSecret = config('services.stripe.secret');
            if (!$stripeSecret) {
                return response()->json([
                    'success' => false,
                    'message' => 'Stripe is not configured',
                ], 503);
            }

            Stripe::setApiKey($stripeSecret);

            
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
            $amount = $request->amount;

            
            $checkoutSession = Session::create([
                'customer' => $customerId,
                'mode' => 'payment',
                'payment_method_types' => ['card'],
                'locale' => 'pt-BR',
                'line_items' => [[
                    'price_data' => [
                        'currency' => 'brl',
                        'product_data' => [
                            'name' => 'Platform Funding for Offer',
                            'description' => 'Fund your platform account to send offers',
                        ],
                        'unit_amount' => (int) round($amount * 100), 
                    ],
                    'quantity' => 1,
                ]],
                'success_url' => $frontendUrl . '/brand?component=Pagamentos&offer_funding_success=true&session_id={CHECKOUT_SESSION_ID}&creator_id=' . $request->creator_id . '&chat_room_id=' . urlencode($request->chat_room_id) . '&amount=' . $amount,
                'cancel_url' => $frontendUrl . '/brand?component=Pagamentos&offer_funding_canceled=true&creator_id=' . $request->creator_id . '&chat_room_id=' . urlencode($request->chat_room_id),
                'metadata' => [
                    'user_id' => (string) $user->id,
                    'type' => 'offer_funding',
                    'creator_id' => (string) $request->creator_id,
                    'chat_room_id' => $request->chat_room_id,
                    'amount' => (string) $amount,
                    'campaign_id' => $campaignId ? (string) $campaignId : null,
                ],
            ]);

            Log::info('Platform funding checkout session created', [
                'session_id' => $checkoutSession->id,
                'user_id' => $user->id,
                'customer_id' => $customerId,
                'creator_id' => $request->creator_id,
                'amount' => $amount,
            ]);

            return response()->json([
                'success' => true,
                'url' => $checkoutSession->url,
                'session_id' => $checkoutSession->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create platform funding checkout session', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create checkout session. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    
    private function parseCardInfo(string $cardHash, string $cardHolderName): array
    {
        
        
        $last4 = substr($cardHash, -4);
        
        
        $brand = 'Visa'; 
        
        if (strpos($cardHash, 'master') !== false) {
            $brand = 'Mastercard';
        } elseif (strpos($cardHash, 'amex') !== false) {
            $brand = 'American Express';
        } elseif (strpos($cardHash, 'elo') !== false) {
            $brand = 'Elo';
        }

        return [
            'brand' => $brand,
            'last4' => $last4,
            'holder_name' => $cardHolderName
        ];
    }

    
    public function handleOfferFundingSuccess(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can access this endpoint',
                ], 403);
            }

            $sessionId = $request->input('session_id') ?? $request->query('session_id');
            
            if (!$sessionId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session ID is required',
                ], 400);
            }

            Log::info('Handling offer funding success callback', [
                'user_id' => $user->id,
                'session_id' => $sessionId,
            ]);

            Stripe::setApiKey(config('services.stripe.secret'));
            
            
            $session = Session::retrieve($sessionId, [
                'expand' => ['payment_intent', 'payment_intent.charges.data.payment_method_details'],
            ]);

            
            $metadata = $session->metadata ?? null;
            $sessionUserId = null;
            
            if (is_array($metadata)) {
                $sessionUserId = $metadata['user_id'] ?? null;
            } elseif (is_object($metadata)) {
                $sessionUserId = $metadata->user_id ?? null;
            }

            if (!$sessionUserId || (string)$sessionUserId !== (string)$user->id) {
                Log::warning('Session does not belong to user', [
                    'session_user_id' => $sessionUserId,
                    'authenticated_user_id' => $user->id,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid session',
                ], 403);
            }

            
            if ($session->payment_status !== 'paid') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not completed',
                ], 400);
            }

            
            $amount = null;
            if (is_array($metadata)) {
                $amount = $metadata['amount'] ?? null;
            } elseif (is_object($metadata)) {
                $amount = $metadata->amount ?? null;
            }
            
            $transactionAmount = $amount ? (float) $amount : ($session->amount_total / 100);

            
            $paymentIntentId = $session->payment_intent;
            if (is_object($paymentIntentId)) {
                $paymentIntentId = $paymentIntentId->id;
            }

            if (!$paymentIntentId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment intent not found',
                ], 400);
            }

            
            $existingTransaction = \App\Models\Transaction::where('stripe_payment_intent_id', $paymentIntentId)
                ->where('user_id', $user->id)
                ->first();

            if ($existingTransaction) {
                Log::info('Offer funding transaction already exists', [
                    'transaction_id' => $existingTransaction->id,
                    'user_id' => $user->id,
                ]);
                
                
                $this->createOfferFundingNotification($user->id, $transactionAmount, $metadata, $existingTransaction->id);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Transaction already processed',
                    'data' => [
                        'transaction_id' => $existingTransaction->id,
                        'amount' => $transactionAmount,
                    ],
                ]);
            }

            
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId, [
                'expand' => ['charges.data.payment_method_details'],
            ]);

            if ($paymentIntent->status !== 'succeeded') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment intent not succeeded',
                ], 400);
            }

            
            $charge = null;
            $cardBrand = null;
            $cardLast4 = null;
            $cardHolderName = null;

            if (!empty($paymentIntent->charges->data)) {
                $charge = $paymentIntent->charges->data[0];
                $paymentMethodDetails = $charge->payment_method_details->card ?? null;
                if ($paymentMethodDetails) {
                    $cardBrand = $paymentMethodDetails->brand ?? null;
                    $cardLast4 = $paymentMethodDetails->last4 ?? null;
                    $cardHolderName = $paymentMethodDetails->name ?? null;
                }
            }

            DB::beginTransaction();

            try {
                
                $transaction = \App\Models\Transaction::create([
                    'user_id' => $user->id,
                    'stripe_payment_intent_id' => $paymentIntent->id,
                    'stripe_charge_id' => $charge->id ?? null,
                    'status' => 'paid',
                    'amount' => $transactionAmount,
                    'payment_method' => 'stripe',
                    'card_brand' => $cardBrand,
                    'card_last4' => $cardLast4,
                    'card_holder_name' => $cardHolderName,
                    'payment_data' => [
                        'checkout_session_id' => $session->id,
                        'payment_intent' => $paymentIntent->id,
                        'charge_id' => $charge->id ?? null,
                        'type' => 'offer_funding',
                        'metadata' => $metadata,
                    ],
                    'paid_at' => now(),
                ]);

                
                $campaignId = null;
                if (is_array($metadata)) {
                    $campaignId = $metadata['campaign_id'] ?? null;
                } elseif (is_object($metadata)) {
                    $campaignId = $metadata->campaign_id ?? null;
                }

                if ($campaignId) {
                    try {
                        $campaign = \App\Models\Campaign::find($campaignId);
                        if ($campaign) {
                            $campaign->update([
                                'final_price' => $transactionAmount,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to update campaign final_price', [
                            'campaign_id' => $campaignId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                DB::commit();

                Log::info('Offer funding transaction created successfully', [
                    'transaction_id' => $transaction->id,
                    'user_id' => $user->id,
                    'amount' => $transactionAmount,
                ]);

                
                $this->createOfferFundingNotification($user->id, $transactionAmount, $metadata, $transaction->id);

                return response()->json([
                    'success' => true,
                    'message' => 'Platform funding processed successfully',
                    'data' => [
                        'transaction_id' => $transaction->id,
                        'amount' => $transactionAmount,
                    ],
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('Failed to handle offer funding success', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to process funding. Please try again.',
            ], 500);
        }
    }

    
    
    public function checkFundingStatus(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->isBrand()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only brands can access this endpoint',
                ], 403);
            }

            
            $hasFunding = \App\Models\Transaction::where('user_id', $user->id)
                ->where('status', 'paid')
                ->where(function($query) {
                    $query->whereJsonContains('payment_data->type', 'offer_funding')
                          ->orWhereJsonContains('payment_data->type', 'platform_funding');
                })
                ->exists();

            
            $brandBalance = \App\Models\BrandBalance::where('brand_id', $user->id)->first();
            $hasBalance = $brandBalance && $brandBalance->total_funded > 0;

            $hasFunded = $hasFunding || $hasBalance;

            return response()->json([
                'success' => true,
                'data' => [
                    'has_funded' => $hasFunded,
                    'has_funding_transactions' => $hasFunding,
                    'has_balance' => $hasBalance,
                    'available_balance' => $brandBalance ? $brandBalance->available_balance : 0,
                    'total_funded' => $brandBalance ? $brandBalance->total_funded : 0,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to check brand funding status', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check funding status',
            ], 500);
        }
    }

    private function createOfferFundingNotification($userId, $amount, $metadata, $transactionId): void
    {
        try {
            $fundingData = [
                'transaction_id' => $transactionId,
            ];
            
            if (is_array($metadata)) {
                $fundingData['creator_id'] = $metadata['creator_id'] ?? null;
                $fundingData['chat_room_id'] = $metadata['chat_room_id'] ?? null;
                $fundingData['campaign_id'] = $metadata['campaign_id'] ?? null;
            } elseif (is_object($metadata)) {
                $fundingData['creator_id'] = $metadata->creator_id ?? null;
                $fundingData['chat_room_id'] = $metadata->chat_room_id ?? null;
                $fundingData['campaign_id'] = $metadata->campaign_id ?? null;
            }
            
            $notification = \App\Models\Notification::createPlatformFundingSuccess(
                $userId,
                $amount,
                $fundingData
            );
            
            
            \App\Services\NotificationService::sendSocketNotification($userId, $notification);
            
            Log::info('Platform funding success notification created', [
                'notification_id' => $notification->id,
                'user_id' => $userId,
                'amount' => $amount,
            ]);
        } catch (\Exception $e) {
            
            Log::error('Failed to create platform funding success notification', [
                'user_id' => $userId,
                'amount' => $amount,
                'error' => $e->getMessage(),
            ]);
        }
    }
} 