<?php

declare(strict_types=1);

namespace App\Http\Controllers\Payment;

use Exception;
use Illuminate\Support\Facades\Log;

use App\Domain\Payment\Services\BrandFundingService;
use App\Domain\Payment\Services\ContractPaymentService;
use App\Domain\Payment\Services\PaymentMethodService;
use App\Domain\Payment\Services\SubscriptionService;
use App\Http\Controllers\Base\Controller;
use App\Models\Payment\Subscription as LocalSubscription;
use App\Models\Payment\WebhookEvent;
use App\Domain\Payment\Repositories\WebhookEventRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private PaymentMethodService $paymentMethodService,
        private SubscriptionService $subscriptionService,
        private ContractPaymentService $contractPaymentService,
        private BrandFundingService $brandFundingService,
        private WebhookEventRepository $webhookEventRepository
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        Log::info('Stripe webhook received', [
            'has_payload' => !empty($payload),
            'payload_length' => strlen($payload),
            'has_signature' => !empty($sigHeader),
            'has_webhook_secret' => !empty($webhookSecret),
            'ip_address' => $request->ip(),
        ]);

        if (!$webhookSecret) {
            Log::error('Stripe webhook secret missing - webhook cannot be processed', [
                'config_exists' => null !== config('services.stripe.webhook_secret'),
            ]);

            return response()->json(['error' => 'Webhook not configured'], 503);
        }

        try {
            Log::info('Verifying Stripe webhook signature');
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
            Log::info('Stripe webhook signature verified successfully', ['event_id' => $event->id ?? 'no_id']);

            // Idempotency check
            $idempotencyResult = $this->checkIdempotency($event, $payload);
            if ($idempotencyResult['response']) {
                return $idempotencyResult['response'];
            }
            $existingEvent = $idempotencyResult['existingEvent'];

            Log::info('Processing Stripe webhook event', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'livemode' => $event->livemode ?? false,
            ]);

            $this->processEvent($event);

            $this->markAsProcessed($existingEvent, $event->id);

            return response()->json(['received' => true]);
        } catch (UnexpectedValueException $e) {
            Log::error('Stripe webhook payload error', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Stripe webhook signature error', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (Exception $e) {
            return $this->handleProcessingError($e, $event ?? null, $existingEvent ?? null);
        }
    }

    /**
     * @return array{response: ?JsonResponse, existingEvent: ?WebhookEvent}
     */
    private function checkIdempotency(Event $event, string $payload): array
    {
        $eventId = $event->id ?? null;
        if (!$eventId) {
            return ['response' => null, 'existingEvent' => null];
        }

        // Check Database
        $existingEvent = $this->webhookEventRepository->findByStripeEventId($eventId);

        if ($existingEvent) {
            if ('processed' === $existingEvent->status) {
                Log::info('Stripe event already processed (Database)', ['event_id' => $eventId]);

                return ['response' => response()->json(['status' => 'duplicate', 'source' => 'database']), 'existingEvent' => $existingEvent];
            }
            if ('processing' === $existingEvent->status) {
                Log::info('Stripe event currently processing', ['event_id' => $eventId]);

                return ['response' => response()->json(['status' => 'processing']), 'existingEvent' => $existingEvent];
            }
        } else {
            // Create record
            try {
                $existingEvent = $this->webhookEventRepository->create([
                    'stripe_event_id' => $eventId,
                    'type' => $event->type,
                    'payload' => json_decode($payload, true),
                    'status' => 'processing',
                ]);
            } catch (Exception $e) {
                // Fallback for race condition
                Log::warning('Could not create WebhookEvent record, might be duplicate', ['error' => $e->getMessage()]);

                return ['response' => response()->json(['status' => 'duplicate']), 'existingEvent' => null];
            }
        }

        // Check Cache
        if (Cache::has('stripe_event_' . $eventId)) {
            return ['response' => response()->json(['status' => 'duplicate', 'source' => 'cache']), 'existingEvent' => $existingEvent];
        }
        Cache::put('stripe_event_' . $eventId, true, 3600);

        return ['response' => null, 'existingEvent' => $existingEvent];
    }

    private function processEvent(Event $event): void
    {
        switch ($event->type) {
            case 'checkout.session.completed':
                $this->handleCheckoutSession($event);

                break;

            case 'invoice.paid':
                $this->handleInvoicePaid($event);

                break;

            case 'invoice.payment_failed':
                $this->handleInvoicePaymentFailed($event);

                break;

            case 'customer.subscription.updated':
            case 'customer.subscription.created':
                $this->handleSubscriptionUpdate($event);

                break;

            case 'charge.dispute.created':
            case 'transfer.failed':
            case 'payout.paid':
            case 'payout.failed':
                $this->handlePaymentEvent($event);

                break;

            default:
                Log::debug('Unhandled Stripe event type', [
                    'event_id' => $event->id,
                    'event_type' => $event->type,
                    'object_type' => $event->data->object->object ?? 'unknown',
                ]);
        }
    }

    private function handleCheckoutSession(Event $event): void
    {
        $session = $event->data->object;

        Log::info('Stripe checkout.session.completed event received', [
            'event_id' => $event->id,
            'session_id' => $session->id ?? 'no_id',
            'mode' => $session->mode ?? 'unknown',
        ]);

        if ('subscription' === $session->mode && $session->subscription) {
            $this->subscriptionService->handleSubscriptionCheckoutCompleted($session);
        } elseif ('payment' === $session->mode) {
            $metadata = $session->metadata ?? null;

            if (isset($metadata->contract_id)) {
                $this->contractPaymentService->handleContractFundingCompleted($session);
            } elseif (isset($metadata->type) && in_array($metadata->type, ['offer_funding', 'platform_funding'])) {
                $this->brandFundingService->processFundingWebhook($session);
            } else {
                Log::warning('Unknown payment session type', [
                    'session_id' => $session->id,
                    'metadata' => $metadata,
                ]);
            }
        } elseif ('setup' === $session->mode) {
            $this->paymentMethodService->processSetupSession($session);
        }
    }

    private function handleInvoicePaid(Event $event): void
    {
        $invoice = $event->data->object;
        $stripeSubscriptionId = $invoice->subscription ?? null;

        Log::info('Stripe invoice.paid event received', [
            'event_id' => $event->id,
            'invoice_id' => $invoice->id ?? 'no_id',
            'subscription_id' => $stripeSubscriptionId,
        ]);

        if ($stripeSubscriptionId) {
            $existingSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if (!$existingSub) {
                Log::info('Creating subscription from invoice.paid event', [
                    'stripe_subscription_id' => $stripeSubscriptionId,
                ]);
                $this->subscriptionService->createSubscriptionFromInvoice($invoice);
            } else {
                $this->subscriptionService->syncSubscription($stripeSubscriptionId, null, null);
            }
        }
    }

    private function handleInvoicePaymentFailed(Event $event): void
    {
        $invoice = $event->data->object;
        $stripeSubscriptionId = $invoice->subscription ?? null;

        Log::warning('Stripe invoice.payment_failed event received', [
            'event_id' => $event->id,
            'invoice_id' => $invoice->id ?? 'no_id',
            'subscription_id' => $stripeSubscriptionId,
        ]);

        if ($stripeSubscriptionId) {
            $this->subscriptionService->markSubscriptionPaymentFailed($stripeSubscriptionId, $invoice->id);
        }
    }

    private function handleSubscriptionUpdate(Event $event): void
    {
        $stripeSub = $event->data->object;

        Log::info('Stripe subscription event received', [
            'event_id' => $event->id,
            'event_type' => $event->type,
            'subscription_id' => $stripeSub->id ?? 'no_id',
        ]);

        $this->subscriptionService->syncSubscription($stripeSub->id, null, null);
    }

    private function handlePaymentEvent(Event $event): void
    {
        Log::info('Stripe payment/payout event received', [
            'event_id' => $event->id,
            'event_type' => $event->type,
            'object_id' => $event->data->object->id ?? 'no_id',
        ]);
    }

    private function markAsProcessed($existingEvent, ?string $eventId): void
    {
        if (isset($existingEvent) && $existingEvent) {
            $this->webhookEventRepository->updateStatus($existingEvent, 'processed');
        } elseif ($eventId) {
            try {
                $this->webhookEventRepository->updateStatusByStripeEventId($eventId, 'processed');
            } catch (Exception $e) {
                // Ignore if update fails
            }
        }
    }

    private function handleProcessingError(Exception $e, ?Event $event, $existingEvent): JsonResponse
    {
        Log::error('Stripe webhook processing error', [
            'error' => $e->getMessage(),
            'event_id' => $event->id ?? 'unknown',
        ]);

        if (isset($existingEvent) && $existingEvent) {
            $this->webhookEventRepository->updateStatus($existingEvent, 'failed', $e->getMessage());
        } elseif (isset($event->id)) {
            try {
                $this->webhookEventRepository->updateStatusByStripeEventId($event->id, 'failed', $e->getMessage());
            } catch (Exception $ex) {
                // Ignore
            }
        }

        return response()->json(['error' => 'Webhook processing failed'], 500);
    }
}
