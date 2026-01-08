<?php

declare(strict_types=1);

namespace App\Domain\Payment\Repositories;

use App\Models\Payment\WebhookEvent;

/**
 * WebhookEventRepository handles data access for Stripe Webhook Events.
 */
class WebhookEventRepository
{
    public function findByStripeEventId(string $stripeEventId): ?WebhookEvent
    {
        return WebhookEvent::where('stripe_event_id', $stripeEventId)->first();
    }

    public function create(array $data): WebhookEvent
    {
        return WebhookEvent::create($data);
    }

    public function updateStatus(WebhookEvent $event, string $status, ?string $errorMessage = null): void
    {
        $data = ['status' => $status];
        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }
        $event->update($data);
    }

    public function updateStatusByStripeEventId(string $stripeEventId, string $status, ?string $errorMessage = null): void
    {
        $data = ['status' => $status];
        if ($errorMessage) {
            $data['error_message'] = $errorMessage;
        }
        WebhookEvent::where('stripe_event_id', $stripeEventId)->update($data);
    }
}
