<?php
// Define your broadcast channels here 

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    // Verify user has access to this chat
    return $user->chats()->where('chats.id', $chatId)->exists();
});