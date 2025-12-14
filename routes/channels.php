<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('chat.{roomId}', function ($user, $roomId) {
    // Verificar se é uma sala de chat de campanha
    $chatRoom = \App\Models\ChatRoom::where('room_id', $roomId)->first();
    if ($chatRoom) {
        return $user->id === $chatRoom->brand_id || $user->id === $chatRoom->creator_id;
    }

    // Verificar se é uma sala de chat direto (para futuro uso ou consistência)
    $directChatRoom = \App\Models\DirectChatRoom::where('room_id', $roomId)->first();
    if ($directChatRoom) {
        return $user->id === $directChatRoom->brand_id || $user->id === $directChatRoom->creator_id;
    }

    return false;
});
