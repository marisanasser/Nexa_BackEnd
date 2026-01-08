<?php

declare(strict_types=1);

namespace App\Domain\User\Services;

use App\Models\User\User;
use Exception;
use Illuminate\Support\Facades\Hash;
use Log;

/**
 * UserProfileService handles user profile operations.
 *
 * Responsibilities:
 * - Profile updates
 * - Avatar management
 * - Password changes
 * - Account settings
 */
class UserProfileService
{
    /**
     * Update user profile.
     */
    public function updateProfile(User $user, array $data): User
    {
        $allowedFields = [
            'name', 'bio', 'phone', 'location', 'website',
            'instagram_handle', 'tiktok_handle', 'youtube_handle',
            'twitter_handle', 'facebook_handle',
            'date_of_birth', 'gender', 'state', 'city',
        ];

        $updateData = array_intersect_key($data, array_flip($allowedFields));

        if (empty($updateData)) {
            return $user;
        }

        $user->update($updateData);

        Log::info('User profile updated', [
            'user_id' => $user->id,
            'fields' => array_keys($updateData),
        ]);

        return $user->fresh();
    }

    /**
     * Update user avatar.
     */
    public function updateAvatar(User $user, string $avatarUrl, ?string $oldAvatarUrl = null): User
    {
        $user->update(['avatar' => $avatarUrl]);

        Log::info('User avatar updated', [
            'user_id' => $user->id,
        ]);

        return $user->fresh();
    }

    /**
     * Change user password.
     */
    public function changePassword(User $user, string $currentPassword, string $newPassword): bool
    {
        if (!Hash::check($currentPassword, $user->password)) {
            throw new Exception('Current password is incorrect');
        }

        $user->update([
            'password' => Hash::make($newPassword),
            'password_changed_at' => now(),
        ]);

        Log::info('User password changed', [
            'user_id' => $user->id,
        ]);

        return true;
    }

    /**
     * Update notification preferences.
     */
    public function updateNotificationPreferences(User $user, array $preferences): User
    {
        $allowedPreferences = [
            'email_notifications',
            'push_notifications',
            'sms_notifications',
            'marketing_emails',
            'campaign_alerts',
            'payment_alerts',
            'message_alerts',
        ];

        $currentPrefs = $user->notification_preferences ?? [];
        $newPrefs = array_merge(
            $currentPrefs,
            array_intersect_key($preferences, array_flip($allowedPreferences))
        );

        $user->update(['notification_preferences' => $newPrefs]);

        Log::info('User notification preferences updated', [
            'user_id' => $user->id,
        ]);

        return $user->fresh();
    }

    /**
     * Deactivate user account.
     */
    public function deactivateAccount(User $user, ?string $reason = null): User
    {
        $user->update([
            'is_active' => false,
            'deactivated_at' => now(),
            'deactivation_reason' => $reason,
        ]);

        Log::info('User account deactivated', [
            'user_id' => $user->id,
            'reason' => $reason,
        ]);

        return $user->fresh();
    }

    /**
     * Reactivate user account.
     */
    public function reactivateAccount(User $user): User
    {
        $user->update([
            'is_active' => true,
            'deactivated_at' => null,
            'deactivation_reason' => null,
        ]);

        Log::info('User account reactivated', [
            'user_id' => $user->id,
        ]);

        return $user->fresh();
    }

    /**
     * Update user email.
     */
    public function updateEmail(User $user, string $newEmail, string $password): User
    {
        if (!Hash::check($password, $user->password)) {
            throw new Exception('Password is incorrect');
        }

        $oldEmail = $user->email;

        $user->update([
            'email' => $newEmail,
            'email_verified_at' => null, // Require reverification
        ]);

        // Send verification email
        $user->sendEmailVerificationNotification();

        Log::info('User email updated', [
            'user_id' => $user->id,
            'old_email' => $oldEmail,
            'new_email' => $newEmail,
        ]);

        return $user->fresh();
    }

    /**
     * Get user statistics.
     */
    public function getUserStatistics(User $user): array
    {
        if ($user->isCreator()) {
            return $this->getCreatorStatistics($user);
        }

        if ($user->isBrand()) {
            return $this->getBrandStatistics($user);
        }

        return [
            'member_since' => $user->created_at,
            'last_login' => $user->last_login_at,
        ];
    }

    /**
     * Get creator-specific statistics.
     */
    private function getCreatorStatistics(User $user): array
    {
        return [
            'member_since' => $user->created_at,
            'last_login' => $user->last_login_at,
            'total_contracts' => $user->creatorContracts()->count(),
            'completed_contracts' => $user->creatorContracts()->where('status', 'completed')->count(),
            'pending_offers' => $user->receivedOffers()->where('status', 'pending')->count(),
            'total_earned' => $user->creatorBalance?->total_earned ?? 0,
            'average_rating' => $user->receivedReviews()->avg('rating') ?? 0,
            'review_count' => $user->receivedReviews()->count(),
        ];
    }

    /**
     * Get brand-specific statistics.
     */
    private function getBrandStatistics(User $user): array
    {
        return [
            'member_since' => $user->created_at,
            'last_login' => $user->last_login_at,
            'total_campaigns' => $user->campaigns()->count(),
            'active_campaigns' => $user->campaigns()->where('status', 'active')->count(),
            'total_contracts' => $user->brandContracts()->count(),
            'completed_contracts' => $user->brandContracts()->where('status', 'completed')->count(),
            'total_spent' => $user->brandBalance?->total_spent ?? 0,
            'average_rating' => $user->receivedReviews()->avg('rating') ?? 0,
            'review_count' => $user->receivedReviews()->count(),
        ];
    }
}
