<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Mail\ProfileApproved;
use App\Mail\StudentVerificationApproved;
use App\Mail\StudentVerificationRejected;
use App\Models\Common\Notification;
use App\Models\Contract\Offer;
use App\Models\User\User;
use DateTimeInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class UserNotificationService
{
    public static function notifyUserOfProfileApproval(User $user, array $approvalData = []): void
    {
        try {
            $notificationData = array_merge($approvalData, [
                'approved_at' => now()->toISOString(),
                'role' => $approvalData['role'] ?? $user->role,
            ]);

            $notification = Notification::createProfileApproved($user->id, $notificationData);

            NotificationService::sendSocketNotification($user->id, $notification);

            try {
                Mail::to($user->email)->send(new ProfileApproved($user, $notificationData));
            } catch (Exception $emailError) {
                Log::error('Failed to send profile approval email', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'error' => $emailError->getMessage(),
                ]);
            }
        } catch (Exception $e) {
            Log::error('Failed to notify user of profile approval', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfStudentVerificationApproval(User $user, array $approvalData = []): void
    {
        try {
            $expiresAt = $approvalData['expires_at'] ?? null;
            $notificationData = array_merge($approvalData, [
                'approved_at' => now()->toISOString(),
                'duration_months' => $approvalData['duration_months'] ?? 12,
                'expires_at' => $expiresAt instanceof DateTimeInterface
                    ? $expiresAt->format(DATE_ATOM)
                    : (is_string($expiresAt) && '' !== trim($expiresAt) ? $expiresAt : null),
            ]);

            $notification = Notification::createStudentVerificationApproved($user->id, $notificationData);

            NotificationService::sendSocketNotification($user->id, $notification);

            try {
                Mail::to($user->email)->send(new StudentVerificationApproved($user, $notificationData));
            } catch (Exception $emailError) {
                Log::error('Failed to send student verification approval email', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'error' => $emailError->getMessage(),
                ]);
            }
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
            $notificationData = array_merge($rejectionData, [
                'rejected_at' => $rejectionData['rejected_at'] ?? now()->toISOString(),
                'rejection_reason' => $rejectionData['rejection_reason'] ?? null,
            ]);

            $notification = Notification::createStudentVerificationRejected($user->id, $notificationData);

            NotificationService::sendSocketNotification($user->id, $notification);

            try {
                Mail::to($user->email)->send(new StudentVerificationRejected($user, $notificationData));
            } catch (Exception $emailError) {
                Log::error('Failed to send student verification rejection email', [
                    'user_id' => $user->id,
                    'user_email' => $user->email,
                    'error' => $emailError->getMessage(),
                ]);
            }
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
