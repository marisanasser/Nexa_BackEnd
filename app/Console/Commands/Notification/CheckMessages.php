<?php

declare(strict_types=1);

namespace App\Console\Commands\Notification;

use App\Models\Chat\ChatRoom;
use App\Models\Chat\Message;
use Illuminate\Console\Command;
use Log;

class CheckMessages extends Command
{
    protected $signature = 'messages:check {--room-id= : Check specific room ID}';

    protected $description = 'Check the current state of messages and help debug chat history issues';

    public function handle()
    {
        $this->info('Checking message statistics...');

        $totalMessages = Message::count();
        $offerMessages = Message::where('message_type', 'offer')->count();
        $textMessages = Message::where('message_type', 'text')->count();
        $fileMessages = Message::where('message_type', 'file')->count();
        $imageMessages = Message::where('message_type', 'image')->count();

        $this->info("Total messages: {$totalMessages}");
        $this->info("Offer messages: {$offerMessages}");
        $this->info("Text messages: {$textMessages}");
        $this->info("File messages: {$fileMessages}");
        $this->info("Image messages: {$imageMessages}");

        $roomsWithMessages = ChatRoom::whereHas('messages')->count();
        $totalRooms = ChatRoom::count();

        $this->info("Rooms with messages: {$roomsWithMessages} / {$totalRooms}");

        if ($roomId = $this->option('room-id')) {
            $this->info("Checking room: {$roomId}");

            $room = ChatRoom::where('room_id', $roomId)->first();
            if (!$room) {
                $this->error("Room not found: {$roomId}");

                return 1;
            }

            $roomMessages = $room->messages()->count();
            $this->info("Messages in room {$roomId}: {$roomMessages}");

            $recentMessages = $room->messages()
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get(['id', 'message_type', 'created_at', 'sender_id'])
            ;

            $this->info('Recent messages:');
            foreach ($recentMessages as $message) {
                $this->line("  - ID: {$message->id}, Type: {$message->message_type}, Created: {$message->created_at}");
            }
        }

        Log::info('Message check completed', [
            'total_messages' => $totalMessages,
            'offer_messages' => $offerMessages,
            'text_messages' => $textMessages,
            'file_messages' => $fileMessages,
            'image_messages' => $imageMessages,
            'rooms_with_messages' => $roomsWithMessages,
            'total_rooms' => $totalRooms,
        ]);

        return 0;
    }
}
