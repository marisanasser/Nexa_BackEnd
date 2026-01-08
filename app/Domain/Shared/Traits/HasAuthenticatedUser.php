<?php

declare(strict_types=1);

namespace App\Domain\Shared\Traits;

use App\Models\User\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

/**
 * Trait HasAuthenticatedUser.
 *
 * Provides a standardized way to retrieve the authenticated user
 * across all controllers. Eliminates code duplication.
 */
trait HasAuthenticatedUser
{
    /**
     * Get the currently authenticated user.
     *
     * @throws AuthenticationException
     */
    protected function getAuthenticatedUser(): User
    {
        $user = Auth::user();

        if (!$user instanceof User) {
            throw new AuthenticationException('User not authenticated');
        }

        return $user;
    }

    /**
     * Get the authenticated user or null if not authenticated.
     */
    protected function getAuthenticatedUserOrNull(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Check if there's an authenticated user.
     */
    protected function hasAuthenticatedUser(): bool
    {
        return Auth::user() instanceof User;
    }

    /**
     * Get the authenticated user's ID.
     *
     * @throws AuthenticationException
     */
    protected function getAuthenticatedUserId(): int
    {
        return $this->getAuthenticatedUser()->id;
    }

    /**
     * Check if the authenticated user is a brand.
     */
    protected function isAuthenticatedUserBrand(): bool
    {
        return 'brand' === $this->getAuthenticatedUser()->role;
    }

    /**
     * Check if the authenticated user is a creator.
     */
    protected function isAuthenticatedUserCreator(): bool
    {
        return 'creator' === $this->getAuthenticatedUser()->role;
    }

    /**
     * Check if the authenticated user is an admin.
     */
    protected function isAuthenticatedUserAdmin(): bool
    {
        return true === $this->getAuthenticatedUser()->is_admin;
    }
}
