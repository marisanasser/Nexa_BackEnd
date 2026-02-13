<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Models\Common\Notification;
use App\Models\Contract\Offer;
use App\Models\User\User;
use Exception;
use Illuminate\Support\Facades\Log;

class UserNotificationService
{
    public static function notifyUserOfStudentVerificationApproval(User $user, array $approvalData = []): void
    {
        try {
            $notification = Notification::createSystemActivity($user->id, array_merge($approvalData, [
                'activity_type' => 'student_verification_approved',
                'approved_at' => now()->toISOString(),
                'duration_months' => $approvalData['duration_months'] ?? 12,
                'expires_at' => $approvalData['expires_at'] ?? null,
            ]));

            NotificationService::sendSocketNotification($user->id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of student verification approval', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfStudentVerificationRejection(User $user, array $rejectionData = []): void
    {
        try {
            $notification = Notification::createSystemActivity($user->id, array_merge($rejectionData, [
                'activity_type' => 'student_verification_rejected',
                'rejected_at' => $rejectionData['rejected_at'] ?? now()->toISOString(),
                'rejection_reason' => $rejectionData['rejection_reason'] ?? null,
            ]));

            NotificationService::sendSocketNotification($user->id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of student verification rejection', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfOfferCancelled(Offer $offer): void
    {
        try {
            $notification = Notification::createSystemActivity($offer->user_id, [
                'activity_type' => 'offer_cancelled',
                'cancelled_at' => now()->toISOString(),
                'offer_id' => $offer->id,
            ]);

            NotificationService::sendSocketNotification($offer->user_id, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of offer cancellation', [
                'user_id' => $offer->user_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
