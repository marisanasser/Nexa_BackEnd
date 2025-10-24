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

        if (!$webhookSecret) {
            Log::warning('Stripe webhook secret missing');
            return response()->json(['error' => 'Webhook not configured'], 503);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $webhookSecret
            );

            // Idempotency: avoid reprocessing
            $eventId = $event->id ?? null;
            if ($eventId && \Illuminate\Support\Facades\Cache::has('stripe_event_'.$eventId)) {
                return response()->json(['status' => 'duplicate']);
            }
            if ($eventId) {
                \Illuminate\Support\Facades\Cache::put('stripe_event_'.$eventId, true, 3600);
            }

            switch ($event->type) {
                case 'invoice.paid':
                    $invoice = $event->data->object;
                    $stripeSubscriptionId = $invoice->subscription ?? null;
                    if ($stripeSubscriptionId) {
                        $this->syncSubscription($stripeSubscriptionId, $invoice->id);
                    }
                    Log::info('Stripe invoice.paid processed', ['id' => $event->id]);
                    break;
                case 'invoice.payment_failed':
                    $invoice = $event->data->object;
                    $stripeSubscriptionId = $invoice->subscription ?? null;
                    if ($stripeSubscriptionId) {
                        $this->markSubscriptionPaymentFailed($stripeSubscriptionId, $invoice->id);
                    }
                    Log::info('Stripe invoice.payment_failed processed', ['id' => $event->id]);
                    break;
                case 'customer.subscription.updated':
                case 'customer.subscription.created':
                    $stripeSub = $event->data->object;
                    $this->syncSubscription($stripeSub->id, $stripeSub->latest_invoice ?? null);
                    Log::info('Stripe subscription sync processed', ['id' => $event->id]);
                    break;
                case 'charge.dispute.created':
                case 'transfer.failed':
                case 'payout.paid':
                case 'payout.failed':
                    // TODO: implementar reconciliação de pagamentos/saques
                    Log::info('Stripe webhook (payments/payouts)', [
                        'type' => $event->type,
                        'id' => $event->id,
                    ]);
                    break;
                default:
                    Log::debug('Unhandled Stripe event type', [ 'type' => $event->type ]);
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
            // Fetch subscription from Stripe for authoritative data
            \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
            $stripeSub = \Stripe\Subscription::retrieve($stripeSubscriptionId);

            // Find local subscription
            $localSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if (!$localSub) {
                Log::warning('Local subscription not found for Stripe ID', ['stripe_subscription_id' => $stripeSubscriptionId]);
                return;
            }

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
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Failed to sync subscription from webhook', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function markSubscriptionPaymentFailed(string $stripeSubscriptionId, ?string $latestInvoiceId = null): void
    {
        try {
            $localSub = LocalSubscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
            if (!$localSub) {
                return;
            }

            $localSub->update([
                'status' => LocalSubscription::STATUS_PENDING,
                'stripe_status' => 'past_due',
                'stripe_latest_invoice_id' => $latestInvoiceId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to mark subscription payment failed', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}


