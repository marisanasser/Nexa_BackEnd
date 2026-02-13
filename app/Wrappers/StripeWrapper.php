<?php

declare(strict_types=1);

namespace App\Wrappers;

use Stripe\Account;
use Stripe\Checkout\Session;
use Stripe\Collection;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Refund;
use Stripe\SetupIntent;
use Stripe\Stripe;
use Stripe\Subscription;

class StripeWrapper
{
    public function __construct()
    {
        $apiKey = config('services.stripe.secret');
        if ($apiKey) {
            Stripe::setApiKey($apiKey);
        }
    }

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

    public function updateCustomer(string $id, array $params): Customer
    {
        return Customer::update($id, $params);
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

    public function cancelSubscription(string $id, array $params = []): Subscription
    {
        return Subscription::retrieve($id)->cancel($params);
    }

    public function retrievePaymentMethod(string $id): PaymentMethod
    {
        return PaymentMethod::retrieve($id);
    }

    public function attachPaymentMethodToCustomer(string $paymentMethodId, string $customerId): PaymentMethod
    {
        $paymentMethod = PaymentMethod::retrieve($paymentMethodId);

        return $paymentMethod->attach(['customer' => $customerId]);
    }

    public function detachPaymentMethod(string $paymentMethodId): PaymentMethod
    {
        $paymentMethod = PaymentMethod::retrieve($paymentMethodId);

        return $paymentMethod->detach();
    }

    public function createPaymentIntent(array $params): PaymentIntent
    {
        return PaymentIntent::create($params);
    }

    public function retrievePaymentIntent(string $id, array $params = []): PaymentIntent
    {
        return PaymentIntent::retrieve($id, $params);
    }

    public function attachPaymentMethod(string $paymentMethodId, string $customerId): PaymentMethod
    {
        $paymentMethod = PaymentMethod::retrieve($paymentMethodId);

        return $paymentMethod->attach(['customer' => $customerId]);
    }

    public function retrieveSetupIntent(string $id, array $params = []): SetupIntent
    {
        return SetupIntent::retrieve($id, $params);
    }

    public function createRefund(array $params): Refund
    {
        return Refund::create($params);
    }

    public function allExternalAccounts(string $id, array $params = []): Collection
    {
        return Account::allExternalAccounts($id, $params);
    }

    public function retrieveAccount(string $id): Account
    {
        return Account::retrieve($id);
    }
}
