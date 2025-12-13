<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\WebhookEvent;
use App\Models\Subscription as LocalSubscription;
use App\Services\PaymentService;
use App\Repositories\WebhookEventRepository;

class StripeWebhookController extends Controller
{
    protected $paymentService;
    protected $webhookEventRepository;

    public function __construct(PaymentService $paymentService, WebhookEventRepository $webhookEventRepository)
    {
        $this->paymentService = $paymentService;
        $this->webhookEventRepository = $webhookEventRepository;
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
                'config_exists' => config('services.stripe.webhook_secret') !== null,
            ]);
            return response()->json(['error' => 'Webhook not configured'], 503);
        }

        try {
            Log::info('Verifying Stripe webhook signature', [
                'signature_length' => strlen($sigHeader ?? ''),
                'webhook_secret_length' => strlen($webhookSecret),
            ]);
            
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $webhookSecret
            );
            
            Log::info('Stripe webhook signature verified successfully', [
                'event_id' => $event->id ?? 'no_id',
                'event_type' => $event->type ?? 'no_type',
                'livemode' => $event->livemode ?? false,
            ]);

            // Idempotency check using Database
            $eventId = $event->id ?? null;
            if ($eventId) {
                // First check if we have already processed this event
                $existingEvent = $this->webhookEventRepository->findByStripeEventId($eventId);
                
                if ($existingEvent && $existingEvent->status === 'processed') {
                    Log::info('Stripe event already processed (Database)', ['event_id' => $eventId]);
                    return response()->json(['status' => 'duplicate', 'source' => 'database']);
                }
                
                // If it exists but failed or is processing, we might want to retry or just log it
                // For now, let's treat 'processing' as duplicate to avoid race conditions
                if ($existingEvent && $existingEvent->status === 'processing') {
                     Log::info('Stripe event currently processing', ['event_id' => $eventId]);
                     return response()->json(['status' => 'processing']);
                }

                // If not exists, create record
                if (!$existingEvent) {
                    try {
                        $this->webhookEventRepository->create([
                            'stripe_event_id' => $eventId,
                            'type' => $event->type,
                            'payload' => json_decode($payload, true),
                            'status' => 'processing',
                        ]);
                    } catch (\Exception $e) {
                         // Fallback for race condition where another request created it just now
                         Log::warning('Could not create WebhookEvent record, might be duplicate', ['error' => $e->getMessage()]);
                         return response()->json(['status' => 'duplicate']);
                    }
                }
            }
            
            // Fallback to Cache for extra safety (or if DB fails)
            if ($eventId && \Illuminate\Support\Facades\Cache::has('stripe_event_'.$eventId)) {
                return response()->json(['status' => 'duplicate', 'source' => 'cache']);
            }
            if ($eventId) {
                \Illuminate\Support\Facades\Cache::put('stripe_event_'.$eventId, true, 3600);
            }

            Log::info('Processing Stripe webhook event', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'livemode' => $event->livemode ?? false,
                'created' => $event->created ?? null,
            ]);
            
            switch ($event->type) {
                case 'checkout.session.completed':
                        $session = $event->data->object;
                        
                        Log::info('Stripe checkout.session.completed event received', [
                            'event_id' => $event->id,
                            'session_id' => $session->id ?? 'no_id',
                            'customer_id' => $session->customer ?? 'no_customer',
                            'subscription_id' => $session->subscription ?? null,
                            'mode' => $session->mode ?? 'unknown',
                        ]);
                        
                        if ($session->mode === 'subscription' && $session->subscription) {
                            $this->paymentService->handleSubscriptionCheckout($session);
                        }
                        elseif ($session->mode === 'payment') {
                            $this->paymentService->handleContractFundingCheckout($session);
                        }
                        elseif ($session->mode === 'setup') {
                            $this->paymentService->handleGeneralSetupCheckout($session);
                        }
                        break;
                        
                    case 'invoice.paid':
                        $invoice = $event->data->object;
                    $stripeSubscriptionId = $invoice->subscription ?? null;
                    
                    Log::info('Stripe invoice.paid event received', [
                        'event_id' => $event->id,
                        'invoice_id' => $invoice->id ?? 'no_id',
                        'subscription_id' => $stripeSubscriptionId,
                        'amount_paid' => $invoice->amount_paid ?? 0,
                        'currency' => $invoice->currency ?? 'unknown',
                    ]);
                    
                    if ($stripeSubscriptionId) {
                        
                        $existingSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
                        if (!$existingSub) {
                            
                            Log::info('Creating subscription from invoice.paid event', [
                                'stripe_subscription_id' => $stripeSubscriptionId,
                            ]);
                            $this->paymentService->createSubscriptionFromInvoice($invoice);
                        } else {
                            
                            $this->paymentService->syncSubscription($stripeSubscriptionId, $invoice->id);
                        }
                    }
                    break;
                    
                case 'invoice.payment_failed':
                    $invoice = $event->data->object;
                    $stripeSubscriptionId = $invoice->subscription ?? null;
                    
                    Log::warning('Stripe invoice.payment_failed event received', [
                        'event_id' => $event->id,
                        'invoice_id' => $invoice->id ?? 'no_id',
                        'subscription_id' => $stripeSubscriptionId,
                        'attempt_count' => $invoice->attempt_count ?? 0,
                        'amount_due' => $invoice->amount_due ?? 0,
                    ]);
                    
                    if ($stripeSubscriptionId) {
                        $this->paymentService->markSubscriptionPaymentFailed($stripeSubscriptionId, $invoice->id);
                    }
                    break;
                    
                case 'customer.subscription.updated':
                case 'customer.subscription.created':
                    $stripeSub = $event->data->object;
                    
                    Log::info('Stripe subscription event received', [
                        'event_id' => $event->id,
                        'event_type' => $event->type,
                        'subscription_id' => $stripeSub->id ?? 'no_id',
                        'subscription_status' => $stripeSub->status ?? 'unknown',
                        'customer_id' => $stripeSub->customer ?? 'no_customer',
                        'latest_invoice' => $stripeSub->latest_invoice ?? null,
                    ]);
                    
                    $this->paymentService->syncSubscription($stripeSub->id, $stripeSub->latest_invoice ?? null);
                    break;
                    
                case 'charge.dispute.created':
                case 'transfer.failed':
                case 'payout.paid':
                case 'payout.failed':
                    Log::info('Stripe payment/payout event received', [
                        'event_id' => $event->id,
                        'event_type' => $event->type,
                        'object_id' => $event->data->object->id ?? 'no_id',
                        'amount' => $event->data->object->amount ?? 0,
                        'currency' => $event->data->object->currency ?? 'unknown',
                    ]);
                    
                    break;
                    
                default:
                    Log::debug('Unhandled Stripe event type', [
                        'event_id' => $event->id,
                        'event_type' => $event->type,
                        'object_type' => $event->data->object->object ?? 'unknown',
                    ]);
            }

            // Update event status to processed
            if (isset($existingEvent) && $existingEvent) {
                $this->webhookEventRepository->updateStatus($existingEvent, 'processed');
            } elseif ($eventId) {
                try {
                    $this->webhookEventRepository->updateStatusByStripeEventId($eventId, 'processed');
                } catch (\Exception $e) {
                     // Ignore if update fails
                }
            }

            return response()->json(['received' => true]);
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook payload error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe webhook signature error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook processing error', [
                'error' => $e->getMessage(),
                'event_id' => $event->id ?? 'unknown',
            ]);

            // Update event status to failed
            if (isset($existingEvent) && $existingEvent) {
                $this->webhookEventRepository->updateStatus($existingEvent, 'failed', $e->getMessage());
            } elseif (isset($eventId)) {
                try {
                    $this->webhookEventRepository->updateStatusByStripeEventId($eventId, 'failed', $e->getMessage());
                } catch (\Exception $ex) {
                     // Ignore
                }
            }

            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

}
