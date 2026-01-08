<?php

declare(strict_types=1);

namespace App\Domain\Payment\Repositories;

use App\Models\Payment\Subscription;
use App\Models\Payment\SubscriptionPlan;

/**
 * SubscriptionRepository handles all data access for Subscription entities.
 */
class SubscriptionRepository
{
    public function findPlanById(int $id): ?SubscriptionPlan
    {
        return SubscriptionPlan::find($id);
    }

    public function findPlanByStripePriceId(string $priceId): ?SubscriptionPlan
    {
        return SubscriptionPlan::where('stripe_price_id', $priceId)->first();
    }

    public function findByStripeId(string $stripeSubscriptionId): ?Subscription
    {
        return Subscription::where('stripe_subscription_id', $stripeSubscriptionId)->first();
    }

    public function create(array $data): Subscription
    {
        return Subscription::create($data);
    }
}
