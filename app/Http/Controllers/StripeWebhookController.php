<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

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
                case 'customer.subscription.updated':
                case 'customer.subscription.created':
                case 'invoice.payment_failed':
                    // TODO: implementar sincronização de assinatura
                    Log::info('Stripe webhook received', [
                        'type' => $event->type,
                        'id' => $event->id,
                    ]);
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
}


