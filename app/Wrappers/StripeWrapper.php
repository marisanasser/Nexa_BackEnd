<?php

namespace App\Wrappers;

use Stripe\Customer;
use Stripe\Checkout\Session;
use Stripe\Stripe;

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
}
