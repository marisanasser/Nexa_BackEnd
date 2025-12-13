<?php

namespace App\Wrappers;

use Stripe\Customer;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Stripe\Subscription;
use Stripe\PaymentMethod;
use Stripe\PaymentIntent;

class StripeWrapper
{
    public function setApiKey(string $apiKey): void
    {
        Stripe::setApiKey($apiKey);
    }

    public function retrieveCustomer(string $id): Customer
    {
        return Customer::retrieve($id);
    }

    public function createCustomer(array $params): Customer
    {
        return Customer::create($params);
    }

    public function createCheckoutSession(array $params): Session
    {
        return Session::create($params);
    }

    public function retrieveCheckoutSession(string $id, array $params = []): Session
    {
        return Session::retrieve($id, $params);
    }

    public function retrieveSubscription(string $id, array $params = []): Subscription
    {
        return Subscription::retrieve(array_merge(['id' => $id], $params));
    }

    public function updateSubscription(string $id, array $params): Subscription
    {
        return Subscription::update($id, $params);
    }

    public function retrievePaymentMethod(string $id): PaymentMethod
    {
        return PaymentMethod::retrieve($id);
    }

    public function retrievePaymentIntent(string $id, array $params = []): PaymentIntent
    {
        return PaymentIntent::retrieve($id, $params);
    }
}
