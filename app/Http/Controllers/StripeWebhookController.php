<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Subscription as LocalSubscription;
use App\Models\User;
use Carbon\Carbon;

class StripeWebhookController extends Controller
{
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

            // Idempotency: avoid reprocessing
            $eventId = $event->id ?? null;
            if ($eventId && \Illuminate\Support\Facades\Cache::has('stripe_event_'.$eventId)) {
                return response()->json(['status' => 'duplicate']);
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
                        $this->syncSubscription($stripeSubscriptionId, $invoice->id);
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
                        $this->markSubscriptionPaymentFailed($stripeSubscriptionId, $invoice->id);
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
                    
                    $this->syncSubscription($stripeSub->id, $stripeSub->latest_invoice ?? null);
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
                    // TODO: implementar reconciliação de pagamentos/saques
                    break;
                    
                default:
                    Log::debug('Unhandled Stripe event type', [
                        'event_id' => $event->id,
                        'event_type' => $event->type,
                        'object_type' => $event->data->object->object ?? 'unknown',
                    ]);
            }

            return response()->json(['received' => true]);
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook payload error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::error('Stripe webhook signature error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook processing error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Webhook processing failed'], 500);
        }
    }

    private function syncSubscription(string $stripeSubscriptionId, ?string $latestInvoiceId = null): void
    {
        try {
            Log::info('Syncing subscription from Stripe webhook', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
            ]);
            
            // Fetch subscription from Stripe for authoritative data
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            
            Log::info('Retrieving subscription from Stripe API', [
                'stripe_subscription_id' => $stripeSubscriptionId,
            ]);
            
            $stripeSub = \Stripe\Subscription::retrieve($stripeSubscriptionId);
            
            Log::info('Subscription retrieved from Stripe', [
                'stripe_subscription_id' => $stripeSub->id,
                'status' => $stripeSub->status ?? 'unknown',
                'customer' => $stripeSub->customer ?? 'no_customer',
                'current_period_start' => $stripeSub->current_period_start ?? null,
                'current_period_end' => $stripeSub->current_period_end ?? null,
            ]);

            // Find local subscription
            $localSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if (!$localSub) {
                Log::warning('Local subscription not found for Stripe ID', [
                    'stripe_subscription_id' => $stripeSubscriptionId,
                    'search_count' => LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->count(),
                ]);
                return;
            }
            
            Log::info('Local subscription found for sync', [
                'local_subscription_id' => $localSub->id,
                'user_id' => $localSub->user_id,
                'current_status' => $localSub->status,
                'stripe_status' => $localSub->stripe_status,
            ]);

            DB::beginTransaction();

            $currentPeriodEnd = isset($stripeSub->current_period_end) ? Carbon::createFromTimestamp($stripeSub->current_period_end) : null;
            $currentPeriodStart = isset($stripeSub->current_period_start) ? Carbon::createFromTimestamp($stripeSub->current_period_start) : null;

            $localSub->update([
                'status' => LocalSubscription::STATUS_ACTIVE,
                'starts_at' => $currentPeriodStart,
                'expires_at' => $currentPeriodEnd,
                'stripe_status' => $stripeSub->status,
                'stripe_latest_invoice_id' => $latestInvoiceId,
            ]);

            // Update user premium flags
            $user = User::find($localSub->user_id);
            if ($user) {
                $user->update([
                    'has_premium' => true,
                    'premium_expires_at' => $currentPeriodEnd,
                ]);
            }

            DB::commit();
            
            Log::info('Subscription synced successfully from webhook', [
                'local_subscription_id' => $localSub->id,
                'user_id' => $localSub->user_id,
                'new_status' => $localSub->status,
                'premium_expires_at' => $user->premium_expires_at?->toISOString(),
            ]);
            
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to sync subscription from webhook', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function markSubscriptionPaymentFailed(string $stripeSubscriptionId, ?string $latestInvoiceId = null): void
    {
        try {
            Log::info('Marking subscription payment as failed', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
            ]);
            
            $localSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if (!$localSub) {
                Log::warning('Local subscription not found when marking payment failed', [
                    'stripe_subscription_id' => $stripeSubscriptionId,
                ]);
                return;
            }
            
            Log::info('Local subscription found for payment failure update', [
                'local_subscription_id' => $localSub->id,
                'user_id' => $localSub->user_id,
                'current_status' => $localSub->status,
            ]);

            $localSub->update([
                'status' => LocalSubscription::STATUS_PENDING,
                'stripe_status' => 'past_due',
                'stripe_latest_invoice_id' => $latestInvoiceId,
            ]);
            
            Log::info('Subscription payment marked as failed', [
                'local_subscription_id' => $localSub->id,
                'user_id' => $localSub->user_id,
                'new_status' => $localSub->status,
                'stripe_status' => $localSub->stripe_status,
            ]);
            
        } catch (\Throwable $e) {
            Log::error('Failed to mark subscription payment failed', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'latest_invoice_id' => $latestInvoiceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}


