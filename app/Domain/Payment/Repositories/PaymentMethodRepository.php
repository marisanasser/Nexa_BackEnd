<?php

declare(strict_types=1);

namespace App\Domain\Payment\Repositories;

use App\Models\Payment\BrandPaymentMethod;
use App\Models\User\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * PaymentMethodRepository handles all data access for Payment Method entities.
 */
class PaymentMethodRepository
{
    public function create(array $data): BrandPaymentMethod
    {
        return BrandPaymentMethod::create($data);
    }

    public function findByCardHash(int $userId, string $cardHash): ?BrandPaymentMethod
    {
        return BrandPaymentMethod::where('user_id', $userId)
            ->where('card_hash', $cardHash)
            ->where('is_active', true)
            ->first();
    }

    public function unsetDefaultMethods(int $userId, int $excludeMethodId): void
    {
        BrandPaymentMethod::where('user_id', $userId)
            ->where('id', '!=', $excludeMethodId)
            ->update(['is_default' => false]);
    }

    public function setAsDefault(BrandPaymentMethod $paymentMethod): void
    {
        $paymentMethod->update(['is_default' => true]);
    }

    public function updateUserStripeId(User $user, string $stripeCustomerId): void
    {
        $user->update(['stripe_customer_id' => $stripeCustomerId]);
    }

    public function updateUserDefaultMethod(User $user, ?string $stripePaymentMethodId): void
    {
        $user->update(['stripe_payment_method_id' => $stripePaymentMethodId]);
    }

    public function findByStripeId(int $userId, string $stripePaymentMethodId): ?BrandPaymentMethod
    {
        return BrandPaymentMethod::where('user_id', $userId)
            ->where('stripe_payment_method_id', $stripePaymentMethodId)
            ->first();
    }

    /**
     * @return Collection<int, BrandPaymentMethod>
     */
    public function getAllForUser(int $userId): Collection
    {
        return BrandPaymentMethod::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function findById(int $userId, int $id): ?BrandPaymentMethod
    {
        return BrandPaymentMethod::where('user_id', $userId)
            ->where('id', $id)
            ->first();
    }

    public function countActiveForUser(int $userId): int
    {
        return BrandPaymentMethod::where('user_id', $userId)
            ->where('is_active', true)
            ->count();
    }

    public function getFirstActive(int $userId): ?BrandPaymentMethod
    {
        return BrandPaymentMethod::where('user_id', $userId)
            ->where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public function deactivate(BrandPaymentMethod $paymentMethod): void
    {
        $paymentMethod->update(['is_active' => false]);
    }

    public function findUserById(int $userId): ?User
    {
        return User::find($userId);
    }

    public function findUserByStripeCustomerId(string $stripeCustomerId): ?User
    {
        return User::where('stripe_customer_id', $stripeCustomerId)->first();
    }
}
