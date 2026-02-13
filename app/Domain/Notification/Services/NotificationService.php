<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Events\User\NotificationSent;
use App\Models\Common\Notification;
use Exception;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public static function sendSocketNotification(int $userId, Notification $notification): void
    {
        try {
            // Dispatch the event for Reverb/Broadcasting
            event(new NotificationSent($notification));

            Log::info('Notification broadcast event dispatched', [
                'user_id' => $userId,
                'notification_id' => $notification->id,
            ]);
        } catch (Exception $e) {
            Log::error('Failed to dispatch notification broadcast event', [
                'user_id' => $userId,
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public static function getUnreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count()
        ;
    }

    public static function markAsRead(int $notificationId, int $userId): bool
    {
        $notification = Notification::where('id', $notificationId)
            ->where('user_id', $userId)
            ->first()
        ;

        if ($notification) {
            return $notification->markAsRead();
        }

        return false;
    }

    public static function markAllAsRead(int $userId): int
    {
        return (int) Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ])
        ;
    }
}
