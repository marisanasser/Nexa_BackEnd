<?php

declare(strict_types=1);

use App\Models\Chat\ChatRoom;
use App\Models\Chat\DirectChatRoom;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', fn ($user, $id) => (int) $user->id === (int) $id);

Broadcast::channel('chat.{roomId}', function ($user, $roomId) {
    // Verificar se é uma sala de chat de campanha
    $chatRoom = ChatRoom::where('room_id', $roomId)->first();
    if ($chatRoom) {
        return $user->id === $chatRoom->brand_id || $user->id === $chatRoom->creator_id;
    }

    // Verificar se é uma sala de chat direto (para futuro uso ou consistência)
    $directChatRoom = DirectChatRoom::where('room_id', $roomId)->first();
    if ($directChatRoom) {
        return $user->id === $directChatRoom->brand_id || $user->id === $directChatRoom->creator_id;
    }

    return false;
});
