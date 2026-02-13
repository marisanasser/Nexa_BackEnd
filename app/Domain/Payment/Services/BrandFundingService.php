<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Models\Campaign\Campaign;
use App\Models\Common\Notification;
use App\Models\Payment\BrandBalance;
use App\Models\Payment\Transaction;
use App\Models\User\User;
use App\Domain\Notification\Services\NotificationService;
use App\Wrappers\StripeWrapper;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session;

class BrandFundingService
{
    public function __construct(
        private StripeWrapper $stripeWrapper,
        private StripeCustomerService $customerService
    ) {
    }

    /**
     * Create a checkout session for brand platform funding.
     */
    public function createFundingCheckout(
        User $brand,
        float $amount,
        array $metadata,
        string $successUrl,
        string $cancelUrl
    ): Session {
        $customerId = $this->customerService->ensureStripeCustomer($brand);

        return $this->stripeWrapper->createCheckoutSession([
            'customer' => $customerId,
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'locale' => 'pt-BR',
            'line_items' => [
                [
                    'price_data' => [
                        'currency' => 'brl',
                        'product_data' => [
                            'name' => 'Platform Funding',
                            'description' => 'Fund your platform account',
                        ],
                        'unit_amount' => (int) round($amount * 100),
                    ],
                    'quantity' => 1,
                ]
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => array_merge([
                'user_id' => (string) $brand->id,
                'type' => 'platform_funding', // or offer_funding, kept generic here or passed in metadata
                'amount' => (string) $amount,
            ], $metadata),
        ]);
    }

    /**
     * Handle successful funding checkout (from Controller).
     */
    public function handleFundingSuccess(string $sessionId, User $user): array
    {
        $session = $this->stripeWrapper->retrieveCheckoutSession($sessionId, [
            'expand' => ['payment_intent', 'payment_intent.charges.data.payment_method_details'],
        ]);

        return $this->processSession($session, $user);
    }

    /**
     * Handle successful funding checkout (from Webhook).
     */
    public function processFundingWebhook(Session $session): void
    {
        // Resolve user
        $user = null;
        $metadata = $session->metadata;

        $userId = null;
        if (is_array($metadata)) {
            $userId = $metadata['user_id'] ?? null;
        } elseif (is_object($metadata)) {
            $userId = $metadata->user_id ?? null;
        }

        if ($userId) {
            $user = User::find($userId);
        }

        if (!$user && $session->customer) {
            // Logic to find user by stripe customer id if needed, or fail
            $user = User::where('stripe_customer_id', $session->customer)->first();
        }

        if (!$user) {
            Log::error('User not found for funding webhook', ['session_id' => $session->id]);

            return;
        }

        try {
            $this->processSession($session, $user);
        } catch (Exception $e) {
            Log::error('Failed to process funding webhook', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    private function processSession(Session $session, User $user): array
    {
        $metadata = $session->metadata;

        // If called from Controller, $user is passed and trusted.
        // But verifying metadata user_id matches is good practice still, handled inside Logic.

        $sessionUserId = null;
        if (is_array($metadata)) {
            $sessionUserId = $metadata['user_id'] ?? null;
        } elseif (is_object($metadata)) {
            $sessionUserId = $metadata->user_id ?? null;
        }

        if ($sessionUserId && (string) $sessionUserId !== (string) $user->id) {
            // If we resolved user from metadata in webhook, this check passes.
            // If from controller, it ensures safety.
            throw new Exception('Invalid session user');
        }

        if ('paid' !== $session->payment_status) {
            throw new Exception('Payment not completed');
        }

        $amount = null;
        if (is_array($metadata)) {
            $amount = $metadata['amount'] ?? null;
        } elseif (is_object($metadata)) {
            $amount = $metadata->amount ?? null;
        }

        $amount = $amount ? (float) $amount : ($session->amount_total / 100);

        $paymentIntent = $session->payment_intent;
        // Expand if it's ID
        if (is_string($paymentIntent)) {
            $paymentIntent = $this->stripeWrapper->retrievePaymentIntent($paymentIntent, ['expand' => ['charges.data.payment_method_details']]);
        }

        // Check if transaction already exists
        $existingTransaction = Transaction::where('stripe_payment_intent_id', $paymentIntent->id)
            ->where('user_id', $user->id)
            ->first()
        ;

        if ($existingTransaction) {
            return [
                'transaction' => $existingTransaction,
                'already_processed' => true,
            ];
        }

        if ('succeeded' !== $paymentIntent->status) {
            throw new Exception('Payment intent not succeeded');
        }

        // Extract card details
        $charge = $paymentIntent->charges->data[0] ?? null;
        $cardDetails = $this->extractCardDetails($charge);

        return DB::transaction(function () use ($user, $paymentIntent, $charge, $amount, $session, $metadata, $cardDetails) {
            // Convert metadata to array for json storage
            $metadataArr = is_object($metadata) ? $metadata->toArray() : (array) $metadata;
            $type = $metadataArr['type'] ?? 'platform_funding';

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'stripe_payment_intent_id' => $paymentIntent->id,
                'stripe_charge_id' => $charge->id ?? null,
                'status' => 'paid',
                'amount' => $amount,
                'payment_method' => 'stripe',
                'card_brand' => $cardDetails['brand'],
                'card_last4' => $cardDetails['last4'],
                'card_holder_name' => $cardDetails['name'],
                'payment_data' => [
                    'checkout_session_id' => $session->id,
                    'payment_intent' => $paymentIntent->id,
                    'charge_id' => $charge->id ?? null,
                    'type' => $type,
                    'metadata' => $metadataArr,
                ],
                'paid_at' => now(),
            ]);

            // Update Brand Balance
            $this->updateBrandBalance($user->id, $amount);

            // Special handling for campaign funding if present
            if (isset($metadataArr['campaign_id'])) {
                $this->updateCampaignPrice($metadataArr['campaign_id'], $amount);
            }

            // Create notification
            $this->createFundingNotification($user->id, $amount, $transaction->id, $metadataArr);

            return [
                'transaction' => $transaction,
                'already_processed' => false,
            ];
        });
    }

    private function extractCardDetails($charge): array
    {
        $details = [
            'brand' => null,
            'last4' => null,
            'name' => null,
        ];

        if ($charge && isset($charge->payment_method_details->card)) {
            $card = $charge->payment_method_details->card;
            $details['brand'] = $card->brand ?? null;
            $details['last4'] = $card->last4 ?? null;
            $details['name'] = $card->name ?? null;
        }

        return $details;
    }

    private function updateBrandBalance(int $brandId, float $amount): void
    {
        $balance = BrandBalance::firstOrCreate(
            ['brand_id' => $brandId],
            ['available_balance' => 0, 'total_funded' => 0]
        );

        $balance->increment('available_balance', $amount);
        $balance->increment('total_funded', $amount);
    }

    private function updateCampaignPrice($campaignId, float $amount): void
    {
        try {
            $campaign = Campaign::find($campaignId);
            if ($campaign) {
                $campaign->update(['final_price' => $amount]);
            }
        } catch (Exception $e) {
            Log::warning('Failed to update campaign final_price', ['campaign_id' => $campaignId, 'error' => $e->getMessage()]);
        }
    }

    private function createFundingNotification(int $userId, float $amount, int $transactionId, array $metadata): void
    {
        try {
            $fundingData = ['transaction_id' => $transactionId];

            // Extract relevant metadata fields
            foreach (['creator_id', 'chat_room_id', 'campaign_id'] as $field) {
                if (isset($metadata[$field])) {
                    $fundingData[$field] = $metadata[$field];
                }
            }

            $notification = Notification::createPlatformFundingSuccess($userId, $amount, $fundingData);
            NotificationService::sendSocketNotification($userId, $notification);
        } catch (Exception $e) {
            Log::error('Failed to create funding notification', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
