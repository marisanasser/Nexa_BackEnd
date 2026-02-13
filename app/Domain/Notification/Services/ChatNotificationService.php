<?php

declare(strict_types=1);

namespace App\Domain\Notification\Services;

use App\Models\Chat\DirectMessage;
use App\Models\Chat\Message;
use App\Models\Common\Notification;
use Exception;
use Illuminate\Support\Facades\Log;

class ChatNotificationService
{
    public static function notifyUserOfNewMessage(Message $message): void
    {
        try {
            $chatRoom = $message->chatRoom;
            $sender = $message->sender;

            $recipientId = $chatRoom->brand_id === $sender->id ? $chatRoom->creator_id : $chatRoom->brand_id;

            $messagePreview = strlen($message->message) > 50
                ? substr($message->message, 0, 50) . '...'
                : $message->message;

            $notification = Notification::createNewMessage(
                $recipientId,
                $sender->id,
                $sender->name,
                $messagePreview,
                'campaign',
                $chatRoom->room_id
            );

            NotificationService::sendSocketNotification($recipientId, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of new message', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyUserOfNewDirectMessage(DirectMessage $message): void
    {
        try {
            $chatRoom = $message->directChatRoom;
            $sender = $message->sender;

            $recipientId = $chatRoom->brand_id === $sender->id ? $chatRoom->creator_id : $chatRoom->brand_id;

            $messagePreview = strlen($message->message) > 50
                ? substr($message->message, 0, 50) . '...'
                : $message->message;

            $notification = Notification::createNewMessage(
                $recipientId,
                $sender->id,
                $sender->name,
                $messagePreview,
                'direct',
                $chatRoom->room_id
            );

            NotificationService::sendSocketNotification($recipientId, $notification);
        } catch (Exception $e) {
            Log::error('Failed to notify user of new direct message', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
