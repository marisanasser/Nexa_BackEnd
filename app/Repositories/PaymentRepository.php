<?php

namespace App\Repositories;

use App\Models\BrandPaymentMethod;
use App\Models\User;

class PaymentRepository
{
    public function createBrandPaymentMethod(array $data): BrandPaymentMethod
    {
        return BrandPaymentMethod::create($data);
    }

    public function unsetDefaultPaymentMethods(int $userId, int $excludeMethodId): void
    {
        BrandPaymentMethod::where('user_id', $userId)
            ->where('id', '!=', $excludeMethodId)
            ->update(['is_default' => false]);
    }

    public function setPaymentMethodAsDefault(BrandPaymentMethod $paymentMethod): void
    {
        $paymentMethod->update(['is_default' => true]);
    }

    public function updateUserStripeId(User $user, string $stripeCustomerId): void
    {
        $user->update(['stripe_customer_id' => $stripeCustomerId]);
    }
    
    public function updateUserDefaultPaymentMethod(User $user, ?string $stripePaymentMethodId): void
    {
        $user->update(['stripe_payment_method_id' => $stripePaymentMethodId]);
    }

    public function findBrandPaymentMethodByStripeId(int $userId, string $stripePaymentMethodId): ?BrandPaymentMethod
    {
        return BrandPaymentMethod::where('user_id', $userId)
            ->where('stripe_payment_method_id', $stripePaymentMethodId)
            ->first();
    }

    public function getBrandPaymentMethods(int $userId)
    {
        return BrandPaymentMethod::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function findBrandPaymentMethod(int $userId, int $id): ?BrandPaymentMethod
    {
        return BrandPaymentMethod::where('user_id', $userId)
            ->where('id', $id)
            ->first();
    }

    public function countActiveBrandPaymentMethods(int $userId): int
    {
        return BrandPaymentMethod::where('user_id', $userId)
            ->where('is_active', true)
            ->count();
    }

    public function getFirstActivePaymentMethod(int $userId): ?BrandPaymentMethod
    {
        return BrandPaymentMethod::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();
    }
}
